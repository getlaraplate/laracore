<?php

declare(strict_types=1);

namespace Modules\Core\Services\Authorization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Services\AclResolverService;
use Modules\Core\Support\AclScopeCheck;
use Modules\Core\Support\FilterPlaceholders;
use Modules\Core\Support\PermissionName;
use Modules\Core\Support\RelationGuard;
use Modules\Core\Support\ScopeGuard;

/**
 * Authorization Service - handles permission checks and ACL filter injection.
 *
 * This service is the single point of entry for all authorization logic:
 * 1. Permission checks (can user perform operation on entity?)
 * 2. ACL resolution (what rows can user access?)
 * 3. ACL injection into requests (modify request filters with ACL constraints)
 *
 * Usage in CrudService:
 * ```php
 * $auth = app(AuthorizationService::class);
 * $permission_name = $auth->ensurePermission($request, 'orders', 'select');
 * $auth->injectAclFilters($requestData, $permission_name);
 * // Now requestData->filters includes ACL constraints
 * ```
 */
final class AuthorizationService
{
    public function __construct(
        private readonly AclResolverService $acl_resolver,
    ) {}

    /**
     * Check if user has permission for the requested operation.
     *
     * @param  Request  $request  The HTTP request (used to get user)
     * @param  string  $entity  The entity/table name
     * @param  string|null  $operation  The operation (select, insert, update, delete, etc.)
     * @param  string|null  $connection  The database connection name
     * @return bool True if user has permission
     */
    public function checkPermission(
        Request $request,
        string $entity,
        ?string $operation = null,
        ?string $connection = null,
    ): bool {
        return $this->passesPermission(
            $request,
            $this->buildPermissionName($entity, $operation, $connection),
        );
    }

    /**
     * The operations the current user may perform on the given entity/table.
     *
     * Drives capability-based UI: the SPA reads these instead of reconstructing
     * the `{connection}.{table}.{operation}` permission names. `impersonate` is a
     * global user capability, not an entity operation, so it is excluded.
     *
     * @return list<string> Allowed ActionEnum values, e.g. ['select', 'update', 'approve'].
     */
    public function allowedOperations(Request $request, string $entity, ?string $connection = null): array
    {
        $operations = [];

        foreach (ActionEnum::cases() as $action) {
            if ($action === ActionEnum::Impersonate) {
                continue;
            }

            if ($this->checkPermission($request, $entity, $action->value, $connection)) {
                $operations[] = $action->value;
            }
        }

        return $operations;
    }

    /**
     * Ensure user has permission or throw exception.
     *
     *
     * @throws AuthorizationException If user doesn't have permission
     *
     * @return string The permission name that was checked (for ACL resolution)
     */
    public function ensurePermission(
        Request $request,
        string $entity,
        ?string $operation = null,
        ?string $connection = null,
    ): string {
        $permission_name = $this->buildPermissionName($entity, $operation, $connection);

        throw_unless(
            $this->checkPermission($request, $entity, $operation, $connection),
            AuthorizationException::class,
            'User not allowed to access this resource',
        );

        return $permission_name;
    }

    /**
     * Ensure the user holds the permission keyed on a model's table, or throw.
     *
     * Derives the name from {@see PermissionName::forClass()} — the same table
     * (and connection) source of truth the permission seeder uses — so a
     * module-prefixed table authorizes against its real permission rather than
     * a caller-supplied short entity that no role is ever granted.
     *
     * @param  class-string<Model>  $model_class
     *
     * @throws AuthorizationException If user doesn't have permission
     *
     * @return string The permission name that was checked (for ACL resolution)
     */
    public function ensurePermissionForClass(
        Request $request,
        string $model_class,
        string $operation,
    ): string {
        $permission_name = PermissionName::forClass($model_class, $operation);

        throw_unless(
            $this->passesPermission($request, $permission_name),
            AuthorizationException::class,
            'User not allowed to access this resource',
        );

        return $permission_name;
    }

    /**
     * Get ACL filters for the current user on a permission.
     *
     * @param  string  $permission_name  The full permission name (e.g., 'default.orders.select')
     * @return FiltersGroup|null The ACL filters, or null if unrestricted
     */
    public function getAclFilters(string $permission_name): ?FiltersGroup
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        $permission = $this->resolvePermission($permission_name);

