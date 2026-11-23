<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;
use Modules\Core\Authorization\ResolvingAuthorization;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLookup;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

/**
 * Service for resolving effective ACLs for users based on their roles and permissions.
 *
 * The ACL resolution follows these rules:
 *
 * 1. PERMISSION CHECK: User must have the permission (via role or direct assignment)
 * 2. INHERITANCE: If a role has an ACL for the permission → use it (overrides parent)
 *                 If a role has NO ACL → inherit from parent role in hierarchy
 * 3. UNRESTRICTED: ACLs with unrestricted=true are "transparent" - they don't contribute
 *                  filters to the query. The role still exists but imposes no restrictions.
 * 4. MULTIPLE ROLES: Non-hierarchical roles that contribute filters are combined with OR
 * 5. NO CONTRIBUTORS: If no ACLs contribute filters → user sees everything
 * 6. PRIORITY: Higher priority ACLs are evaluated first within the same level
 *
 * Example scenarios:
 * ```
 * Scenario 1: Inheritance with override
 * guest (root) → ACL: { filters: [status = 'published'] }
 *   └── editor → NO ACL → inherits from guest (sees only published)
 *       └── admin → ACL: { unrestricted: true } → transparent, no filters
 *
 * Scenario 2: Multiple roles
 * User with roles: sales_it (country='IT') + sales_de (country='DE')
 * Result: country='IT' OR country='DE'
 *
 * Scenario 3: Mixed roles
 * User with roles: sales (country='IT') + supervisor (unrestricted=true)
 * Result: country='IT' (supervisor doesn't contribute)
 * ```
 */
final class AclResolverService
{
    private const int CACHE_TTL = 3600; // 1 hour

    /**
     * Get the effective ACLs for a user on a specific permission.
     *
     * @return Collection<int, ACL> Collection of effective ACLs (empty if unrestricted or no ACLs)
     */
    public function getEffectiveAcls(User $user, Permission $permission): Collection
    {
        $connection_name = $this->assertSharedConnection($user, $permission);

        // Stores nothing while a failover index is degraded, or when a label was flushed during the resolution.
        /** @var Collection<int, ACL> */
        return resolve(LabelledCache::class)->remember(
            self::buildCacheKey($connection_name, $user->getKey(), $permission->getKey()),
            self::dependencies($user->getKey()),
            self::CACHE_TTL,
            // Reading the ACL rows is part of working out what the user may read: the per-row select check of the
            // ACL model must not ask the very question being answered, or a role that holds a permission narrowed by
            // an ACL (the guest of the api, the publisher) could not resolve its own narrowing.
            fn (): Collection => ResolvingAuthorization::resolve(fn (): Collection => $this->resolveAcls($user, $permission)),
            static fn (mixed $acls): bool => $acls instanceof Collection,
        );
    }

    /**
     * Get combined filters for a user on a specific permission.
     * Returns null if no filters apply (user sees everything).
     *
     * Logic:
     * - ACLs with unrestricted=true are "transparent" - they don't contribute to the query
     * - ACLs with filters contribute those filters
     * - Multiple contributing ACLs are combined with OR (union of access)
     * - If NO ACLs contribute filters → user sees everything
     */
    public function getCombinedFilters(User $user, Permission $permission): ?FiltersGroup
    {
        $acls = $this->getEffectiveAcls($user, $permission);

        if ($acls->isEmpty()) {
            return null;
        }

        // Filter out unrestricted ACLs - they don't contribute to the query
        // Only ACLs with actual filters contribute
        $contributing_acls = $acls->filter(
            fn (ACL $acl): bool => ! $acl->isUnrestricted() && $acl->hasFilters(),
        );

        // If no ACLs contribute filters, user sees everything
        if ($contributing_acls->isEmpty()) {
            return null;
        }

        // Collect all filter groups from contributing ACLs
        $all_filters = $contributing_acls
            ->map(fn (ACL $acl) => $acl->filters)
            ->values()
            ->all();

        // If only one filter group, return it directly
        if (count($all_filters) === 1) {
            return $all_filters[0];
        }

        // Combine multiple filter groups with OR (union of access from different roles)
        return new FiltersGroup(
            filters: $all_filters,
            operator: WhereClause::Or,
        );
    }

