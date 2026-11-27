<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Casts\Column;
use Modules\Core\Casts\ColumnType;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\SelectRequestData;
use Modules\Core\Casts\Sort;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Contracts\IMountsRelatedAttributes;
use Modules\Core\Contracts\IPreloadsAppendedRelations;
use Modules\Core\Exceptions\ScopeFilterException;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Overrides\CustomSoftDeletingScope;
use Modules\Core\SoftDeletes\SoftDeletes;
use Modules\Core\Support\RelationGuard;
use Modules\Core\Support\ScopeGuard;
use ReflectionMethod;

/**
 * QueryBuilder - prepares Eloquent queries from CRUD request data.
 *
 * This class is responsible ONLY for query manipulation:
 * - Applying columns, filters, sorts, relations
 * - Preparing the query builder based on SelectRequestData/ListRequestData
 *
 * Authorization logic (permissions and ACLs) is handled by AuthorizationService.
 * The ACL filters should be injected into the request BEFORE calling prepareQuery().
 *
 * Usage:
 * ```php
 * // In CrudService:
 * $auth->injectAclFilters($requestData, $permission_name);  // Inject ACL filters
 * $query_builder->prepareQuery($query, $requestData);        // Now filters include ACLs
 * ```
 */
final class QueryBuilder
{
    /**
     * Relations a CRUD read never loads, at any depth of a path: tree walks (their own endpoint), version
     * history (its own endpoint, and every past value of every field), and access tokens (credentials).
     *
     * @var list<string>
     */
    public const array RELATION_BLACK_LIST = [
        'history',
        'versions',
        'lastVersion',
        'latestVersion',
        'firstVersion',
        'latestVersions',
        'oldestVersions',
        'tokens',
        'embeddings',
        'ancestors',
        'ancestorsAndSelf',
        'bloodline',
        'children',
        'childrenAndSelf',
        'descendants',
        'descendantsAndSelf',
        'parentAndSelf',
        'rootAncestor',
        'siblings',
        'siblingsAndSelf',
    ];

    /**
     * Whether a relation path names a black-listed relation at any depth.
     */
    public static function isBlackListedPath(string $path): bool
    {
        return array_intersect(explode('.', $path), self::RELATION_BLACK_LIST) !== [];
    }

    /**
     * Prepare the query based on request data.
     *
     * This applies columns, filters, sorts, and relations from the request.
     * ACL filters should already be injected into $request_data->filters.
     *
     * @param  Builder<Model>  $query
     * @param  (callable(string, Builder<Model>): void)|null  $countConstraint  Optional
     *                                                                          hook to constrain each main-model relation-count subquery.
     *
     * @throws InvalidArgumentException when a relation name of the request is not a relation
     */
    public function prepareQuery(Builder $query, SelectRequestData $request_data, ?callable $countConstraint = null): void
    {
        $this->buildQuery($query, $request_data, $countConstraint, null);
    }

    /**
     * {@see prepareQuery()} for a caller's request: every relation the query loads, counts or filters on obeys
     * the related entity's permission and ACL (spec 8.1). A relation the request asked for (by `relations`, a
     * dotted column, a sort or an aggregate) is refused when the caller may not select the related entity, one
     * the query loads on its own (a model's default eager loads, a computed column's dependency, an ACL filter's
     * relation) is left out instead, and each receives the related ACL. The relations of the request filters
     * are checked before the ACL is merged into them, by {@see requestedRelationPaths()} and
     * {@see RelationAuthorizer::authorizePaths()}; here their existence checks only receive the related ACL.
     *
     * @param  Builder<Model>  $query
     *
     * @throws InvalidArgumentException when a relation name of the request is not a relation
     * @throws AuthorizationException when the caller may not select an entity the request asked for
     */
    public function prepareAuthorizedQuery(Builder $query, SelectRequestData $request_data, RelationAuthorizer $relation_authorizer): void
    {
        $this->buildQuery($query, $request_data, null, $relation_authorizer);
    }

    /**
     * Loads the relations a request names (`relations` and the relation part of its dotted columns) on a query
     * built elsewhere, the record reload of a search, under the same rules as {@see prepareQuery()}: the
     * black list, the related columns, the related permission and the related ACL.
     *
     * @param  Builder<Model>  $query
     *
     * @throws InvalidArgumentException when a relation name of the request is not a relation
     * @throws AuthorizationException when the caller may not select an entity the request asked for
     */
    public function applyRequestedRelations(Builder $query, SelectRequestData $request_data, ?RelationAuthorizer $relation_authorizer = null): void
    {
        $request = $relation_authorizer instanceof RelationAuthorizer ? $request_data->request : null;
        $main_entity = $query->getModel()->getTable();
        $relations = $this->normalizeRelations($request_data->relations);
        $relations_columns = [];
        $relations_sorts = [];
        $relations_aggregates = [];
        $relations_filters = [];
        $request_columns = $this->requestColumns($query->getModel(), $request_data->columns);
        $computed_relations = $this->extractComputedColumns($main_entity, $request_columns)['relations'];

        foreach ($this->groupColumns($main_entity, $request_columns)['relations'] as $relation => $relation_cols) {
            $relations_columns[$relation] = array_values(array_filter($relation_cols, static fn (Column $column): bool => $column->type === ColumnType::Column));

            if (! in_array($relation, $relations, true)) {
                $relations[] = $relation;
            }
        }

        foreach (array_keys($computed_relations) as $computed_relation) {
            if (! in_array($computed_relation, $relations, true)) {
                $relations[] = $computed_relation;
            }
        }

        if ($relation_authorizer instanceof RelationAuthorizer && $request instanceof Request) {
            $this->guardDefaultEagerLoads($query, $relation_authorizer, $request, []);
        }

        if ($relations !== []) {
            $this->applyRelations($query, $relations, $relations_columns, $relations_sorts, $relations_aggregates, $relations_filters, $computed_relations, $relation_authorizer, $request, $relations);
        }
    }

    /**
     * Every relation path a request names, each name checked by {@see RelationGuard} before anything calls it:
     * `relations`, the relation part of dotted, aggregate and computed columns, of sorts and of filters. The
     * black-listed relations are dropped from the paths that would load them, as {@see prepareQuery()} drops
     * them. With `$lenient_filters` (search, whose filters and sorts name index fields), a filter or sort
     * property contributes only the part of it that resolves to relations.
     *
     * Call it before the ACL filters are merged into the request: they name relations on their own authority.
     * Without `$include_columns` (facets, whose columns are bare facet fields of the main table) the columns are
     * not read as relation paths.
     *
     *
     * @throws InvalidArgumentException when a relation name of the request is not a relation
     *
     * @return list<string>
     */
    public function requestedRelationPaths(Model $model, SelectRequestData $request_data, bool $lenient_filters = false, bool $include_columns = true): array
    {
        $by_use = $this->requestedRelationPathsByUse($model, $request_data, $lenient_filters, $include_columns);

        return array_values(array_unique(array_merge($by_use['display'], $by_use['membership'])));
    }

    /**
     * {@see requestedRelationPaths()} split by what the path is used for. `display` holds the relations loaded to
     * be shown: `relations` and the relation part of plain dotted columns. `membership` holds the ones that decide
     * which rows come out or how they are counted and ordered: aggregate columns, computed columns, group by,
     * sorts and filters. A path used both ways is in both lists. The guards and the black-list handling are those
     * of {@see requestedRelationPaths()}.
     *
     * @throws InvalidArgumentException when a relation name of the request is not a relation
     *
     * @return array{display: list<string>, membership: list<string>}
     */
    public function requestedRelationPathsByUse(Model $model, SelectRequestData $request_data, bool $lenient_filters = false, bool $include_columns = true): array
    {
        $main_entity = $model->getTable();
        $request_columns = $include_columns ? $this->requestColumns($model, $request_data->columns) : [];
        $columns = $this->groupColumns($main_entity, $request_columns);
        $displayed = array_merge(
            $this->normalizeRelations($request_data->relations),
            array_keys($columns['relations']),
        );
        $loaded_for_membership = array_merge(
            array_keys($columns['aggregates']),
            array_keys($this->extractComputedColumns($main_entity, $request_columns)['relations']),
            $this->groupByRelations($request_data),
        );
        $this->cleanRelations($displayed);
        $this->cleanRelations($loaded_for_membership);

        $named = [];

        if ($request_data instanceof ListRequestData) {
            foreach ($request_data->sort ?? [] as $sort) {
                $property = (string) $sort->property;

                if (! Str::contains($property, '.') || Str::startsWith($property, $main_entity . '.')) {
                    continue;
                }

                $named[] = $this->splitColumnNameOnLastDot($property)[0];
            }

            if ($request_data->filters instanceof FiltersGroup) {
                array_push($named, ...$this->filterRelationPaths($model, $request_data->filters));
            }
        }

        $display = [];

        foreach ($displayed as $path) {
            $display[] = $this->assertRelationPath($model, $path);
        }

        $membership = [];

        foreach ($loaded_for_membership as $path) {
            $membership[] = $this->assertRelationPath($model, $path);
        }

        foreach ($named as $path) {
            $path = $lenient_filters ? $this->resolvableRelationPrefix($model, $path) : $this->assertRelationPath($model, $path);

            if ($path !== '') {
                $membership[] = $path;
            }
        }

        return [
            'display' => array_values(array_unique($display)),
            'membership' => array_values(array_unique($membership)),
        ];
    }

