<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Modules\Core\Authorization\PermissionExistenceMemo;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Console\PermissionsRefreshCommand;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Support\PermissionName;
use Modules\Core\Support\RelationGuard;

/**
 * The visibility rule of related records (spec 8.1): a relation to an independent entity is loaded only when
 * the caller holds that entity's `select` permission on the request's guard, and its query receives that
 * entity's ACL, exactly as a root query does. A part of its parent ({@see IsPartOfParent}) loaded from that
 * parent is not checked; reached from any other model, it is checked against its parent's permission and ACL.
 *
 * The ACL is applied as `key IN (visible keys)`, a subquery on the related table alone, so filters written
 * with plain column names never clash with the pivot table a many-to-many relation joins.
 */
final class RelationAuthorizer
{
    /**
     * How far a chain of parts may climb before it is taken for a declaration loop.
     */
    private const int MAX_PARENT_DEPTH = 8;

    public function __construct(private readonly AuthorizationService $auth) {}

    /**
     * Authorizes a relation the caller asked for: refuses it when the caller may not select the related entity,
     * otherwise narrows its query to the rows the related ACL allows. A morph relation is checked per morph
     * type, once its types are known (inside an eager load).
     *
     * @param  Relation<*, *, *>  $relation
     *
     * @throws AuthorizationException when the caller may not select the related entity
     */
    public function authorize(Request $request, Relation $relation): void
    {
        if ($relation instanceof MorphTo) {
            $this->constrainMorphTo($request, $relation, true);

            return;
        }

        throw_unless(
            $this->isReadable($request, $relation->getParent(), $relation->getRelated()),
            AuthorizationException::class,
            sprintf('User not allowed to read %s through %s', $relation->getRelated()->getTable(), $relation->getParent()->getTable()),
        );

        $this->constrain($relation->getQuery(), $relation->getParent());
    }

    /**
     * The same rule for a relation the caller did not ask for (a relation a model loads on its own, the
     * dependency of a computed column): false when the caller may not select the related entity, so the caller
     * drops it instead of refusing the request; otherwise the related ACL narrows it and the answer is true.
     *
     * @param  Relation<*, *, *>  $relation
     */
    public function constrainIfReadable(Request $request, Relation $relation): bool
    {
        if ($relation instanceof MorphTo) {
            $this->constrainMorphTo($request, $relation, false);

            return true;
        }

        if (! $this->isReadable($request, $relation->getParent(), $relation->getRelated())) {
            return false;
        }

        $this->constrain($relation->getQuery(), $relation->getParent());

        return true;
    }

    /**
     * Refuses the first relation of each path the caller may not select. Each path is a dotted chain of relation
     * names starting from the model; every name has already been accepted by {@see RelationGuard}. A path stops
     * being checked at a morph relation, whose related entity is known only per row: those are checked when the
     * relation loads.
     *
     * @param  list<string>  $paths
     *
     * @throws AuthorizationException when the caller may not select an entity on one of the paths
     * @throws InvalidArgumentException when a name on a path is not a relation
     */
    public function authorizePaths(Request $request, Model $model, array $paths): void
    {
        foreach ($paths as $path) {
            $parent = $model;

            foreach (explode('.', $path) as $name) {
                $relation = RelationGuard::relationOf($parent, $name);

                throw_unless($relation instanceof Relation, InvalidArgumentException::class, sprintf('"%s" is not a relation of %s.', $name, $parent::class));

                if ($relation instanceof MorphTo) {
                    break;
                }

                $related = $relation->getRelated();

                throw_unless(
                    $this->isReadable($request, $parent, $related),
                    AuthorizationException::class,
                    sprintf('User not allowed to read %s through %s', $related->getTable(), $parent->getTable()),
                );

                $parent = $related;
            }
        }
    }