    /**
     * Check if a user has unrestricted access to a permission.
     *
     * A user has unrestricted access when NO ACLs contribute filters.
     * This happens when:
     * - User has no ACLs at all
     * - All ACLs are marked as unrestricted=true
     * - No ACLs have actual filters defined
     */
    public function hasUnrestrictedAccess(User $user, Permission $permission): bool
    {
        $acls = $this->getEffectiveAcls($user, $permission);

        if ($acls->isEmpty()) {
            return true;
        }

        // User has unrestricted access if no ACL contributes filters
        $has_contributing_acls = $acls->contains(
            fn (ACL $acl): bool => ! $acl->isUnrestricted() && $acl->hasFilters(),
        );

        return ! $has_contributing_acls;
    }

    /**
     * Clear cached ACLs for a user.
     */
    public function clearCacheForUser(User $user): void
    {
        // Get all permissions and clear cache for each
        $permissions = (new Permission)
            ->setConnection($user->getConnection()->getName())
            ->newQuery()
            ->select('id')
            ->get();

        $index = self::index();

        foreach ($permissions as $permission) {
            $index->forget(self::lookup($index, $user->getConnection()->getName(), $user->getKey(), $permission->getKey()));
        }
    }

    /**
     * Clear cached ACLs for a specific permission using targeted key-based invalidation.
     *
     * When the number of affected users exceeds the configured threshold
     * (config('core.acl.clear_threshold', 500)), falls back to flushing every
     * cached resolution (the `acl` label) to avoid excessive individual deletes.
     */
    public function clearCacheForPermission(Permission $permission): void
    {
        $threshold = (int) config('core.acl.clear_threshold', 500);

        // Collect all user IDs that have this permission (directly or via roles)
        $user_ids = $this->getUserIdsForPermission($permission);

        if ($threshold < $user_ids->count()) {
            $this->flushCache();

            return;
        }

        $index = self::index();

        foreach ($user_ids as $user_id) {
            $index->forget(self::lookup($index, $permission->getConnection()->getName(), $user_id, $permission->getKey()));
        }
    }

    /**
     * Flush every cached ACL resolution across all users and permissions: every one carries the `acl` label.
     *
     * Needed when an ACL write must invalidate entries whose exact keys are not known (e.g. role-scoped ACLs
     * affecting many users). The flush is immediate; {@see \Modules\Core\Observers\AclObserver} flushes the same
     * label after the transaction of the ACL write commits.
     */
    public function flushCache(): void
    {
        self::index()->flush(CacheLabel::acl());
    }

    /**
     * Build the ACL cache key for a specific user and permission.
     */
    private static function buildCacheKey(string $connection_name, mixed $user_id, mixed $perm_id): string
    {
        return CacheManager::key('acl', 'connection', $connection_name, 'user', self::keyString($user_id), 'perm', self::keyString($perm_id));
    }

    /**
     * Resolved from the container on each call rather than injected: the service is also built with `new`, and it
     * must store into the same index the label flushes reach.
     */
    private static function index(): ICacheLabelIndex
    {
        return resolve(ICacheLabelIndex::class);
    }

    /**
     * The lookup of a resolution, to forget it.
     */
    private static function lookup(ICacheLabelIndex $index, string $connection_name, mixed $user_id, mixed $perm_id): CacheLookup
    {
        return $index->lookup(self::buildCacheKey($connection_name, $user_id, $perm_id), self::dependencies($user_id));
    }