    /**
     * Apply a FiltersGroup directly to a query.
     *
     * Useful when you need to apply filters outside of the normal request flow.
     *
     * Given a relation authorizer, the existence checks of the relation filters receive the related ACL, as in
     * {@see prepareAuthorizedQuery()}; their related permission is checked by the caller beforehand.
     *
     * The group always joins the query as one condition, inside its own nested `where`: the query usually holds
     * conditions already (a key, the ACL of the caller), and a root OR group applied to the query itself would
     * join them with `OR` (`where (acl) and a or b`) and let its later branches escape them.
     *
     * @param  Builder<Model>  $query
     * @param  array<string,array<int,Column>>  $relation_columns
     */
    public function applyFilters(Builder $query, FiltersGroup $filters, array &$relation_columns = [], ?RelationAuthorizer $relation_authorizer = null, ?Request $request = null): void
    {
        $query->where(function (Builder $nested) use ($filters, &$relation_columns, $relation_authorizer, $request): void {
            $this->recursivelyApplyFilters($nested, $filters, $relation_columns, $relation_authorizer, $request);
        });
    }

    /**
     * {@see guardDefaultEagerLoads()} for a query the CRUD engine builds itself, after the request relations were
     * applied (the nodes of a tree, the versions of a record): the relations that query loads on its own, and the
     * ones its model's appended attributes read, follow the same rule instead of loading unchecked or row by row.
     *
     * @param  Builder<Model>  $builder
     */
    public function guardEngineQuery(Builder $builder, RelationAuthorizer $authorizer, Request $request): void
    {
        $this->guardDefaultEagerLoads($builder, $authorizer, $request, []);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  (callable(string, Builder<Model>): void)|null  $countConstraint
     *
     * @throws InvalidArgumentException when a relation name of the request is not a relation
     * @throws AuthorizationException when the caller may not select an entity the request asked for
     */
    private function buildQuery(Builder $query, SelectRequestData $request_data, ?callable $countConstraint, ?RelationAuthorizer $relation_authorizer): void
    {
        $request = $relation_authorizer instanceof RelationAuthorizer ? $request_data->request : null;
        $main_model = $query->getModel();
        $main_entity = $main_model->getTable();
        $relations_sorts = [];
        $relations_columns = [];
        $relations_filters = [];
        $normalized_relations = $this->normalizeRelations($request_data->relations);
        $requested_relations = $normalized_relations;
        $request_columns = $this->requestColumns($main_model, $request_data->columns);
        $computed_columns = $this->extractComputedColumns($main_entity, $request_columns);
        $computed_main = $computed_columns['main'];
        $computed_relations = $computed_columns['relations'];
        $computed_main_dependencies = $this->resolveComputedDependencies($main_model, $computed_main);
        $force_select_all_main = $computed_main_dependencies['force_select_all'];

        if ($computed_main['append'] !== []) {
            $this->applyModelAppends($main_model, $computed_main['append']);
        }

        if ($computed_main_dependencies['relations'] !== []) {
            $normalized_relations = array_values(array_unique(array_merge($normalized_relations, $computed_main_dependencies['relations'])));
        }

        // A computed column on a relation is read from the loaded relation, so the relation is loaded here,
        // under the same rule as any other, rather than lazily and unchecked while the column is computed.
        foreach (array_keys($computed_relations) as $computed_relation) {
            $requested_relations[] = $computed_relation;

            if (! in_array($computed_relation, $normalized_relations, true)) {
                $normalized_relations[] = $computed_relation;
            }
        }

        $columns = $this->groupColumns($main_entity, $request_columns);

        foreach ($columns as $type => $cols) {
            if ($type === 'main' && $cols !== []) {
                $this->sortColumns($query, $cols);

                /** @var array<int,string> $only_standard_columns */
                $only_standard_columns = [];

                foreach ($cols as $column) {
                    if ($column->type === ColumnType::Column) {
                        $only_standard_columns[] = $column->name;
                    }
                }

                if ($computed_main_dependencies['columns'] !== [] && ! $force_select_all_main) {
                    foreach ($computed_main_dependencies['columns'] as $dependency_column) {
                        if (! in_array($dependency_column, $only_standard_columns, true)) {
                            $only_standard_columns[] = $dependency_column;
                        }
                    }
                }

                if ($force_select_all_main) {
                    $only_standard_columns = [$main_entity . '.*'];
                }

                if (! $force_select_all_main) {
                    $this->addForeignKeysToSelectedColumns($query, $only_standard_columns, $main_model, $main_entity);
                }
                $query->select($only_standard_columns);
            } elseif ($type === 'relations' && $cols !== []) {
                foreach ($cols as $relation => $relation_cols) {
                    $only_relation_columns = [];

                    foreach ($relation_cols as $column) {
                        if ($column->type === ColumnType::Column) {
                            $only_relation_columns[] = $column;
                        }
                    }

                    $relations_columns[$relation] = $only_relation_columns;
                    $requested_relations[] = $relation;

                    if (! in_array($relation, $normalized_relations, true)) {
                        $normalized_relations[] = $relation;
                    }
                }
            }
        }

        if ($request_data instanceof ListRequestData && isset($request_data->sort)) {
            foreach ($request_data->sort as $column) {
                $property = $this->mountedProperty($main_model, (string) $column->property);

                if ($this->orderByToOneRelation($query, $property, $column->direction->value, $relation_authorizer, $request)) {
                    continue;
                }

                if (! Str::contains($property, '.')) {
                    if (method_exists($main_model, $property)) {
                        $relation_method = new ReflectionMethod($main_model, $property);
                        $return_type = $relation_method->getReturnType();

                        if ($return_type !== null && is_a($return_type->__toString(), Relation::class, true)) {
                            continue;
                        }
                    }

                    $query->orderBy($property, $column->direction->value);

                    continue;
                }

                if (Str::startsWith($property, $main_entity . '.')) {
                    $query->orderBy($property, $column->direction->value);

                    continue;
                }

                $index = str_replace($main_entity . '.', '', $property);
                $splitted = $this->splitColumnNameOnLastDot($index);

                if (! isset($splitted[1])) {
                    continue;
                }

                $cloned_column = new Sort($splitted[1], $column->direction);
                $relations_sorts[$splitted[0]][] = $cloned_column;
            }
        }

        // A group_by key through a relation is read from each row: the relation is loaded here, asked for, so that
        // it obeys the related permission and ACL instead of loading lazily and unchecked while grouping.
        foreach ($this->groupByRelations($request_data) as $group_relation) {
            $requested_relations[] = $group_relation;

            if (! in_array($group_relation, $normalized_relations, true)) {
                $normalized_relations[] = $group_relation;
            }
        }

        if ($request_data instanceof ListRequestData && isset($request_data->filters)) {
            // One nested condition, as in applyFilters(): the query may already hold conditions, and a root OR
            // group applied to it directly would let its later branches escape them.
            $list_filters = $request_data->filters;
            $query->where(function (Builder $nested) use ($list_filters, &$columns, $relation_authorizer, $request): void {
                $this->recursivelyApplyFilters($nested, $list_filters, $columns['relations'], $relation_authorizer, $request);
            });

            $relations_filters = $this->extractRelationFilters($main_model, $request_data->filters);

            if ($relations_filters !== []) {
                $normalized_relations = array_values(array_unique(array_merge($normalized_relations, array_keys($relations_filters))));
            }
        }

        // Main-model relation aggregates (dotless keys) apply whether or not other
        // relations are eager-loaded; the relation pass then handles the dotted ones.
        $this->applyMainAggregates($query, $columns['aggregates'], $countConstraint, $relation_authorizer, $request);

        if ($relation_authorizer instanceof RelationAuthorizer && $request instanceof Request) {
            // The relations the model loads on its own were not asked for: they follow the rule, but quietly.
            $this->guardDefaultEagerLoads($query, $relation_authorizer, $request, []);
        }

        if ($normalized_relations !== []) {
            $this->applyRelations($query, $normalized_relations, $relations_columns, $relations_sorts, $columns['aggregates'], $relations_filters, $computed_relations, $relation_authorizer, $request, $requested_relations);
        }
    }

    /**
     * Extract relation-only filters from a mixed FiltersGroup.
     *
     * This is used to constrain eager-loaded relations so that the returned related
     * records match the same relation filters used for `whereHas`.
     *
     * @return array<string, FiltersGroup>
     */
    private function extractRelationFilters(Model $main_model, FiltersGroup $filters): array
    {
        $relations = [];

        $collect_relations = function (FiltersGroup $group) use (&$relations, $main_model, &$collect_relations): void {
            foreach ($group->filters as $subfilter) {
                if ($subfilter instanceof FiltersGroup) {
                    $collect_relations($subfilter);

                    continue;
                }

                // A relation filter (ACL only) constrains the rows, never an eager load.
                if (! $subfilter instanceof Filter) {
                    continue;
                }

                $path_length = mb_substr_count($subfilter->property, '.');

                if ($path_length < 1) {
                    continue;
                }

                $splitted = $this->splitProperty($main_model, $subfilter->property);

                if ($splitted['relation'] === '') {
                    continue;
                }

                $relation_path = (string) $splitted['relation'];
                $relations[] = $relation_path;

                // Also constrain parent relations for nested filters.
                // Example: roles.permissions -> roles
                while (Str::contains($relation_path, '.')) {
                    $relation_path = (string) Str::beforeLast($relation_path, '.');
                    $relations[] = $relation_path;
                }
            }
        };

        $collect_relations($filters);

        $relations = array_values(array_unique($relations));

        $result = [];

        foreach ($relations as $relation) {
            $relation_filters = $this->extractFiltersForRelation($main_model, $filters, $relation);

            if ($relation_filters instanceof FiltersGroup) {
                $result[$relation] = $relation_filters;
            }
        }

        return $result;
    }

    private function extractFiltersForRelation(Model $main_model, FiltersGroup $filters, string $relation): ?FiltersGroup
    {
        $kept = [];
        $relation_prefix = $relation . '.';

        foreach ($filters->filters as $subfilter) {
            if ($subfilter instanceof FiltersGroup) {
                $nested = $this->extractFiltersForRelation($main_model, $subfilter, $relation);

                if ($nested instanceof FiltersGroup && $nested->filters !== []) {
                    $kept[] = $nested;
                }

                continue;
            }

            if (! $subfilter instanceof Filter) {
                continue;
            }

            $path_length = mb_substr_count($subfilter->property, '.');

            if ($path_length < 1) {
                continue;
            }

            $splitted = $this->splitProperty($main_model, $subfilter->property);

            if ($splitted['relation'] === $relation) {
                $kept[] = new Filter($splitted['field'] ?? $subfilter->property, $subfilter->value, $subfilter->operator);

                continue;
            }

            if (! Str::startsWith($splitted['relation'], $relation_prefix)) {
                continue;
            }

            // Nested relation filter, propagate it to the parent eager load too.
            // Example: "roles.permissions.name" becomes "permissions.name" for the "roles" relation callback.
            $nested_relation = Str::after($splitted['relation'], $relation_prefix);
            $nested_property = $nested_relation === '' ? (string) $splitted['field'] : $nested_relation . '.' . $splitted['field'];
            $kept[] = new Filter($nested_property, $subfilter->value, $subfilter->operator);
        }

        if ($kept === []) {
            return null;
        }

        return new FiltersGroup($kept, $filters->operator);
    }

    /**
     * The requested columns with the attributes the main model mounts from its relation turned into columns of
     * that relation: a client asks for `name`, not for `counterparty.name`, and does not know where it lives.
     *
     * @param  array<int,Column>  $columns
     * @return array<int,Column>
     */
    private function requestColumns(Model $main_model, array $columns): array
    {
        if (! $main_model instanceof IMountsRelatedAttributes) {
            return $columns;
        }

        $main_entity = $main_model->getTable();

        return array_map(function (Column $column) use ($main_model, $main_entity): Column {
            if ($column->type !== ColumnType::Column || preg_match('/^\w+\.(\w+)$/', $column->name, $matches) !== 1 || ! str_starts_with($column->name, $main_entity . '.')) {
                return $column;
            }

            $mounted = $this->mountedProperty($main_model, $matches[1]);

            return $mounted === $matches[1] ? $column : new Column($main_entity . '.' . $mounted, $column->type);
        }, $columns);
    }

    /**
     * `name` becomes `counterparty.name` when the model mounts `name` from its relation and the related table
     * has a column of that name; any other property is returned as it is.
     */
    private function mountedProperty(Model $model, string $property): string
    {
        if (! $model instanceof IMountsRelatedAttributes || Str::contains($property, '.') || ModelPropertyResolver::resolve($model, $property) !== ModelPropertyResolver::MOUNTED) {
            return $property;
        }

        $relation = RelationGuard::relationOf($model, $model->mountedRelation());

        if (! $relation instanceof Relation || ! SchemaInspector::getInstance()->hasColumn($property, $relation->getRelated()->getTable(), $relation->getRelated()->getConnectionName())) {
            return $property;
        }

        return $model->mountedRelation() . '.' . $property;
    }

    /**
     * Orders the rows of the main query by a column of a relation that holds at most one record, through a
     * correlated subquery: `counterparty.name` sorts the parties, not the counterparties a party loads. A
     * to-many relation keeps its meaning (the order of the loaded related records), and a column the related
     * table does not have is ignored. The caller needs the right to read the related entity.
     *
     * @param  Builder<Model>  $query
     * @param  'asc'|'desc'  $direction
     *
     * @throws AuthorizationException when the caller may not read the related entity
     */
    private function orderByToOneRelation(Builder $query, string $property, string $direction, ?RelationAuthorizer $authorizer, ?Request $request): bool
    {
        if (mb_substr_count($property, '.') !== 1) {
            return false;
        }

        [$relation_name, $column] = explode('.', $property);
        $relation = RelationGuard::relationOf($query->getModel(), $relation_name);

        if (! $relation instanceof BelongsTo && ! $relation instanceof HasOne) {
            return false;
        }

        $related = $relation->getRelated();

        if (! SchemaInspector::getInstance()->hasColumn($column, $related->getTable(), $related->getConnectionName())) {
            return false;
        }

        if ($authorizer instanceof RelationAuthorizer && $request instanceof Request) {
            throw_unless(
                $authorizer->isReadable($request, $relation->getParent(), $related),
                AuthorizationException::class,
                sprintf('User not allowed to read %s through %s', $related->getTable(), $relation->getParent()->getTable()),
            );
        }

        $query->orderBy(
            $relation->getRelationExistenceQuery($related->newQueryWithoutScopes(), $query, [$related->qualifyColumn($column)]),
            $direction,
        );

        return true;
    }

    /**
     * @return list<string>
     */
    private function splitColumnNameOnLastDot(string $name): array
    {
        $parts = preg_split('/\.(?=[^.]*$)/', $name, 2);

        if (! is_array($parts) || $parts === []) {
            return [$name];
        }

        return $parts;
    }

    /**
     * @param  array<int,Column>  $columns_filters
     * @return array{main:array<Column>,relations:array<string,array<Column>>,aggregates:array<string,array<Column>>}
     */
    private function groupColumns(string &$mainEntity, array $columns_filters): array
    {
        $columns = [
            'main' => [],
            'relations' => [],
            'aggregates' => [],
        ];

        if ($columns_filters !== []) {
            /** @var array<int,string> $all_relations_names */
            $all_relations_names = [];

            foreach ($columns_filters as $column) {
                $index = str_replace($mainEntity . '.', '', $column->name);

                if (preg_match("/^\w+\.\w+$/", $column->name) && $column->type === ColumnType::Column) {
                    $columns['main'][] = new Column($index, $column->type);
                } else {
                    $splitted = $this->splitColumnNameOnLastDot($index);

                    if (! isset($splitted[1])) {
                        $splitted[1] = '*';
                    }

                    if ($column->type === ColumnType::Column) {
                        $remapped_column = new Column($splitted[1], $column->type);

                        if (! in_array($splitted[0], $all_relations_names, true)) {
                            $columns['relations'][$splitted[0]] = [$remapped_column];
                            $all_relations_names[] = $splitted[0];
                        } else {
                            $columns['relations'][$splitted[0]][] = $remapped_column;
                        }
                    } elseif ($column->type->isAggregateColumn()) {
                        $cloned_column = new Column($splitted[1], $column->type);

                        if (! array_key_exists($splitted[0], $columns['aggregates'])) {
                            $columns['aggregates'][$splitted[0]] = [$cloned_column];
                        } else {
                            $columns['aggregates'][$splitted[0]][] = $cloned_column;
                        }
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * The relation part of each group_by key (`relation.column` gives `relation`).
     *
     * @return list<string>
     */
    private function groupByRelations(SelectRequestData $request_data): array
    {
        if (! $request_data instanceof ListRequestData) {
            return [];
        }

        $relations = [];

        foreach ($request_data->group_by as $group) {
            if (Str::contains($group, '.')) {
                $relations[] = Str::beforeLast($group, '.');
            }
        }

        return array_values(array_unique($relations));
    }

    /**
     * Drops the paths that load a black-listed relation (history, tree walks) at any depth.
     *
     * @param  array<int,string>  $relations
     */
    private function cleanRelations(array &$relations): void
    {
        $relations = array_values(array_filter(
            $relations,
            static fn (string $relation): bool => ! self::isBlackListedPath($relation),
        ));
    }

    /**
     * The relation paths named by the filter properties of a request, including a property that is itself a
     * relation (a relation-count filter).
     *
     * @return list<string>
     */
    private function filterRelationPaths(Model $model, FiltersGroup $filters): array
    {
        $paths = [];

        foreach ($filters->filters as $filter) {
            if ($filter instanceof FiltersGroup) {
                array_push($paths, ...$this->filterRelationPaths($model, $filter));

                continue;
            }

            if (! $filter instanceof Filter || ! Str::contains($filter->property, '.')) {
                continue;
            }

            $exploded = explode('.', $filter->property);

            if ($exploded[0] === $model->getTable()) {
                array_shift($exploded);
            }

            $field = (string) array_pop($exploded);

            if ($exploded !== []) {
                $paths[] = implode('.', $exploded);
            }

            $owner = $exploded === [] ? $model : $this->relatedModelAt($model, $exploded);

            if ($owner instanceof Model && $field !== '' && RelationGuard::relationOf($owner, $field) instanceof Relation) {
                $paths[] = $exploded === [] ? $field : implode('.', $exploded) . '.' . $field;
            }
        }

        return $paths;
    }

    /**
     * Checks every name of a relation path from the model: each must be a relation, and a morph relation can only
     * end a path, since what follows it depends on each row's type and cannot be checked.
     *
     * @throws InvalidArgumentException when a name is not a relation or follows a morph relation
     */
    private function assertRelationPath(Model $model, string $path): string
    {
        $this->relationChain($model, $path);

        return $path;
    }

    /**
     * The longest leading part of a dotted property that resolves to relations from the model, '' when none does.
     */
    private function resolvableRelationPrefix(Model $model, string $path): string
    {
        $resolved = [];
        $current = $model;

        foreach (explode('.', $path) as $name) {
            $relation = RelationGuard::relationOf($current, $name);

            if (! $relation instanceof Relation) {
                break;
            }

            $resolved[] = $name;

            if ($relation instanceof MorphTo) {
                break;
            }

            $current = $relation->getRelated();
        }

        return implode('.', $resolved);
    }

    /**
     * The model at the end of a relation path, or null when a name on it is not a relation or a morph relation
     * stands in the way.
     *
     * @param  list<string>  $names
     */
    private function relatedModelAt(Model $model, array $names): ?Model
    {
        $current = $model;

        foreach ($names as $name) {
            $relation = RelationGuard::relationOf($current, $name);

            if (! $relation instanceof Relation || $relation instanceof MorphTo) {
                return null;
            }

            $current = $relation->getRelated();
        }

        return $current;
    }

    /**
     * Each relation of a dotted path from the model, every name checked by {@see RelationGuard} before it is
     * called.
     *
     *
     * @throws InvalidArgumentException when a name is not a relation or follows a morph relation
     *
     * @return list<array{name: string, relation: Relation<Model, Model, mixed>}>
     */
    private function relationChain(Model $model, string $path): array
    {
        $chain = [];
        $current = $model;
        $names = explode('.', $path);

        foreach ($names as $index => $name) {
            $relation = RelationGuard::relationOf($current, $name);

            throw_unless($relation instanceof Relation, InvalidArgumentException::class, sprintf('"%s" is not a relation of %s.', $name, $current::class));
            throw_if(
                $relation instanceof MorphTo && $index < count($names) - 1,
                InvalidArgumentException::class,
                sprintf('"%s" follows the morph relation "%s"; a morph relation can only end a relation path.', $names[$index + 1] ?? '', $name),
            );

            $chain[] = ['name' => $name, 'relation' => $relation];
            $current = $relation->getRelated();
        }

        return $chain;
    }

    /**
     * Whether the caller may select every entity along a relation chain; a morph relation is checked per type
     * when it loads.
     *
     * @param  list<array{name: string, relation: Relation<Model, Model, mixed>}>  $chain
     */
    private function chainIsReadable(array $chain, RelationAuthorizer $authorizer, Request $request): bool
    {
        foreach ($chain as $link) {
            $relation = $link['relation'];

            if ($relation instanceof MorphTo) {
                continue;
            }

            if (! $authorizer->isReadable($request, $relation->getParent(), $relation->getRelated())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Applies the rule to the relations a query loads on its own (a model's `$with`, a dependency added on the
     * way): each is dropped when the caller may not select its entity, and narrowed by the related ACL otherwise.
     * The relations in `$known` were added by the request and carry their own check. A name that does not
     * resolve to a relation is dropped: it cannot be checked, and a relation method needs a declared return type
     * to be recognised (see {@see RelationGuard}).
     *
     * @param  Builder<Model>  $builder
     * @param  list<string>  $known
     */
    private function guardDefaultEagerLoads(Builder $builder, RelationAuthorizer $authorizer, Request $request, array $known): void
    {
        $model = $builder->getModel();
        $drop = [];
        $wrapped = [];

        // The relations the appended attributes read are loaded once for the result set, here, so the attributes
        // do not load them row by row; each then goes through the same rule below as any default eager load.
        if ($model instanceof IPreloadsAppendedRelations) {
            foreach ($model->appendedAttributeRelations() as $appended_relation) {
                if (! array_key_exists($appended_relation, $builder->getEagerLoads())) {
                    $builder->with($appended_relation);
                }
            }
        }

        foreach ($builder->getEagerLoads() as $name => $constraint) {
            if ($this->isKnownRelationPath($name, $known)) {
                continue;
            }

            // A name that does not resolve to a relation (an untyped relation method, a path through a morph
            // relation) cannot be checked, so it is not loaded: unchecked, it would load unguarded.
            try {
                $chain = $this->relationChain($model, $name);
            } catch (InvalidArgumentException) {
                $drop[] = $name;

                continue;
            }

            if (! $this->chainIsReadable($chain, $authorizer, $request)) {
                $drop[] = $name;

                continue;
            }

            $wrapped[$name] = function (Relation $relation) use ($constraint, $authorizer, $request): mixed {
                $result = $constraint($relation);
                $this->guardRelationQuery($relation, $authorizer, $request, [], false);

                return $result;
            };
        }

        if ($drop !== []) {
            $builder->without($drop);
            $wrapped = array_filter(
                $wrapped,
                static fn (string $name): bool => array_all($drop, static fn (string $dropped): bool => ! Str::startsWith($name, $dropped . '.')),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($wrapped !== []) {
            $builder->with($wrapped);
        }
    }

    /**
     * Whether a relation path is one of the given paths, or leads to one of them.
     *
     * @param  list<string>  $paths
     */
    private function isKnownRelationPath(string $name, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path === $name || Str::startsWith($path, $name . '.') || Str::startsWith($name, $path . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule on one loading relation: its query receives the related ACL (a morph relation is checked per type,
     * refused when asked for), and the relations its model loads on its own are guarded in turn.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     * @param  list<string>  $known  the nested paths the request added under this relation
     */
    private function guardRelationQuery(Relation $relation, ?RelationAuthorizer $authorizer, ?Request $request, array $known, bool $requested): void
    {
        if (! $authorizer instanceof RelationAuthorizer || ! $request instanceof Request) {
            return;
        }

        if ($relation instanceof MorphTo) {
            if ($requested) {
                $authorizer->authorize($request, $relation);
            } else {
                $authorizer->constrainIfReadable($request, $relation);
            }

            return;
        }

        $authorizer->constrain($relation->getQuery(), $relation->getParent());
        $this->guardDefaultEagerLoads($relation->getQuery(), $authorizer, $request, $known);
    }

    /**
     * @param  Builder<Model>|Model  $model
     *
     * @throws InvalidArgumentException when a name of the property path is not a relation of the model it is
     *                                  read on, before that name is ever called
     *
     * @return array{relation:string,connection:string|null,table:string,field:string|null}
     */
    private function splitProperty(Builder|Model $model, string $property): array
    {
        $relation_model = $model instanceof Model ? $model : $model->getModel();

        /** @var array<int,string> $exploded */
        $exploded = explode('.', $property);

        if ($exploded !== [] && $exploded[0] === $relation_model->getTable()) {
            array_shift($exploded);
        }

        $field = array_pop($exploded);
        $relation = implode('.', $exploded);

        foreach ($exploded as $relation_name) {
            $relation_instance = RelationGuard::relationOf($relation_model, $relation_name);

            throw_unless(
                $relation_instance instanceof Relation,
                InvalidArgumentException::class,
                sprintf('"%s" is not a relation of %s.', $relation_name, $relation_model::class),
            );

            $relation_model = $relation_instance->getModel();
        }

        return [
            'relation' => $relation,
            'connection' => $relation_model->getConnection()->getName(),
            'table' => $relation_model->getTable(),
            'field' => is_string($field) ? $field : null,
        ];
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<string,array<int,Column>>  $relation_columns
     */
    private function applyFilter(Builder|Relation $query, Filter $filter, string $method, array &$relation_columns, ?RelationAuthorizer $authorizer = null, ?Request $request = null): void
    {
        $path_length = mb_substr_count($filter->property, '.');

        if ($path_length >= 1) {
            $splitted = $this->splitProperty($query->getModel(), $filter->property);
            $query_model = $query->getModel();
            $field = $splitted['field'];

            if ($field !== null && $splitted['relation'] === '' && RelationGuard::relationOf($query_model, $field) instanceof Relation) {
                $this->applyRelationCount($query, $field, $filter, Str::startsWith($method, 'or') ? 'or' : 'and', $authorizer);

                return;
            }

            if ($splitted['relation'] !== '') {
                $has_method = $method . 'Has';

                if (is_callable([$query, $has_method])) {
                    $this->whereHasPath(
                        $query,
                        $has_method,
                        explode('.', $splitted['relation']),
                        function (Builder $q) use ($filter, $splitted, &$relation_columns, $authorizer, $request): void {
                            $q->withoutGlobalScope('global_ordered');

                            if ($splitted['field'] === 'deleted_at' && $this->mayReadTrashed($q->getModel(), $authorizer, $request)) {
                                $q->withoutGlobalScope(CustomSoftDeletingScope::class);
                            }

                            $filter_field = $splitted['field'] ?? $filter->property;

                            // A relation at the end of the path is a count condition on it, as on the main model.
                            if (RelationGuard::relationOf($q->getModel(), $filter_field) instanceof Relation) {
                                $this->applyRelationCount($q, $filter_field, $filter, 'and', $authorizer);

                                return;
                            }

                            $cloned_filter = new Filter($filter_field, $filter->value, $filter->operator);
                            // Inside relation subquery we must not propagate `orWhere`,
                            // otherwise the relation constraints can be bypassed.
                            $this->applyFilter($q, $cloned_filter, 'where', $relation_columns, $authorizer, $request);
                        },
                        $authorizer,
                    );
                }

                return;
            }
        }

        $stored_as = ModelPropertyResolver::resolve($query->getModel(), $filter->property);

        if ($stored_as !== null) {
            $this->applyStoredPropertyFilter($query, $filter, $method, $stored_as, $relation_columns, $authorizer, $request);

            return;
        }

        if ($filter->value === null) {
            $null_method = $filter->operator === FilterOperator::Equals ? 'Null' : 'NotNull';
            $final_method = $method . $null_method;
            $query->{$final_method}($filter->property);

            return;
        }

        if ($filter->operator === FilterOperator::In) {
            $in_method = $method === 'orWhere' ? 'orWhereIn' : 'whereIn';
            $query->{$in_method}($filter->property, Arr::wrap($filter->value));

            return;
        }

        if ($filter->operator === FilterOperator::Between && is_array($filter->value)) {
            $between_method = $method === 'orWhere' ? 'orWhereBetween' : 'whereBetween';
            $query->{$between_method}($filter->property, $filter->value);

            return;
        }

        if (in_array($filter->operator, [FilterOperator::Like, FilterOperator::NotLike], true)) {
            $final_method = $method . Str::studly($filter->operator->value);
            $query->{$final_method}($filter->property, $filter->value);

            return;
        }

        if ($method !== '' && is_callable([$query, $method])) {
            $query->{$method}($filter->property, $filter->operator->value, $filter->value);
        }
    }

    /**
     * A filter on a property that is not a column: a translated field is matched on the translation of
     * the request's locale, a dynamic field in every JSON container that can hold it, since nothing says
     * whether a given field was configured as translatable or shared, and a mounted attribute on the
     * relation the model mounts it from.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<string,array<int,Column>>  $relation_columns
     * @param  ModelPropertyResolver::TRANSLATED|ModelPropertyResolver::DYNAMIC|ModelPropertyResolver::MOUNTED  $stored_as
     */
    private function applyStoredPropertyFilter(Builder|Relation $query, Filter $filter, string $method, string $stored_as, array &$relation_columns, ?RelationAuthorizer $authorizer, ?Request $request): void
    {
        $model = $query->getModel();

        if ($stored_as === ModelPropertyResolver::MOUNTED && $model instanceof IMountsRelatedAttributes) {
            $mounted_filter = new Filter($model->mountedRelation() . '.' . $filter->property, $filter->value, $filter->operator);
            $this->applyFilter($query, $mounted_filter, $method, $relation_columns, $authorizer, $request);

            return;
        }

        if ($stored_as === ModelPropertyResolver::TRANSLATED) {
            $this->whereTranslation(
                $query,
                $method,
                function (Builder $translation_query) use ($filter, &$relation_columns, $authorizer, $request): void {
                    $this->applyFilter($translation_query, $filter, 'where', $relation_columns, $authorizer, $request);
                },
            );

            return;
        }

        $translation_container = ModelPropertyResolver::translationContainer($model);
        $query->{$method}(function (Builder $group) use ($model, $filter, $translation_container, &$relation_columns, $authorizer, $request): void {
            foreach (ModelPropertyResolver::mainContainers($model) as $container) {
                $json_filter = new Filter($container . '->' . $filter->property, $filter->value, $filter->operator);
                $this->applyFilter($group, $json_filter, 'orWhere', $relation_columns, $authorizer, $request);
            }

            if ($translation_container !== null) {
                $json_filter = new Filter($translation_container . '->' . $filter->property, $filter->value, $filter->operator);
                $this->whereTranslation(
                    $group,
                    'orWhere',
                    function (Builder $translation_query) use ($json_filter, &$relation_columns, $authorizer, $request): void {
                        $this->applyFilter($translation_query, $json_filter, 'where', $relation_columns, $authorizer, $request);
                    },
                );
            }
        });
    }

    /**
     * Constrain a query by the translation of the request locale. No fallback to another locale: the
     * application keeps a single locale setting, so a model never has a distinct default to fall back to
     * while a request runs, and a record without a translation in the request locale is simply not matched.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  Closure(Builder<Model>): void  $constraint
     */
    private function whereTranslation(Builder|Relation $query, string $method, Closure $constraint): void
    {
        $locale = LocaleContext::get();

        $query->{$method . 'Has'}('translations', function (Builder $translation_query) use ($constraint, $locale): void {
            $translation_query->getQuery()->where('locale', '=', $locale);
            $constraint($translation_query);
        });
    }

    /**
     * A filter on a relation itself: the rows with as many related records as the value says (1 when it is not
     * a number), counting only the related records the caller may see.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     */
    private function applyRelationCount(Builder|Relation $query, string $relation, Filter $filter, string $boolean, ?RelationAuthorizer $authorizer): void
    {
        $owner = $query->getModel();
        $has_count = is_int($filter->value)
            ? $filter->value
            : (is_numeric($filter->value) ? (int) $filter->value : 1);

        $query->has(
            $relation,
            $filter->operator->value,
            $has_count,
            $boolean,
            static function (Builder $q) use ($authorizer, $owner): void {
                $q->withoutGlobalScope('global_ordered');
                $authorizer?->constrain($q, $owner);
            },
        );
    }

    /**
     * An existence condition along a relation path, one `whereHas` per relation so that each related query
     * receives its own ACL; the leaf callback constrains the last one.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  list<string>  $segments
     * @param  Closure(Builder<Model>): void  $leaf
     */
    private function whereHasPath(Builder|Relation $query, string $has_method, array $segments, Closure $leaf, ?RelationAuthorizer $authorizer): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        $owner = $query->getModel();

        $query->{$has_method}($segment, function (Builder $related) use ($segments, $leaf, $authorizer, $owner): void {
            $authorizer?->constrain($related, $owner);

            if ($segments === []) {
                $leaf($related);

                return;
            }

            $this->whereHasPath($related, 'whereHas', $segments, $leaf, $authorizer);
        });
    }

    /**
     * Whether a filter on the related `deleted_at` may reach the trashed related records: only for a caller who
     * holds the related delete permission on the request's guard, within the abilities of the token. Without an
     * acting user nobody is asking, and the soft-delete scope stays.
     */
    private function mayReadTrashed(Model $related, ?RelationAuthorizer $authorizer, ?Request $request): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($related), true) || Auth::user() === null) {
            return false;
        }

        $authorizer ??= resolve(RelationAuthorizer::class);

        if (! $request instanceof Request) {
            // A caller without a request of its own (a direct use of the query builder): the acting user is
            // the authenticated one, whatever the current request object resolves.
            $request = request()->duplicate();
            $request->setUserResolver(static fn (): ?Authenticatable => Auth::user());
        }

        return $authorizer->allows($request, $related, ActionEnum::Delete->value);
    }

    /**
     * A relation filter from an ACL: the rows whose related record matches the nested filters, through
     * `whereHas` or, for a morph relation, `whereHasMorph`. Applied by relation, never by property path, so
     * the nested filters keep their plain column names.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<string,array<int,Column>>  $relation_columns
     */
    private function applyRelationFilter(Builder|Relation $query, RelationFilter $filter, string $method, array &$relation_columns, ?RelationAuthorizer $authorizer = null, ?Request $request = null): void
    {
        RelationGuard::assertFilterable($query->getModel(), $filter);

        $has_method = $method . ($filter->morph_types !== null ? 'HasMorph' : 'Has');
        $constraint = function (Builder $q) use ($filter, &$relation_columns, $authorizer, $request): void {
            $this->recursivelyApplyFilters($q, $filter->filters, $relation_columns, $authorizer, $request);
        };

        if ($filter->morph_types !== null) {
            $query->{$has_method}($filter->relation, $filter->morph_types, $constraint);

            return;
        }

        $query->{$has_method}($filter->relation, $constraint);
    }

    /**
     * Applies a filter tree to the query: the first node of an OR group joins with `where`, the others with
     * `orWhere`. A scope node runs in its own nested where ({@see ScopeGuard::apply()}) against the model of the
     * query it reaches, the related model inside a relation filter. Every scope node is applied as an ACL's would be: a
     * request's scope nodes were gated when the request was parsed ({@see ListRequestData}), the only client gate.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  FiltersGroup|array<int, Filter|FiltersGroup|RelationFilter|ScopeFilter>  $filters
     * @param  array<string,array<int,Column>>  $relation_columns
     *
     * @throws InvalidArgumentException when a node cannot be applied (a scope refusal is a {@see ScopeFilterException})
     */
    private function recursivelyApplyFilters(Builder|Relation $query, FiltersGroup|array $filters, array &$relation_columns, ?RelationAuthorizer $authorizer = null, ?Request $request = null): void
    {
        if ($filters instanceof FiltersGroup) {
            $iterable = $filters->filters;
            $operator = $filters->operator;
        } else {
            $iterable = $filters;
            $operator = WhereClause::And;
        }

        $is_or = $operator === WhereClause::Or;
        $first = true;

        foreach ($iterable as $subfilter) {
            $method = $is_or && $first ? 'where' : ($is_or ? 'orWhere' : 'where');

            if ($subfilter instanceof FiltersGroup) {
                if (is_callable([$query, $method])) {
                    $query->{$method}(fn (Builder $q) => $this->recursivelyApplyFilters($q, $subfilter, $relation_columns, $authorizer, $request));
                }
            } elseif ($subfilter instanceof RelationFilter) {
                $this->applyRelationFilter($query, $subfilter, $method, $relation_columns, $authorizer, $request);
            } elseif ($subfilter instanceof ScopeFilter) {
                ScopeGuard::apply($query instanceof Relation ? $query->getQuery() : $query, $subfilter, $method === 'orWhere' ? 'or' : 'and');
            } elseif ($subfilter instanceof Filter) {
                $this->applyFilter($query, $subfilter, $method, $relation_columns, $authorizer, $request);
            } else {
                // A node skipped here would drop its condition and widen the rows: refused instead (fail closed).
                throw new InvalidArgumentException(sprintf('The CRUD query builder cannot apply a %s filter node.', class_basename($subfilter)));
            }

            $first = false;
        }
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<int,Column>  $columns
     */
    private function sortColumns(Builder|Relation $query, array &$columns): void
    {
        usort($columns, fn (Column $a, Column $b): int => $a->name <=> $b->name);

        $all_columns_name = [];

        foreach ($columns as $column) {
            $all_columns_name[] = $column->name;
        }

        $primary_key = Arr::wrap($query->getModel()->getKeyName());

        foreach ($primary_key as $key) {
            if (! in_array($key, $all_columns_name, true)) {
                array_unshift($columns, new Column($key, ColumnType::Column));
                $all_columns_name[] = $key;
            }
        }
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<int,Column>  $relation_columns
     */
    private function applyColumnsToSelect(Builder|Relation $query, array &$relation_columns): void
    {
        $this->sortColumns($query, $relation_columns);
        $simple_columns = [];

        foreach ($relation_columns as $column) {
            if ($column->type === ColumnType::Column) {
                $simple_columns[] = $column->name;
            }
        }

        if ($simple_columns === []) {
            return;
        }

        $query->select($simple_columns);
    }

    /**
     * Apply main-model relation aggregates — the "dotless" aggregate keys such as
     * `contents` (a direct relation of the main model) → withCount('contents') → a
     * `contents_count` attribute on each row. Runs regardless of whether other
     * relations are eager-loaded (dotted keys stay scoped to their loaded relation
     * and are consumed by {@see applyAggregatesToQuery()}). Consumed keys are removed
     * so the relation pass does not see them again.
     *
     * Given a relation authorizer, an aggregate is refused when the caller may not select the related entity,
     * and counts only the related rows its ACL allows.
     *
     * @param  Builder<Model>  $query
     * @param  array<string,array<int,Column>>  $aggregates
     * @param  (callable(string, Builder<Model>): void)|null  $countConstraint
     *
     * @throws InvalidArgumentException when a dotless key is not an Eloquent relation
     * @throws AuthorizationException when the caller may not select the related entity
     */
    private function applyMainAggregates(Builder $query, array &$aggregates, ?callable $countConstraint = null, ?RelationAuthorizer $authorizer = null, ?Request $request = null): void
    {
        $model = $query->getModel();

        foreach ($aggregates as $relation => $aggregates_cols) {
            if (Str::contains($relation, '.')) {
                continue;
            }

            $instance = $this->assertAggregateRelation($model, $relation);
            $this->authorizeAggregate($instance, $authorizer, $request);

            $constraint = $countConstraint === null && ! $authorizer instanceof RelationAuthorizer
                ? null
                : static function (Builder $subquery) use ($countConstraint, $relation, $authorizer, $model): void {
                    $authorizer?->constrain($subquery, $model);

                    if ($countConstraint !== null) {
                        $countConstraint($relation, $subquery);
                    }
                };

            foreach ($aggregates_cols as $col) {
                $method = $this->resolveAggregateMethod($col->type);

                if ($col->type === ColumnType::Count) {
                    $query->{$method}($constraint === null ? [$relation] : [$relation => $constraint]);

                    continue;
                }

                $query->{$method}($constraint === null ? $relation : [$relation => $constraint], $col->name);
            }

            unset($aggregates[$relation]);
        }
    }

    /**
     * Fail fast when an aggregate targets something that is not a real relation on
     * the model, turning an opaque "undefined method" into a clear message. The name
     * is checked by {@see RelationGuard} before it is ever called.
     *
     * @return Relation<Model, Model, mixed>
     */
    private function assertAggregateRelation(Model $model, string $relation): Relation
    {
        $instance = RelationGuard::relationOf($model, $relation);

        if (! $instance instanceof Relation) {
            throw new InvalidArgumentException(sprintf(
                "Aggregate '%s' is not an Eloquent relation on %s, so it cannot be counted or summed.",
                $relation,
                $model::class,
            ));
        }

        return $instance;
    }

    /**
     * An aggregate is always asked for by the request: refused when the caller may not select the related entity.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     *
     * @throws AuthorizationException when the caller may not select the related entity
     */
    private function authorizeAggregate(Relation $relation, ?RelationAuthorizer $authorizer, ?Request $request): void
    {
        if (! $authorizer instanceof RelationAuthorizer || ! $request instanceof Request || $relation instanceof MorphTo) {
            return;
        }

        throw_unless(
            $authorizer->isReadable($request, $relation->getParent(), $relation->getRelated()),
            AuthorizationException::class,
            sprintf('User not allowed to read %s through %s', $relation->getRelated()->getTable(), $relation->getParent()->getTable()),
        );
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<string,array<int,Column>>  $relations_aggregates
     */
    private function applyAggregatesToQuery(Builder|Relation $query, array &$relations_aggregates, string $relation, ?RelationAuthorizer $authorizer = null, ?Request $request = null): void
    {
        $owner = $query->getModel();

        foreach ($relations_aggregates as $aggregate_relation => $aggregates_cols) {
            $escaped = preg_quote($relation);

            if (preg_match('/^' . $escaped . '\.\w+$/', $aggregate_relation) !== 1) {
                continue;
            }

            $subrelation = (string) preg_replace('/^' . $escaped . '\./', '', $aggregate_relation);
            $this->authorizeAggregate($this->assertAggregateRelation($owner, $subrelation), $authorizer, $request);
            $constraint = $authorizer instanceof RelationAuthorizer
                ? static function (Builder $subquery) use ($authorizer, $owner): void {
                    $authorizer->constrain($subquery, $owner);
                }
            : null;

            foreach ($aggregates_cols as $col) {
                $method = $this->resolveAggregateMethod($col->type);

                if ($col->type === ColumnType::Count) {
                    $query->{$method}($constraint === null ? [$subrelation] : [$subrelation => $constraint]);

                    continue;
                }

                $query->{$method}($constraint === null ? $subrelation : [$subrelation => $constraint], $col->name);
            }

            unset($relations_aggregates[$aggregate_relation]);
        }
    }

    /**
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  array<int, Column|string>  $selectColumns
     */
    private function addForeignKeysToSelectedColumns(Builder|Relation $query, array &$selectColumns, ?Model $model = null, ?string $table = null, bool $as_columns = false): void
    {
        if (! $model instanceof Model) {
            $model = $query->getModel();
        }

        $table ??= $model->getTable();

        $existing_columns = [];

        foreach ($selectColumns as $select_column) {
            $existing_columns[] = $select_column instanceof Column ? $select_column->name : $select_column;
        }

        foreach (SchemaInspector::getInstance()->foreignKeys($table, $model->getConnection()->getName()) as $foreign) {
            foreach ($foreign->columns as $column) {
                if (in_array($column, $existing_columns, true)) {
                    continue;
                }

                $selectColumns[] = $as_columns ? new Column($column) : $column;
                $existing_columns[] = $column;
            }
        }
    }

    /**
     * @param  Relation<Model, Model, mixed>  $query
     * @param  array<int, Column>  $relation_columns
     */
    private function addForeignKeysToRelationColumns(Relation $query, array &$relation_columns, ?Model $model = null): void
    {
        if (! $model instanceof Model) {
            $model = $query->getModel();
        }

        $table = $model->getTable();
        $existing_columns = array_map(static fn (Column $column): string => $column->name, $relation_columns);

        foreach (SchemaInspector::getInstance()->foreignKeys($table, $model->getConnection()->getName()) as $foreign) {
            foreach ($foreign->columns as $column) {
                if (in_array($column, $existing_columns, true)) {
                    continue;
                }

                $relation_columns[] = new Column($column);
                $existing_columns[] = $column;
            }
        }
    }

    /**
     * @param  Relation<Model, Model, mixed>  $query
     * @param  array<string,array<int,Column>>  $relations_columns
     * @param  array<string,array<int,Sort>>  $relations_sorts
     * @param  array<string,array<int,Column>>  $relations_aggregates
     * @param  array<string,FiltersGroup>  $relations_filters
     * @param  array<string,array{append:array<int,string>,method:array<int,string>}>  $computed_relations
     */
    private function createRelationCallback(Relation $query, string $relation, array &$relations_columns, array &$relations_sorts, array &$relations_aggregates, array &$relations_filters, array $computed_relations, ?RelationAuthorizer $authorizer = null, ?Request $request = null): void
    {
        $computed = $computed_relations[$relation] ?? ['append' => [], 'method' => []];
        $computed_dependencies = $this->resolveComputedDependencies($query->getModel(), $computed);
        $force_select_all = $computed_dependencies['force_select_all'];

        if ($computed['append'] !== []) {
            $this->applyModelAppends($query->getModel(), $computed['append']);
        }

        if ($computed_dependencies['relations'] !== []) {
            $query->with($computed_dependencies['relations']);
        }

        if (! $force_select_all && $computed_dependencies['columns'] !== []) {
            $this->mergeComputedDependencies($relations_columns, $relation, $computed_dependencies['columns']);
        } elseif ($force_select_all) {
            unset($relations_columns[$relation]);
        }

        if (array_key_exists($relation, $relations_columns) && $relations_columns[$relation] !== []) {
            $this->addForeignKeysToRelationColumns($query, $relations_columns[$relation]);
            $this->applyColumnsToSelect($query, $relations_columns[$relation]);
        }

        $this->applyAggregatesToQuery($query, $relations_aggregates, $relation, $authorizer, $request);

        if (isset($relations_filters[$relation])) {
            if (! array_key_exists($relation, $relations_columns)) {
                $relations_columns[$relation] = [];
            }

            // One nested condition beside the relation's own constraint (the parent keys) and the related ACL,
            // so a root OR group of the relation filters cannot escape them.
            $query->where(function (Builder $nested) use ($relations_filters, $relation, &$relations_columns, $authorizer, $request): void {
                $this->recursivelyApplyFilters($nested, $relations_filters[$relation], $relations_columns, $authorizer, $request);
            });
        }

        if (array_key_exists($relation, $relations_sorts) && $relations_sorts[$relation] !== []) {
            foreach ($relations_sorts[$relation] as $sort) {
                $query->orderBy($sort->property, $sort->direction->value);
            }
        }
    }

    /**
     * Eager-loads the relation paths. Every name is checked by {@see RelationGuard} before Eloquent calls it.
     * Given a relation authorizer, a path the request asked for is refused when the caller may not select an
     * entity on it and a path the query added on its own is dropped instead; each relation of each path, the
     * intermediate ones included, receives the related ACL.
     *
     * @param  Builder<Model>  $query
     * @param  array<int,string>  $relations
     * @param  array<string,array<int,Column>>  $relations_columns
     * @param  array<string,array<int,Sort>>  $relations_sorts
     * @param  array<string,array<int,Column>>  $relations_aggregates
     * @param  array<string,FiltersGroup>  $relations_filters
     * @param  array<string,array{append:array<int,string>,method:array<int,string>}>  $computed_relations
     * @param  array<int,string>  $requested  the paths the request asked for
     *
     * @throws InvalidArgumentException when a name is not a relation
     * @throws AuthorizationException when the caller may not select an entity on a requested path
     */
    private function applyRelations(Builder $query, array $relations, array &$relations_columns, array &$relations_sorts, array &$relations_aggregates, array &$relations_filters, array $computed_relations, ?RelationAuthorizer $authorizer = null, ?Request $request = null, array $requested = []): void
    {
        $relations = $this->normalizeRelations($relations);
        $merged_relations = array_values(array_unique(array_merge($relations, array_keys($relations_sorts), array_keys($relations_columns))));
        $this->cleanRelations($merged_relations);
        $requested = array_merge($requested, array_keys($relations_sorts), array_keys($relations_columns));
        $root = $query->getModel();
        $loaded = [];

        foreach ($merged_relations as $relation) {
            $chain = $this->relationChain($root, $relation);

            if ($authorizer instanceof RelationAuthorizer && $request instanceof Request && ! $this->chainIsReadable($chain, $authorizer, $request)) {
                throw_if(
                    in_array($relation, $requested, true),
                    AuthorizationException::class,
                    sprintf('User not allowed to read the relation %s of %s', $relation, $root->getTable()),
                );

                continue;
            }

            $loaded[] = $relation;
        }

        $withs = [];

        foreach ($loaded as $relation) {
            $is_requested = $this->isKnownRelationPath($relation, $requested);
            $nested = $this->nestedRelationPaths($relation, $loaded);

            // Each intermediate relation of the path gets its own callback, so that it receives its ACL too:
            // Eloquent would otherwise load it without constraints.
            $prefix = '';

            foreach (explode('.', $relation) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix . '.' . $segment;

                if ($prefix === $relation || isset($withs[$prefix]) || in_array($prefix, $loaded, true)) {
                    continue;
                }

                $prefix_nested = $this->nestedRelationPaths($prefix, $loaded);
                $withs[$prefix] = function (Relation $q) use ($authorizer, $request, $prefix_nested, $is_requested): mixed {
                    $this->guardRelationQuery($q, $authorizer, $request, $prefix_nested, $is_requested);

                    return null;
                };
            }

            $withs[$relation] = function (Relation $q) use ($relation, $relations_columns, $relations_sorts, $relations_aggregates, $relations_filters, $computed_relations, $authorizer, $request, $nested, $is_requested): mixed {
                $this->createRelationCallback($q, $relation, $relations_columns, $relations_sorts, $relations_aggregates, $relations_filters, $computed_relations, $authorizer, $request);
                $this->guardRelationQuery($q, $authorizer, $request, $nested, $is_requested);

                return null;
            };
        }

        if ($withs !== []) {
            $query->with($withs);
        }
    }

    /**
     * The paths among `$paths` that continue `$relation`, relative to it.
     *
     * @param  array<int,string>  $paths
     * @return list<string>
     */
    private function nestedRelationPaths(string $relation, array $paths): array
    {
        $nested = [];

        foreach ($paths as $path) {
            if (Str::startsWith($path, $relation . '.')) {
                $nested[] = Str::after($path, $relation . '.');
            }
        }

        return $nested;
    }

    private function resolveAggregateMethod(ColumnType $column_type): string
    {
        if ($column_type === ColumnType::Avg) {
            return 'withAvg';
        }

        return 'with' . ucfirst((string) $column_type->value);
    }

    /**
     * @param  array<int,string|array{name:string}>  $relations
     * @return array<int,string>
     */
    private function normalizeRelations(array $relations): array
    {
        $normalized = [];

        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $normalized[] = $relation;

                continue;
            }

            if (is_array($relation) && isset($relation['name'])) {
                $normalized[] = $relation['name'];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<int,Column>  $columns
     * @return array{main:array{append:array<int,string>,method:array<int,string>},relations:array<string,array{append:array<int,string>,method:array<int,string>}>}
     */
    private function extractComputedColumns(string $main_entity, array $columns): array
    {
        $computed = [
            'main' => ['append' => [], 'method' => []],
            'relations' => [],
        ];

        foreach ($columns as $column) {
            if (! in_array($column->type, [ColumnType::Append, ColumnType::Method], true)) {
                continue;
            }

            $index = str_replace($main_entity . '.', '', $column->name);
            $splitted = $this->splitColumnNameOnLastDot($index);
            $relation = $splitted[1] ?? null ? $splitted[0] : '';
            $name = $splitted[1] ?? $splitted[0];
            $bucket = $column->type === ColumnType::Append ? 'append' : 'method';

            if ($relation === '') {
                $computed['main'][$bucket][] = $name;
            } else {
                if (! isset($computed['relations'][$relation])) {
                    $computed['relations'][$relation] = ['append' => [], 'method' => []];
                }

                $computed['relations'][$relation][$bucket][] = $name;
            }
        }

        return $computed;
    }

    /**
     * @param  array{append:array<int,string>,method:array<int,string>}  $computed
     * @return array{columns:array<int,string>,relations:array<int,string>,force_select_all:bool}
     */
    private function resolveComputedDependencies(Model $model, array $computed): array
    {
        $computed_names = array_values(array_unique(array_merge($computed['append'], $computed['method'])));
        $resolved = [
            'columns' => [],
            'relations' => [],
            'force_select_all' => false,
        ];

        if ($computed_names === []) {
            return $resolved;
        }

        if (! method_exists($model, 'crudComputedDependencies')) {
            $resolved['force_select_all'] = true;

            return $resolved;
        }

        /** @var array<string, array{columns?: array<int, string>|string, relations?: array<int, string>}|array<int, string>|string> $dependencies_map */
        $dependencies_map = $model->crudComputedDependencies();
        $table = $model->getTable();

        foreach ($computed_names as $computed_name) {
            $dependency = $dependencies_map[$computed_name] ?? $dependencies_map[$table . '.' . $computed_name] ?? null;

            if ($dependency === null) {
                $resolved['force_select_all'] = true;

                continue;
            }

            if (is_array($dependency)) {
                $dependency_columns = Arr::wrap($dependency['columns'] ?? $dependency);
                $dependency_relations = Arr::wrap($dependency['relations'] ?? []);
            } else {
                $dependency_columns = Arr::wrap($dependency);
                $dependency_relations = [];
            }

            foreach ($dependency_columns as $dependency_column) {
                if (! is_string($dependency_column) && ! is_numeric($dependency_column)) {
                    continue;
                }

                $dependency_column = str_replace($table . '.', '', (string) $dependency_column);

                if (! in_array($dependency_column, $resolved['columns'], true)) {
                    $resolved['columns'][] = $dependency_column;
                }
            }

            foreach ($dependency_relations as $dependency_relation) {
                if (! is_string($dependency_relation) && ! is_numeric($dependency_relation)) {
                    continue;
                }

                $dependency_relation = str_replace($table . '.', '', (string) $dependency_relation);

                if (! in_array($dependency_relation, $resolved['relations'], true)) {
                    $resolved['relations'][] = $dependency_relation;
                }
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string,array<int,Column>>  $relations_columns
     * @param  array<int,string>  $dependency_columns
     */
    private function mergeComputedDependencies(array &$relations_columns, string $relation, array $dependency_columns): void
    {
        if (! array_key_exists($relation, $relations_columns)) {
            $relations_columns[$relation] = [];
        }

        $existing_columns = array_map(static fn (Column $column): string => $column->name, $relations_columns[$relation]);

        foreach ($dependency_columns as $dependency_column) {
            if (in_array($dependency_column, $existing_columns, true)) {
                continue;
            }

            $relations_columns[$relation][] = new Column($dependency_column, ColumnType::Column);
            $existing_columns[] = $dependency_column;
        }
    }

    /**
     * @param  array<int,string>  $appends
     */
    private function applyModelAppends(Model $model, array $appends): void
    {
        if ($appends === []) {
            return;
        }

        $model->append($appends);
    }
}