    /**
     * Whether the caller may select the related entity when it is reached from the parent model.
     */
    public function isReadable(Request $request, Model $parent, Model $related): bool
    {
        // Credentials and model embeddings (vectors, model keys, content hashes) are never read through the CRUD,
        // whoever asks.
        if ($related instanceof PersonalAccessToken || $related instanceof ModelEmbedding) {
            return false;
        }

        // The pending modifications of a record are the values somebody proposed and nobody approved yet: only a
        // caller who may change or approve the record reads them.
        if ($related instanceof Modification) {
            return $this->maySeePendingChanges($request, $parent);
        }

        $source = $this->visibilitySource($parent, $related);

        if ($source === null) {
            return true;
        }

        // A part of a modification (its approvals) reached from anywhere but its modification: the record the
        // modification belongs to is unknown here, so the read fails closed.
        if ($source['model'] instanceof Modification) {
            return $this->allows($request, $source['model'], ActionEnum::Select->value);
        }

        // An entity deliberately kept out of permission generation has no select permission on any guard by
        // design. Of those, only a pivot record reached through a plain relation (the preset link a model loads
        // for its dynamic fields) is a part of its parent and readable with it. The others (versions, licenses,
        // dynamic entities, a translation reached from anything but the model it translates, which is recognised
        // above) are neither parts nor public: having no permission to grant, they are read as a relation by a
        // superadmin only. Any other entity without a permission fails closed: it was simply never granted.
        if (! $this->isGovernedByPermission($source['model']) && PermissionsRefreshCommand::isOutsidePermissionScheme($source['model']::class)) {
            return $source['model'] instanceof Pivot || $this->isSuperAdmin($request);
        }

        return $this->allows($request, $source['model'], ActionEnum::Select->value);
    }

    /**
     * Whether the caller may see the pending (unapproved) changes of a record: it holds the record's `update` or
     * `approve` permission, on the request's guard and within the abilities of its token.
     */
    public function maySeePendingChanges(Request $request, Model $record): bool
    {
        return $this->allows($request, $record, ActionEnum::Update->value)
            || $this->allows($request, $record, ActionEnum::Approve->value);
    }

    /**
     * Whether the caller may read the history of a record: it holds `history`, or `revert`, which implies it (D27), on
     * the request's guard and within the abilities of its token. Reading the record itself (`select`) is checked apart.
     */
    public function mayReadHistory(Request $request, Model $record): bool
    {
        return $this->allows($request, $record, ActionEnum::History->value)
            || $this->allows($request, $record, ActionEnum::Revert->value);
    }