    /**
     * A resolution depends on the ACL rows (`acl`, also flushed when a role gains or loses a permission) and on the
     * roles and direct permissions of the user (`user:{id}`).
     */
    private static function dependencies(mixed $user_id): CacheDependencies
    {
        return new CacheDependencies([CacheLabel::acl(), CacheLabel::user(self::keyString($user_id))]);
    }

    private static function keyString(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : '0';
    }

    /**
     * Collect all user IDs that have the given permission, either directly
     * or through a role assignment.
     *
     * @return Collection<int, int|string>
     */
    private function getUserIdsForPermission(Permission $permission): Collection
    {
        $user_model = config('auth.providers.users.model', \App\Models\User::class);
        $connection = $permission->getConnection();

        // Users with the permission assigned directly
        $direct_ids = $connection->table(config('permission.table_names.model_has_permissions'))
            ->where('permission_id', $permission->id)
            ->where('model_type', $user_model)
            ->pluck('model_id');

        // Users with a role that has this permission
        $role_ids = $connection->table(config('permission.table_names.role_has_permissions'))
            ->where('permission_id', $permission->id)
            ->pluck('role_id');

        $via_role_ids = collect();

        if ($role_ids->isNotEmpty()) {
            $via_role_ids = $connection->table(config('permission.table_names.model_has_roles'))
                ->whereIn('role_id', $role_ids)
                ->where('model_type', $user_model)
                ->pluck('model_id');
        }

        return $direct_ids->merge($via_role_ids)->unique()->values();
    }

    /**
     * Resolve ACLs for a user on a permission using a single batch query.
     *
     * Instead of issuing one query per role (N+1), this method:
     * 1. Collects all role IDs for the user (direct + ancestors for inheritance)
     * 2. Loads all relevant ACLs in a single `whereIn` query with eager-loaded `permission.roles`
     * 3. Reconstructs the inheritance logic (role → ancestor fallback) in memory
     *
     * @return Collection<int, ACL>
     */
    private function resolveAcls(User $user, Permission $permission): Collection
    {
        // SuperAdmin bypasses all ACLs
        if ($user->isSuperAdmin()) {
            return collect([
                $this->createUnrestrictedAcl($permission),
            ]);
        }

        /** @var Collection<int, Role> $roles */
        $roles = $user->roles;

        // A permission can reach a user two ways, and the entity-level gate honours both: through a
        // role, or granted straight to the user. The ACLs have to honour both as well. While this
        // returned early on a user with no roles, a direct grant carried no row filters at all, so
        // handing someone a permission directly quietly gave them every row of the table.
        $has_direct_permission = $user->hasDirectPermission($permission);

        if ($roles->isEmpty() && ! $has_direct_permission) {
            return collect();
        }

        // Build a map of role_id → Role for all direct roles and their ancestors.
        // This is needed to reconstruct the inheritance chain in memory.
        /** @var Collection<int, Role> $all_roles_map */
        $all_roles_map = collect();

        foreach ($roles as $role) {
            $all_roles_map->put($role->id, $role);

            foreach ($role->ancestors as $ancestor) {
                $all_roles_map->put($ancestor->id, $ancestor);
            }
        }

        $all_role_ids = $all_roles_map->keys()->all();

        // Single batch query: load all active ACLs for this permission
        // that are associated with any of the user's roles (direct or ancestor).
        // Eager-load permission.roles to avoid N+1 in the matching logic below.
        /** @var Collection<int, ACL> $batch_acls */
        $batch_acls = $permission->acls()
            ->active()
            ->byPriority()
            ->with(['permission.roles'])
            ->where(static function (Builder $query) use ($all_role_ids): void {
                // Role-scoped ACLs apply only to their own role; unscoped (null) ACLs
                // apply to every holder of the permission.
                $query->whereNull('role_id')->orWhereIn('role_id', $all_role_ids);
            })
            // The permission has to actually reach this user. Through a role, that means one of the
            // user's roles holds it; a direct grant needs no role at all, so the constraint is
            // dropped rather than made unsatisfiable.
            ->when(! $has_direct_permission, static fn (Builder $query): Builder => $query->whereHas(
                'permission.roles',
                static function (Builder $roles_query) use ($all_role_ids): void {
                    $roles_query->whereIn($roles_query->qualifyColumn('id'), $all_role_ids);
                },
            ))
            ->get();

        // Build a lookup: role_id → ACL (highest priority ACL for that role, already ordered)
        /** @var array<int, ACL> $acl_by_role */
        $acl_by_role = [];

        foreach ($batch_acls as $acl) {
            // A role-scoped ACL maps only to its own role; an unscoped ACL maps to
            // every role that holds the permission (eager-loaded to avoid N+1).
            $acl_role_ids = $acl->role_id !== null
                ? [$acl->role_id]
                : $acl->permission->roles->pluck('id')->all();

            foreach ($acl_role_ids as $acl_role_id) {
                // Only map roles that are in our set; keep the first (highest priority) ACL per role
                if (in_array($acl_role_id, $all_role_ids, true) && ! isset($acl_by_role[$acl_role_id])) {
                    $acl_by_role[$acl_role_id] = $acl;
                }
            }
        }

        // Reconstruct inheritance logic in memory for each direct role
        $resolved_acls = collect();

        foreach ($roles as $role) {
            // Skip roles that don't have this permission (direct or via ancestors)
            if (! $role->hasPermission($permission->name, $permission->guard_name)) {
                continue;
            }

            $acl = $this->resolveAclFromMapForRole($role, $acl_by_role);

            if ($acl instanceof ACL) {
                $resolved_acls->push($acl);
            }
        }

        if ($has_direct_permission) {
            // A direct grant is not attached to any role, so only an unscoped ACL can speak for it.
            // The batch is ordered by priority, so the first one is the one that wins. Added only
            // when the role pass has not already reached it: the same ACL twice would say the same
            // thing, since the filter groups are combined with OR, but it would be noise.
            $direct_acl = $batch_acls->first(static fn (ACL $acl): bool => $acl->role_id === null);

            if ($direct_acl instanceof ACL && ! $resolved_acls->contains(
                static fn (ACL $acl): bool => $acl->getKey() === $direct_acl->getKey(),
            )) {
                $resolved_acls->push($direct_acl);
            }
        }

        return $resolved_acls;
    }