        return $this->acl_resolver->getCombinedFilters($user, $permission);
    }

    /**
     * Inject ACL filters into the request data.
     *
     * This method modifies the request's filters to include ACL constraints.
     * The logic wraps existing user filters with ACL filters using AND:
     *
     * Before: filters = { user_filters }
     * After:  filters = { ACL_filters AND user_filters }
     *
     * This ensures users cannot bypass ACL restrictions with their own filters.
     *
     * @param  ListRequestData  $request_data  The request data to modify
     * @param  string  $permission_name  The permission name for ACL lookup
     */
    public function injectAclFilters(ListRequestData $request_data, string $permission_name): void
    {
        // The list path hands the filters to the CRUD query builder, which knows nothing of the ACL
        // placeholders: they are resolved here, as applyAclFiltersToQuery() does for the same filters.
        $acl_filters = $this->resolvedAclFilters($permission_name, $request_data->model ?? null);

        if ($acl_filters instanceof FiltersGroup) {
            $request_data->mergeFilters($acl_filters);
        }
    }

    /**
     * The ACL filters of the acting user on a permission, ready for the CRUD query builder
     * ({@see \Modules\Core\Services\Crud\QueryBuilder::applyFilters()}), which knows nothing of the ACL
     * placeholders: every `@now`, `@today` and `@user.<attribute>` is replaced by its live value, in column filter
     * values and scope parameters alike. Null when no ACL narrows the permission.
     *
     * Every scope node of the contributing ACLs is first checked against the model (and the related models of
     * its relation filters): an ACL whose scope no longer applies denies the read.
     *
     * @param  Model|null  $model  The model the permission is about; null reads it from the permission name
     *
     * @throws AuthorizationException when a scope node of an ACL no longer applies
     */
    public function resolvedAclFilters(string $permission_name, ?Model $model = null): ?FiltersGroup
    {
        $acl_filters = $this->checkedAclFilters($permission_name, $model);

        return $acl_filters instanceof FiltersGroup ? $this->resolvePlaceholders($acl_filters) : null;
    }

    /**
     * Check if user has unrestricted access to a permission.
     *
     * @param  string  $permission_name  The full permission name
     * @return bool True if user has no ACL restrictions
     */
    public function hasUnrestrictedAccess(string $permission_name): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $permission = $this->resolvePermission($permission_name);

        return $this->acl_resolver->hasUnrestrictedAccess($user, $permission);
    }

    /**
     * Build the full permission name from components.
     */
    public function buildPermissionName(
        string $entity,
        ?string $operation = null,
        ?string $connection = null,
    ): string {
        return PermissionName::build($connection ?? 'default', $entity, (string) $operation);
    }

    /**
     * Whether the user holds at least one permission on the module's entities.
     *
     * The `permissions` table carries a generated `module_name` column (the table's
     * prefix before the first underscore, e.g. `sao_tickets` -> `sao`), so module
     * access is a single indexed lookup rather than parsing permission names in
     * PHP. Checks direct permissions then permissions granted via the user's
     * roles. Super admins always pass. Used to gate scoped SPA logins.
     */
    public function userHasModuleAccess(User $user, string $scope): bool
    {
        $scope = mb_strtolower(mb_trim($scope));

        if ($scope === '' || $user->isSuperAdmin()) {
            return true;
        }

        if ($user->permissions()->where('module_name', $scope)->exists()) {
            return true;
        }

        $role_ids = $user->roles()->pluck('id');

        if ($role_ids->isEmpty()) {
            return false;
        }

        return Permission::query()
            ->where('module_name', $scope)
            ->whereHas('roles', static fn (Builder $query): Builder => $query->whereKey($role_ids->all()))
            ->exists();
    }

    /**
     * Every module the user holds at least one permission on: the set of slugs `userHasModuleAccess` would
     * accept, read in two queries (direct permissions, then permissions granted by the user's roles) rather
     * than one lookup per module. A super admin gets every module that has permissions.
     *
     * @return Collection<int, string>
     */
    public function accessibleModules(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            $modules = Permission::query()->distinct()->pluck('module_name');
        } else {
            $role_ids = $user->roles()->pluck('id');

            $modules = $user->permissions()->distinct()->pluck('module_name')->merge(
                $role_ids->isEmpty()
                    ? []
                    : Permission::query()
                        ->whereHas('roles', static fn (Builder $query): Builder => $query->whereKey($role_ids->all()))
                        ->distinct()
                        ->pluck('module_name'),
            );
        }

        return $modules
            ->map(static fn (mixed $module): string => mb_strtolower((string) $module))
            ->filter(static fn (string $module): bool => $module !== '')
            ->unique()
            ->values();
    }

    /**
     * Apply ACL filters directly to a query builder.
     *
     * Use this for requests that don't have a filters property (e.g., DetailRequestData).
     * For ListRequestData, prefer injectAclFilters() to modify the request.
     *
     * Generic over the model: a caller holding a Builder<Ticket> or any other
     * concrete builder must be able to pass it. Declaring Builder<Model> made
     * every such call an argument.type error, since PHPStan treats the builder
     * as invariant in its model.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  The Eloquent query builder
     * @param  string  $permission_name  The permission name for ACL lookup
     *
     * @throws AuthorizationException when a scope node of an ACL no longer applies
     */
    public function applyAclFiltersToQuery(Builder $query, string $permission_name): void
    {
        $acl_filters = $this->checkedAclFilters($permission_name, $query->getModel());

        if (! $acl_filters instanceof FiltersGroup) {
            return;
        }

        // Apply filters using a closure to wrap them properly
        $query->where(function (Builder $q) use ($acl_filters): void {
            $this->applyFiltersRecursively($q, $acl_filters);
        });
    }

    /**
     * Clear ACL cache for the current user.
     */
    public function clearCacheForCurrentUser(): void
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $this->acl_resolver->clearCacheForUser($user);
        }
    }

    /**
     * Resolve the user from request, falling back to anonymous user if needed.
     *
     * Public for the API middleware, which resolves the anonymous user before the controller runs.
     */
    public function resolveUser(Request $request): ?User
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user;
        }

        return $this->resolveAnonymousUser($request);
    }

    /**
     * The anonymous user (`permission.users.guest`) as the request user, whatever session or token the
     * request carries. Null when the installation has no such account.
     */
    public function resolveAnonymousUser(Request $request): ?User
    {
        $guest_name = config('permission.users.guest');

        if (! is_string($guest_name) || $guest_name === '') {
            return null;
        }

        // Keyed by the configured name: a stale user must not survive a rename of the guest account.
        // Loaded as the configured user class: the roles of the account are stored under the morph type of
        // that class, so a parent class would load the account with no role at all.
        // Labelled with the users table: any write to the guest account, or to which account bears its name, flushes
        // it. The model is cached with no relation loaded, so its roles and permissions are read fresh.
        $user_class = user_class();
        $anonymous = resolve(LabelledCache::class)->remember(
            'anonymous_user.' . $guest_name,
            [CacheLabel::table(new $user_class()->getTable())],
            (int) config('cache.duration.long'),
            function () use ($guest_name, $user_class): ?Model {
                $id = $this->anonymousUserId($guest_name);

                return $id === null ? null : $user_class::query()->whereKey($id)->first();
            },
            static fn (mixed $user): bool => $user instanceof Model,
        );

        if (! $anonymous instanceof User) {
            return null;
        }

        // Set on the request's guard for this request only, never signed in to a session: a session sign-in would
        // let the next request of the same browser through the `auth` group as the shared anonymous account.
        Auth::guard(Auth::getDefaultDriver())->setUser($anonymous);

        $request->setUserResolver(fn (): User => $anonymous);

        return $anonymous;
    }

    /**
     * Whether a user holds a fully-qualified permission name the way every CRUD check asks it: a superadmin always
     * does; anybody else through a direct grant, a role or an ancestor role of the request's guard, and within the
     * abilities of the token that authenticated the request. For the code that asks about the acting user outside
     * a request object (model events and scopes), so that it answers as the CRUD does instead of through the Gate,
     * which reads neither the request's guard nor the token.
     */
    public function userHolds(User $user, string $permission_name): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Not Spatie's `hasPermissionTo`: that one does not see a permission inherited from an
        // ancestor role, so the gate denied what the role model itself reports as granted.
        // The guard is the request's: `web` for the session routes, `api` once the API
        // middleware has switched the default guard.
        return $user->hasPermission($permission_name, Auth::getDefaultDriver())
            && $this->tokenAllows($user, $permission_name);
    }

    /**
     * A node skipped by a walker would drop its condition and widen the rows: refused instead (fail closed).
     */
    private static function unknownNode(mixed $node): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The ACL filters cannot apply a %s filter node.', get_debug_type($node)));
    }

    /**
     * The id of the account configured as the guest. The username is unique, so a match on it is the
     * account; otherwise the name (which is not unique) picks an account that is not a service account
     * first and the oldest one after that, so the answer never depends on the order of the table.
     */
    private function anonymousUserId(string $guest_name): mixed
    {
        $user = new User();

        return User::query()->where($user->qualifyColumn('username'), $guest_name)->value('id')
            ?? User::query()->where('name', $guest_name)->orderBy('is_service_account')->orderBy('id')->value('id');
    }

    /**
     * Superadmin-aware check that a request's user holds a fully-qualified
     * permission name. Shared by the entity- and model-based gates.
     */
    private function passesPermission(Request $request, string $permission_name): bool
    {
        $user = $this->resolveUser($request);

        if (! $user instanceof User) {
            return false;
        }

        return $this->userHolds($user, $permission_name);
    }

    /**
     * A personal access token narrows what the role grants: the permission has to be among its
     * abilities, and the wildcard ability is never accepted. A request with no token (anonymous or
     * a session) is not narrowed.
     */
    private function tokenAllows(User $user, string $permission_name): bool
    {
        /** @var HasAbilities|null $token A session carries a transient token, a bearer request a personal one */
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return true;
        }

        return in_array($permission_name, $token->abilities ?? [], true);
    }

    /**
     * Apply filters recursively to a query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyFiltersRecursively(Builder $query, FiltersGroup $filters): void
    {
        $method = $filters->operator === WhereClause::And ? 'where' : 'orWhere';

        foreach ($filters->filters as $filter) {
            if ($filter instanceof FiltersGroup) {
                $query->{$method}(function (Builder $q) use ($filter): void {
                    $this->applyFiltersRecursively($q, $filter);
                });
            } elseif ($filter instanceof RelationFilter) {
                $this->applyRelationFilter($query, $filter, $method);
            } elseif ($filter instanceof ScopeFilter) {
                ScopeGuard::apply($query, $this->resolveScopeParameters($filter), $method === 'orWhere' ? 'or' : 'and');
            } elseif ($filter instanceof Filter) {
                $this->applySingleFilter($query, $filter, $method);
            } else {
                throw self::unknownNode($filter);
            }
        }
    }

    /**
     * A condition on the related record: `whereHas`, or `whereHasMorph` for a morph relation, with the
     * nested filters applied to the related query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyRelationFilter(Builder $query, RelationFilter $filter, string $method): void
    {
        RelationGuard::assertFilterable($query->getModel(), $filter);

        $or = $method === 'orWhere';
        $constraint = function (Builder $related) use ($filter): void {
            $this->applyFiltersRecursively($related, $filter->filters);
        };

        if ($filter->morph_types !== null) {
            $query->{$or ? 'orWhereHasMorph' : 'whereHasMorph'}($filter->relation, $filter->morph_types, $constraint);

            return;
        }

        $query->{$or ? 'orWhereHas' : 'whereHas'}($filter->relation, $constraint);
    }

    /**
     * A copy of the filters with every dynamic value (`@now`, `@today`, `@user.<attribute>`) replaced by its
     * live value, nested relation filters and scope parameters included.
     *
     * @throws InvalidArgumentException on a node of an unknown kind
     */
    private function resolvePlaceholders(FiltersGroup $filters): FiltersGroup
    {
        $resolved = [];

        foreach ($filters->filters as $node) {
            $resolved[] = match (true) {
                $node instanceof FiltersGroup => $this->resolvePlaceholders($node),
                $node instanceof RelationFilter => new RelationFilter(
                    relation: $node->relation,
                    filters: $this->resolvePlaceholders($node->filters),
                    morph_types: $node->morph_types,
                ),
                $node instanceof ScopeFilter => $this->resolveScopeParameters($node),
                default => $this->resolveFilter($node),
            };
        }

        return new FiltersGroup($resolved, $filters->operator);
    }

    /**
     * A copy of the column filter with its placeholder value replaced by its live value.
     *
     * @throws InvalidArgumentException when the node is not a column filter
     */
    private function resolveFilter(mixed $node): Filter
    {
        if (! $node instanceof Filter) {
            throw self::unknownNode($node);
        }

        return new Filter($node->property, $this->resolveFilterValue($node->value), $node->operator);
    }

    /**
     * A copy of the scope node with each placeholder parameter replaced by its live value.
     */
    private function resolveScopeParameters(ScopeFilter $filter): ScopeFilter
    {
        return AclScopeCheck::resolveParameters($filter);
    }

    /**
     * {@see getAclFilters()}, after checking that every scope node of the contributing ACLs still applies to the
     * model it reaches ({@see AclScopeCheck::assertApplies()}).
     *
     * @param  Model|null  $model  The model the permission is about; null reads it from the permission name
     *
     * @throws AuthorizationException when a scope node of an ACL no longer applies
     */
    private function checkedAclFilters(string $permission_name, ?Model $model): ?FiltersGroup
    {
        $acl_filters = $this->getAclFilters($permission_name);
        $user = Auth::user();

        if (! $acl_filters instanceof FiltersGroup || ! $user instanceof User || ! AclScopeCheck::holdsScopeFilter($acl_filters)) {
            return $acl_filters;
        }

        if (! $model instanceof Model) {
            $model_class = PermissionName::modelClassOf($permission_name);
            $model = $model_class === null ? null : new $model_class();
        }

        $acls = $this->acl_resolver->getEffectiveAcls($user, $this->resolvePermission($permission_name))
            ->filter(static fn (ACL $acl): bool => ! $acl->isUnrestricted() && $acl->hasFilters());

        foreach ($acls as $acl) {
            if ($acl->filters instanceof FiltersGroup) {
                AclScopeCheck::assertApplies($acl, $permission_name, $acl->filters, $model);
            }
        }

        return $acl_filters;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySingleFilter(Builder $query, Filter $filter, string $method): void
    {
        if ($filter->value === null) {
            $null_method = $filter->operator->value === '=' ? 'whereNull' : 'whereNotNull';

            if ($method === 'orWhere') {
                $null_method = 'or' . ucfirst($null_method);
            }
            $query->{$null_method}($filter->property);

            return;
        }

        $value = $this->resolveFilterValue($filter->value);

        if ($filter->operator->value === 'in') {
            $in_method = $method === 'orWhere' ? 'orWhereIn' : 'whereIn';
            $query->{$in_method}($filter->property, (array) $value);

            return;
        }

        if ($filter->operator->value === 'between' && is_array($value)) {
            $between_method = $method === 'orWhere' ? 'orWhereBetween' : 'whereBetween';
            $query->{$between_method}($filter->property, $value);

            return;
        }

        $query->{$method}($filter->property, $filter->operator->value, $value);
    }

    /**
     * Resolve dynamic placeholders in an ACL filter value at query-build time.
     *
     * ACL filters are JSON-persisted with frozen literal values, so a stored value cannot express a moving
     * target such as the current time: {@see FilterPlaceholders} substitutes it with its live value.
     */
    private function resolveFilterValue(mixed $value): mixed
    {
        return FilterPlaceholders::resolve($value);
    }

    /**
     * Resolve a Permission model instance by name on the active guard, memoized for the current request.
     *
     * @param  string  $permission_name  The full permission name (e.g., 'default.orders.select')
     */
    private function resolvePermission(string $permission_name): Permission
    {
        $guard_name = Auth::getDefaultDriver();

        return once(fn (): Permission => Permission::query()
            ->where(['name' => $permission_name, 'guard_name' => $guard_name])
            ->firstOrFail());
    }
}