    /**
     * {@see maySeePendingChanges()} for a model serializing itself (the approvals preview), which has no request of
     * its own. With no acting user (console, queue) there is no caller to hide them from.
     */
    public function actingUserMaySeePendingChanges(Model $record): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return true;
        }

        $request = request()->duplicate();
        $request->setUserResolver(static fn (): Authenticatable => $user);

        return $this->maySeePendingChanges($request, $record);
    }

    /**
     * The keys of the related rows the caller may see, for a query that reads a related table directly instead of
     * loading a relation (facets): refuses an entity the caller may not select, and answers null when no ACL
     * narrows it.
     *
     *
     * @throws AuthorizationException when the caller may not select the related entity
     *
     * @return Builder<Model>|null
     */
    public function visibleKeysQuery(Request $request, Model $parent, Model $related, ?string $key_column = null): ?Builder
    {
        throw_unless(
            $this->isReadable($request, $parent, $related),
            AuthorizationException::class,
            sprintf('User not allowed to read %s through %s', $related->getTable(), $parent->getTable()),
        );

        $source = $this->visibilitySource($parent, $related);

        if ($source === null) {
            return null;
        }

        $permission = PermissionName::forModel($source['model'], ActionEnum::Select->value);

        if (! $this->aclFilters($permission) instanceof FiltersGroup) {
            return null;
        }

        $keys = $related->newQueryWithoutScopes()->select($related->qualifyColumn($key_column ?? $related->getKeyName()));
        $this->applyAclThroughPath($keys, $source['path'], $permission);

        return $keys;
    }

    /**
     * Whether a select permission is registered for the model's table, on any guard.
     */
    public function isGovernedByPermission(Model $model): bool
    {
        /** @var class-string<Model> $permission_class */
        $permission_class = config('permission.models.permission');

        return PermissionExistenceMemo::exists($permission_class, PermissionName::forModel($model, ActionEnum::Select->value));
    }

    /**
     * Whether the caller holds the permission of an operation on the model's table, on the request's guard and
     * within the abilities of the token that authenticated it.
     */
    public function allows(Request $request, Model $model, string $operation): bool
    {
        return $this->auth->checkPermission($request, $model->getTable(), $operation, $model->getConnectionName());
    }

    /**
     * Narrows a query on the related model, reached from the parent model, to the rows the ACL of the entity
     * governing its visibility allows. Nothing happens for an unrestricted caller, nor for a part loaded from
     * its own parent.
     *
     * @param  Builder<Model>  $query
     */
    public function constrain(Builder $query, Model $parent): void
    {
        $related = $query->getModel();
        $source = $this->visibilitySource($parent, $related);

        if ($source === null) {
            return;
        }

        $permission = PermissionName::forModel($source['model'], ActionEnum::Select->value);

        if (! $this->aclFilters($permission) instanceof FiltersGroup) {
            return;
        }

        $query->whereIn($related->getQualifiedKeyName(), $this->visibleKeys($related, $source['path'], $permission));
    }

    /**
     * How many records of the parent's relation the related ACL leaves out: one aggregate count, and none at all
     * when no ACL narrows the relation.
     */
    public function hiddenCount(Request $request, Model $parent, string $relation): int
    {
        $instance = RelationGuard::relationOf($parent, $relation);

        if (! $instance instanceof Relation || $instance instanceof MorphTo) {
            return 0;
        }

        $related = $instance->getRelated();
        $source = $this->visibilitySource($parent, $related);

        if ($source === null || ! $this->allows($request, $source['model'], ActionEnum::Select->value)) {
            return 0;
        }

        $permission = PermissionName::forModel($source['model'], ActionEnum::Select->value);

        if (! $this->aclFilters($permission) instanceof FiltersGroup) {
            return 0;
        }

        return $instance->whereNotIn($related->getQualifiedKeyName(), $this->visibleKeys($related, $source['path'], $permission))->count();
    }

    /**
     * Loads a relation an appended attribute reads (a content's `cover` reads its media), narrowed by the related
     * ACL, and answers whether the attribute may show it. With no acting user (console, queue) there is no caller
     * to hide it from, and the relation is left to load as it always did.
     */
    public function loadForAppendedAttribute(Model $parent, string $relation): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return true;
        }

        $instance = RelationGuard::relationOf($parent, $relation);

        if (! $instance instanceof Relation || $instance instanceof MorphTo) {
            return false;
        }

        // The acting user is the authenticated one, whatever the current request object resolves (a job, a
        // command): the check must never fall back to the anonymous user and log it in.
        $request = request()->duplicate();
        $request->setUserResolver(static fn (): Authenticatable => $user);

        if (! $this->isReadable($request, $parent, $instance->getRelated())) {
            return false;
        }

        if (! $parent->relationLoaded($relation)) {
            $parent->load([$relation => function (Relation $query) use ($parent): void {
                $this->constrain($query->getQuery(), $parent);
            }]);
        }

        return true;
    }

    /**
     * Whether the caller of the request is a superadmin.
     */
    private function isSuperAdmin(Request $request): bool
    {
        $user = $this->auth->resolveUser($request);

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * Checks a morph relation per morph type: a type the caller may not select is refused when the relation was
     * asked for, or matches nothing when it was not; a readable type is narrowed by its ACL.
     *
     * @param  MorphTo<Model, Model>  $relation
     *
     * @throws AuthorizationException when an asked-for relation holds a type the caller may not select
     */
    private function constrainMorphTo(Request $request, MorphTo $relation, bool $requested): void
    {
        $parent = $relation->getParent();
        $constraints = [];

        foreach (array_keys($relation->getDictionary()) as $type) {
            $class = RelationGuard::morphModelClass((string) $type);

            if ($class === null) {
                continue;
            }

            if (! $this->isReadable($request, $parent, new $class)) {
                throw_if($requested, AuthorizationException::class, sprintf('User not allowed to read %s through %s', new $class()->getTable(), $parent->getTable()));

                $constraints[$class] = static fn (Builder $query): Builder => $query->whereRaw('1 = 0');

                continue;
            }

            $constraints[$class] = function (Builder $query) use ($parent): void {
                $this->constrain($query, $parent);
            };
        }

        $relation->constrain($constraints);
    }

    /**
     * The entity whose permission and ACL decide whether the related model may be read from the parent, and the
     * relation path leading from the related model to it. Null when the related model is a part reached from its
     * own parent (or from an ancestor further up its chain of parts), which inherits that visibility.
     *
     * @return array{model: Model, path: list<string>}|null
     */
    private function visibilitySource(Model $parent, Model $related): ?array
    {
        if ($this->isTranslationOf($parent, $related)) {
            return null;
        }

        $model = $related;
        $path = [];

        while ($model instanceof IsPartOfParent) {
            throw_if(count($path) >= self::MAX_PARENT_DEPTH, LogicException::class, sprintf('The parent chain of %s is longer than %d parts.', $related::class, self::MAX_PARENT_DEPTH));

            $name = $model->parentRelation();
            $relation = RelationGuard::relationOf($model, $name);

            throw_if(
                ! $relation instanceof Relation || $relation instanceof MorphTo,
                LogicException::class,
                sprintf('%s declares "%s" as its parent relation, which is not a relation to one parent model.', $model::class, $name),
            );

            $owner = $relation->getRelated();

            if ($parent instanceof $owner) {
                return null;
            }

            $path[] = $name;
            $model = $owner;
        }

        return ['model' => $model, 'path' => $path];
    }

    /**
     * Whether the related model is the translation model of the parent. A translation is the commonest part of
     * a parent, and it is recognised from the translated model: the translations shared by the subclasses of an
     * abstract model (taxonomies) cannot declare a parent relation, since Eloquent cannot build a relation to
     * an abstract model, and they inherit all the same.
     */
    private function isTranslationOf(Model $parent, Model $related): bool
    {
        if (! in_array(HasTranslations::class, class_uses_recursive($parent), true)) {
            return false;
        }

        $translations = RelationGuard::relationOf($parent, 'translations');

        if (! $translations instanceof Relation) {
            return false;
        }

        $translation_model = $translations->getRelated();

        return $related instanceof $translation_model;
    }

    /**
     * The keys of the related rows the ACL allows, on the related table alone.
     *
     * @param  list<string>  $path
     * @return Builder<Model>
     */
    private function visibleKeys(Model $related, array $path, string $permission): Builder
    {
        $keys = $related->newQueryWithoutScopes()->select($related->getQualifiedKeyName());
        $this->applyAclThroughPath($keys, $path, $permission);

        return $keys;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $path
     */
    private function applyAclThroughPath(Builder $query, array $path, string $permission): void
    {
        if ($path === []) {
            $this->auth->applyAclFiltersToQuery($query, $permission);

            return;
        }

        $query->whereHas(implode('.', $path), function (Builder $owner) use ($permission): void {
            $this->auth->applyAclFiltersToQuery($owner, $permission);
        });
    }

    /**
     * The ACL of the permission for the acting user; null when none narrows it, including when the permission
     * does not exist on the request's guard (a caller cannot hold it, and a relation reaching here without a
     * permission check comes from an ACL filter, which names relations on its own authority).
     */
    private function aclFilters(string $permission): ?FiltersGroup
    {
        try {
            return $this->auth->getAclFilters($permission);
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