    /**
     * Resolve ACL for a role using the pre-loaded in-memory map.
     * Implements inheritance: if role has no ACL in the map, check ancestor roles.
     *
     * @param  array<int, ACL>  $acl_by_role
     */
    private function resolveAclFromMapForRole(Role $role, array $acl_by_role): ?ACL
    {
        // Direct match: this role has an ACL
        if (isset($acl_by_role[$role->id])) {
            return $acl_by_role[$role->id];
        }

        // Inheritance fallback: walk ancestors from closest to root
        foreach ($role->ancestors as $ancestor) {
            if (isset($acl_by_role[$ancestor->id])) {
                return $acl_by_role[$ancestor->id];
            }
        }

        return null;
    }

    /**
     * Create a virtual unrestricted ACL for superadmin.
     */
    private function createUnrestrictedAcl(Permission $permission): ACL
    {
        $acl = (new ACL)->setConnection($permission->getConnection()->getName());
        $acl->permission_id = $permission->id;
        $acl->unrestricted = true;
        $acl->is_active = true;
        $acl->priority = PHP_INT_MAX;

        return $acl;
    }

    private function assertSharedConnection(User $user, Permission $permission): string
    {
        $user_connection_name = $user->getConnection()->getName();

        throw_if(
            $user_connection_name !== $permission->getConnection()->getName(),
            LogicException::class,
            'ACL resolution requires user and permission models on the same database connection.',
        );

        return $user_connection_name;
    }
}
