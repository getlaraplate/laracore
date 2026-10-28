<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud;

use BadMethodCallException;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes as EloquentSoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Approvals\PendingDeletionStrategy;
use Modules\Core\Authorization\RetrievedSelectGuard;
use Modules\Core\Cache\Repository as CacheRepository;
use Modules\Core\Casts\ColumnType;
use Modules\Core\Casts\CrudRequestData;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\HistoryRequestData;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Casts\SelectRequestData;
use Modules\Core\Casts\TreeRequestData;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\ProvidesDefaultSearchFilters;
use Modules\Core\Contracts\ProvidesFacetLabelSources;
use Modules\Core\Contracts\ProvidesSyncableRelations;
use Modules\Core\Contracts\RestrictsCrudWrites;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Enums\ColumnKind;
use Modules\Core\Enums\TimeBucket;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Exceptions\CrudWriteNotAllowedException;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Locking\Exceptions\LockedModelException;
use Modules\Core\Locking\Locked;
use Modules\Core\Locking\LockIntent;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Disapproval;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Overrides\CustomSoftDeletingScope;
use Modules\Core\Search\Contracts\IAuthorizesSearchRehydration;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Enums\SearchMode as SearchDepth;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\ScoutSearchConstraintApplier;
use Modules\Core\Search\Services\SearchCostGuard;
use Modules\Core\Search\Traits\Searchable;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\Aggregate\AggregateEngine;
use Modules\Core\Services\Crud\Aggregate\AggregateGroup;
use Modules\Core\Services\Crud\Aggregate\AggregateMeasure;
use Modules\Core\Services\Crud\Aggregate\AggregateQuery;
use Modules\Core\Services\Crud\Aggregate\AggregateResult;
use Modules\Core\Services\Crud\Aggregate\AggregateScope;
use Modules\Core\Services\Crud\Concerns\HasCrudOperations;
use Modules\Core\Services\Crud\DTOs\CrudMeta;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Services\Crud\DTOs\FacetLabelSource;
use Modules\Core\Services\Crud\DTOs\FacetPage;
use Modules\Core\Services\Crud\DTOs\FacetQuery;
use Modules\Core\Services\Crud\DTOs\FacetSort;
use Modules\Core\Services\Crud\DTOs\PaginationMode;
use Modules\Core\Services\ModificationVoteService;
use Modules\Core\SoftDeletes\SoftDeletes as CoreSoftDeletes;
use Modules\Core\Support\ColumnKinds;
use Modules\Core\Support\ComputedColumnGuard;
use Modules\Core\Support\PermissionName;
use Modules\Core\Support\ReadableColumnGuard;
use Modules\Core\Support\RelationGuard;
use ReflectionMethod;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnexpectedValueException;

/**
 * CRUD Service - orchestrates CRUD operations with authorization and query building.
 *
 * This service uses:
 * - AuthorizationService: for permission checks and ACL filter injection
 * - QueryBuilder: for preparing Eloquent queries from request data
 *
 * The flow for read operations (list, detail, history, tree):
 * 1. ensurePermission() - verify user has permission for operation
 * 2. injectAclFilters() - inject ACL filters into request data
 * 3. prepareQuery() - build the query (filters now include ACLs)
 * 4. Execute query and return result
 */
class CrudService
{
    /** @phpstan-use HasCrudOperations<Model> */
    use HasCrudOperations;

    /**
     * Memoized "method requires parameters" checks, keyed by `class::method`.
     *
     * The reflection result depends only on the class + method signature, so it
     * is cached to avoid rebuilding a ReflectionMethod for every row when a
     * computed Method column is resolved over a collection.
     *
     * @var array<string, bool>
     */
    private static array $method_requires_parameters_cache = [];

    public function __construct(
        private readonly AuthorizationService $auth,
        private readonly QueryBuilder $query_builder,
        private readonly ?AdvancedSearchService $advanced_search = null,
        private readonly ?ScoutSearchConstraintApplier $search_constraint_applier = null,
        private readonly ?RelationAuthorizer $relation_authorizer = null,
        private readonly ?AggregateEngine $aggregate_engine = null,
    ) {}

    public function list(ListRequestData $requestData): CrudResult
    {
        $model = $requestData->model;

        // 1. Check permission
        $permission_name = $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        // 2. The relations the request names obey their own permission, checked before the ACL
        //    filters join the request filters (an ACL names relations on its own authority).
        $this->authorizeRequestedRelations($requestData);

        // 3. Inject ACL filters into request (filters become: ACL AND user_filters)
        $this->auth->injectAclFilters($requestData, $permission_name);

        // 4. Build query (now includes ACL filters). Loaded relations and relation-count
        //    aggregates get the related entity's ACL, so neither a related row nor a
        //    `*_count` shows what the viewer is not permitted to see.
        $query = $model->newQuery();
        $this->query_builder->prepareAuthorizedQuery($query, $requestData, $this->relationAuthorizer());

        // When the full result set is materialized (no page, no from/to range, no
        // limit cap and not a count-only request), the total equals the number of
        // fetched rows, so the separate COUNT(*) round-trip is redundant.
        $is_full_get = $requestData->page === null
            && $requestData->from === null
            && ! isset($requestData->limit)
            && ! $requestData->count;

        // Paginated request that opted out of the exact total (`totals=false`): skip
        // the COUNT(*) entirely and rely on the over-fetched look-ahead row to report
        // whether a further page exists. Sort-agnostic, so it works for any ordering.
        $look_ahead = $requestData->page !== null
            && ! $requestData->totals
            && ! $requestData->count;

        $total_records = ($is_full_get || $look_ahead) ? 0 : $query->count();

        // The table-level select permission was ensured above and row-level ACL
        // filters are already baked into the query, so the per-row retrieved check is
        // redundant for this model; suppress it just for this class while hydrating.
        $data = RetrievedSelectGuard::without(
            $model::class,
            fn (): Collection|int => match (true) {
                $requestData->page !== null => $this->listByPagination($query, $requestData, $total_records),
                $requestData->from !== null => $this->listByFromTo($query, $requestData, $total_records),
                default => $this->listByOthers($query, $requestData, $total_records),
            },
        );

        if ($is_full_get && $data instanceof Collection) {
            $total_records = $data->count();
        }

        // Look-ahead: an over-fetched extra row proves a further page exists. Record
        // it, then trim back to the requested page size before anything else sees it.
        $has_more = null;

        if ($look_ahead && $data instanceof Collection) {
            $per_page = max(1, $requestData->pagination);
            $has_more = $per_page < $data->count();

            if ($has_more) {
                $data = $data->take($per_page)->values();
            }
        }

        $this->applyComputedMethods($data, $requestData);

        // The number of records in the current page is the count of the fetched rows.
        // It must be captured before grouping, otherwise `applyGroupBy` would collapse
        // the collection into its group buckets and `currentRecords` would report the
        // number of groups instead of the number of records.
        $current_records = is_numeric($data) ? $data : $data->count();

        if ($requestData->group_by !== [] && $data instanceof Collection) {
            $data = $this->applyGroupBy($data, $requestData->group_by);
        }

        // A paginated (`page`) response advertises its counting mode explicitly so the
        // client can pick its footer; non-paginated responses leave it null.
        $mode = match (true) {
            $look_ahead => PaginationMode::Lookahead,
            $requestData->page !== null => PaginationMode::Counted,
            default => null,
        };

        $meta = new CrudMeta(
            totalRecords: $look_ahead ? null : $total_records,
            currentRecords: $current_records,
            currentPage: $requestData->page,
            totalPages: ($requestData->page !== null && ! $look_ahead) ? $requestData->calculateTotalPages($total_records) : null,
            pagination: $requestData->pagination,
            from: $requestData->from,
            to: $requestData->to,
            hasMore: $has_more,
            mode: $mode,
            class: $model::class,
            table: $model->getTable(),
            cachedAt: Date::now(),
        );

        return new CrudResult(
            data: $data,
            meta: $meta,
        );
    }

    /**
     * Per-facet value counts. Every requested column is treated as a facet dimension.
     *
     * For each facet value, `total` is the value distribution ignoring the request
     * filters, while `count` applies them minus the facet's own selection (so a facet
     * stays live as its values are toggled), reusing the list filter machinery. Results
     * are keyed by the facet's bare column name.
     *
     * @return array<string, list<array{value: mixed, total: int, count: int}>>
     */
    public function facetCounts(ListRequestData $base): array
    {
        $model = $base->model;

        $permission_name = $this->auth->ensurePermission(
            $base->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        // The relations the request names (its filters included) obey their own permission, as in a list.
        // The columns of a facet request are facet fields of the main table, not relation paths.
        $this->authorizeRequestedRelations($base, include_columns: false);

        foreach ($base->columns as $column) {
            ReadableColumnGuard::assertReadablePath($model, $this->facetKey($column->name));
        }

        $result = [];

        foreach ($base->columns as $column) {
            // Direct-column scope: the bare column name resolves against the model's
            // own table, regardless of any entity-qualified prefix on the request.
            $field = $this->facetKey($column->name);

            // Real base-table columns count with a portable SQL `GROUP BY` (no rows
            // hydrated into PHP); computed accessors have no column to group on, so
            // they fall back to plucking the values and counting them in memory.
            $is_real_column = $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $field);

            // A field that is not a column is read from each row as an attribute: it must not name a method,
            // which Eloquent would call on the row as if it were a relation.
            if (! $is_real_column) {
                ComputedColumnGuard::assertReadableAttribute($model, $field);
            }

            // Both universes stay within the ACL-visible rows: `total` ignores the
            // request filters but not the row-level ACL, `count` applies both.
            $total_query = $model->newQuery();
            $this->auth->applyAclFiltersToQuery($total_query, $permission_name);
            $totals = $this->columnValueCounts($total_query, $field, $is_real_column);

            $count_query = $model->newQuery();
            $this->auth->applyAclFiltersToQuery($count_query, $permission_name);

            if ($base->filters instanceof FiltersGroup) {
                $relation_columns = [];
                $this->query_builder->applyFilters($count_query, $this->excludeFacetField($base->filters, $field), $relation_columns, $this->relationAuthorizer(), $base->request);
            }

            $counts = $this->columnValueCounts($count_query, $field, $is_real_column);

            $result[$field] = $totals
                ->map(static fn (int $occurrences, mixed $value): array => [
                    'value' => $value,
                    'total' => $occurrences,
                    'count' => (int) ($counts[$value] ?? 0),
                ])
                ->values()
                ->all();
        }

        return $result;
    }

    /**
     * One open (high-cardinality) facet's value page: a SQL `GROUP BY` on the key
     * column, paginated, value-searchable and ordered by count or key, with the
     * double counter and the key≠label two-step.
     *
     * The heavy `total`/`count` counting stays a single grouped query on the
     * model's own connection (never an in-memory `group_by`, so it scales), and
     * display fields are resolved in a second bounded `whereIn` over just the
     * page's keys — so `GROUP BY` stays on one portable column and labelling is a
     * cheap round trip. `total` ignores the request filters (the value universe),
     * `count` applies them minus this facet's own selection (keeping it live), and
     * both stay within the ACL-visible rows.
     */
    public function facetValues(ListRequestData $base, FacetQuery $facet): FacetPage
    {
        $model = $base->model;

        $permission_name = $this->auth->ensurePermission(
            $base->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        // The columns of a facet request are facet fields of the main table, not relation paths.
        $this->authorizeRequestedRelations($base, include_columns: false);
        $this->assertReadableFacet($model, $facet);

        $to_one = $facet->relation === null ? $this->resolveToOneColumn($model, $facet->groupBy) : null;
        $this->assertFacetResolvable($model, $facet, $to_one);

        if ($facet->relation !== null) {
            return $this->facetRelationValues($base, $facet, $permission_name);
        }

        if ($to_one !== null) {
            return $this->facetRelatedColumnValues($base, $facet, $permission_name, $to_one);
        }

        $key = $this->facetKey($facet->groupBy);

        $filtered = $model->newQuery();
        $this->auth->applyAclFiltersToQuery($filtered, $permission_name);

        if ($base->filters instanceof FiltersGroup) {
            $relation_columns = [];
            $this->query_builder->applyFilters($filtered, $this->excludeFacetField($base->filters, $key), $relation_columns, $this->relationAuthorizer(), $base->request);
        }

        // The model's default ordering scope (e.g. `order_column`) is meaningless on an
        // aggregate and breaks `GROUP BY` under MySQL's only_full_group_by.
        $filtered_base = $filtered->toBase()->reorder();

        // A relation label (single-hop BelongsTo keyed by the group key) lets the
        // facet search and sort by the label instead of the raw key — resolved
        // without a join, so no column collides with the ACL/soft-delete scopes.
        $label_relation = $facet->labelField === null
            ? null
            : $this->resolveLabelRelation($model, $key, $facet->labelField);

        // The label is read on the related entity: its permission and ACL apply.
        $label_visible = $label_relation === null
            ? null
            : $this->visibleRelatedKeys($base->request, $model, $label_relation['related'], $label_relation['ownerKey']);

        if ($facet->search !== null) {
            if ($label_relation === null) {
                $filtered_base->where($key, 'like', '%' . $facet->search . '%');
            } elseif (isset($label_relation['translation'])) {
                // Translated label: the translation's foreign key is the group key.
                $translation = $label_relation['translation'];
                $matching_keys = $translation['model']->newQuery()
                    ->where($translation['column'], 'like', '%' . $facet->search . '%')
                    ->where('locale', $translation['locale'])
                    ->when($label_visible !== null, fn (Builder $query): Builder => $query->whereIn($translation['foreign'], $label_visible))
                    ->pluck($translation['foreign'])
                    ->all();

                $filtered_base->whereIn($key, $matching_keys);
            } else {
                $matching_keys = $label_relation['related']->newQuery()
                    ->where($label_relation['column'], 'like', '%' . $facet->search . '%')
                    ->when($label_visible !== null, fn (Builder $query): Builder => $query->whereIn($label_relation['ownerKey'], $label_visible))
                    ->pluck($label_relation['ownerKey'])
                    ->all();

                $filtered_base->whereIn($key, $matching_keys);
            }
        }

        $distinct_values = (int) (clone $filtered_base)->distinct()->count($key);

        $page_query = (clone $filtered_base)
            ->select($key)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($key);

        $this->orderFacetPage($page_query, $model->getTable(), $key, $facet->sort, $label_relation);

        $rows = $page_query->forPage($facet->page, $facet->perPage)->get();

        $page_keys = $rows->pluck($key)->all();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->{$key}] = (int) $row->aggregate;
        }

        $totals = $this->facetTotals($model, $permission_name, $key, $page_keys);
        $attributes = $this->resolveFacetLabels($model, $key, $page_keys, $facet->fields, $base->request);

        $values = array_map(static fn (mixed $value): array => [
            'key' => $value,
            'total' => $totals[$value] ?? 0,
            'count' => $counts[$value] ?? 0,
            'attributes' => $attributes[$value] ?? [],
        ], $page_keys);

        return new FacetPage(array_values($values), $distinct_values, $facet->page, $facet->perPage);
    }

    /**
     * The figures of an aggregate request under the permissions of the list (spec 4.2): the caller needs `select` on
     * the entity, names only relations and columns it may read, and every figure covers only the rows the
     * row-level ACL and the list filters leave.
     *
     * The engine reads the rows and the related entities through an {@see AggregateScope} of closures over the
     * helpers of the facets (ruling R5).
     *
     * @throws AuthorizationException when the caller may not select the entity, a relation the filters name, or the related entity of a relation group or of a group label
     * @throws AggregateException when a field or a filter property is not one the aggregate can read, the caller cannot read it, or it does not suit its function
     */
    public function aggregate(ListRequestData $base, AggregateQuery $query): AggregateResult
    {
        $model = $base->model;
        $request = $base->request;
        $filters = $base->filters;

        $permission_name = $this->auth->ensurePermission(
            $request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        if ($filters instanceof FiltersGroup) {
            $this->assertAggregateFilters($model, $filters);
        }

        // The fields of an aggregate are not list columns: they are checked below, against the aggregate rules.
        $this->authorizeRequestedRelations($base, include_columns: false);
        $this->assertAggregateFields($model, $query);

        $scope = new AggregateScope(
            model: $model,
            baseQuery: fn (): Builder => $this->listedRowsQuery($request, $model, $permission_name, $filters),
            filteredIds: fn (): BaseQueryBuilder => $this->relationParentIds($request, $model, $permission_name, $filters, null),
            visibleRelatedKeys: fn (Model $related, string $owner_key): ?BaseQueryBuilder => $this->visibleRelatedKeys($request, $model, $related, $owner_key),
            resolveToOne: fn (string $path): ?array => $this->resolveToOneColumn($model, $path),
            resolveLabels: fn (string $key, array $page_keys, string $label_field): array => array_map(
                static fn (array $attributes): mixed => $attributes[$label_field] ?? null,
                $this->resolveFacetLabels($model, $key, $page_keys, [$label_field], $request),
            ),
        );

        return $this->aggregateEngine()->run($scope, $query, CarbonImmutable::now());
    }

    /**
     * Page-scoped freshness fingerprint: filtered total + current page id/updated_at rows,
     * plus optional presence of client snapshot ids (on_page / off_page / gone).
     *
     * Reuses list auth, ACL injection, filters, sort and pagination. Selects only
     * primary key + updated_at (when the model has timestamps).
     *
     * Rows are read via the base query builder (no Eloquent hydration) so model
     * global `with()` scopes, `retrieved` hooks, and missing-attribute guards
     * cannot blow up when FK columns were intentionally omitted from the select.
     *
     * @return CrudResult data shape: `{ total, items, presence }`
     */
    public function freshness(ListRequestData $requestData): CrudResult
    {
        $model = $requestData->model;

        $permission_name = $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        $this->authorizeRequestedRelations($requestData);
        $this->auth->injectAclFilters($requestData, $permission_name);

        $query = $model->newQuery();
        $this->query_builder->prepareAuthorizedQuery($query, $requestData, $this->relationAuthorizer());

        // Drop eager loads / wide selects from the list pipeline; fingerprint only.
        $query->withoutEagerLoads();
        $table = $model->getTable();
        $key_name = $model->getKeyName();
        $updated_at_column = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;

        $select_columns = is_array($key_name)
            ? array_map(static fn (string $key): string => $table . '.' . $key, $key_name)
            : [$table . '.' . $key_name];

        if (is_string($updated_at_column) && $updated_at_column !== '') {
            $select_columns[] = $table . '.' . $updated_at_column;
        }

        $query->select($select_columns);

        $total_records = (clone $query)->count();

        // Page window on a clone so the filtered base query stays usable for presence.
        $page_query = clone $query;
        match (true) {
            $requestData->page !== null => $page_query
                ->skip($requestData->from - 1)
                ->take($requestData->to - $requestData->from + 1),
            $requestData->from !== null => tap($page_query, static function (Builder $q) use ($requestData): void {
                $q->skip($requestData->from - 1);

                if ($requestData->to !== null) {
                    $q->take($requestData->to - $requestData->from + 1);
                }
            }),
            isset($requestData->limit) => $page_query->take($requestData->take),
            default => $page_query,
        };

        $rows = $requestData->count
            ? collect()
            : $page_query->toBase()->get();

        $key_columns = is_array($key_name) ? $key_name : [$key_name];

        $items = $rows->map(
            fn (object $row): array => $this->mapFreshnessRow($row, $key_columns, $updated_at_column),
        )->values()->all();

        $presence = $this->buildFreshnessPresence(
            $query,
            $items,
            $key_name,
            $key_columns,
            $table,
            $updated_at_column,
            $this->normalizeFreshnessCheckIds($requestData),
        );

        return new CrudResult(
            data: [
                'total' => $total_records,
                'items' => $items,
                'presence' => $presence,
            ],
            meta: new CrudMeta(
                totalRecords: $total_records,
                currentRecords: count($items),
                currentPage: $requestData->page,
                totalPages: $requestData->page !== null ? $requestData->calculateTotalPages($total_records) : null,
                pagination: $requestData->pagination,
                from: $requestData->from,
                to: $requestData->to,
                class: $model::class,
                table: $model->getTable(),
                cachedAt: Date::now(),
            ),
        );
    }

    public function detail(DetailRequestData $requestData): CrudResult
    {
        $model = $requestData->model;

        // 1. Check permission
        $permission_name = $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        // 2. Constrain by primary key first (from validated/input/route so record-not-found can 404)
        $key = $this->getModelPrimaryKeyName($model);

        if (is_array($key)) {
            $key_value = array_map(
                fn (string $k): mixed => $this->resolveKeyFromRequest($requestData->request, $k),
                $key,
            );
            throw_if(
                array_any($key_value, static fn (mixed $value): bool => $value === null || $value === ''),
                ModelNotFoundException::class,
                'Primary key is required for detail.',
            );
            $query = $model->newQuery()->where(array_combine($key, $key_value));
        } else {
            $key_value = $this->resolveKeyFromRequest($requestData->request, $key);
            throw_if($key_value === null || $key_value === '', ModelNotFoundException::class, 'Primary key is required for detail.');
            $query = $model->newQuery()->where([$key => $key_value]);
        }

        // 3. Build query and apply ACL filters
        $this->authorizeRequestedRelations($requestData);
        $this->auth->applyAclFiltersToQuery($query, $permission_name);
        $this->query_builder->prepareAuthorizedQuery($query, $requestData, $this->relationAuthorizer());

        $data = $query->sole();

        $this->applyComputedMethods($data, $requestData);

        $meta = new CrudMeta(
            class: $model::class,
            table: $model->getTable(),
            cachedAt: Date::now(),
            relations: $this->partialRelations($requestData->request, $data),
        );

        return new CrudResult(
            data: $data,
            meta: $meta,
        );
    }

    public function search(SearchRequestData $requestData): CrudResult
    {
        $model = $requestData->model;

        $is_searchable_class = class_uses_trait($model, Searchable::class);

        if (! $is_searchable_class) {
            return new CrudResult(
                data: null,
                error: 'Full-search operation can be done only on Searchable entities',
                statusCode: Response::HTTP_BAD_REQUEST,
            );
        }

        // 2. Check permission
        $permission_name = $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        // The relations and dotted columns load from the database and are checked in full; the filters and
        // sorts name index fields, so only the part of them that resolves to relations is checked.
        $this->authorizeRequestedRelations($requestData, lenient_filters: true);

        return match ($requestData->mode) {
            SearchMode::Orchestrated => $this->searchWithAdvanced($requestData, $permission_name),
            SearchMode::Auto => ($this->advanced_search ?? app(AdvancedSearchService::class))->available($model)
                ? $this->searchWithAdvanced($requestData, $permission_name)
                : $this->searchWithScout($requestData, $permission_name),
            SearchMode::Basic => $this->searchWithScout($requestData, $permission_name),
        };
    }

    public function history(HistoryRequestData $requestData): CrudResult
    {
        $model = $requestData->model;

        throw_unless($this->hasHistory($model), BadMethodCallException::class, sprintf("'%s' doesn't have history handling", $requestData->mainEntity));

        // 1. Check permission
        $permission_name = $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        // The versions hold the values as they were before they were published, and the raw attributes of the
        // record: they are editorial data, for who may change or approve the entity, as the pending changes are.
        // The answer is the same whether the record is hidden by its ACL or does not exist, because it comes first.
        throw_unless(
            $this->relationAuthorizer()->maySeePendingChanges($requestData->request, $model),
            AuthorizationException::class,
            'User not allowed to access this resource',
        );

        // 2. Build query and apply ACL filters
        $this->authorizeRequestedRelations($requestData);
        $query = $model->newQuery();
        $this->auth->applyAclFiltersToQuery($query, $permission_name);
        $this->query_builder->prepareAuthorizedQuery($query, $requestData, $this->relationAuthorizer());

        $query->with('history', function (Relation $q) use ($requestData): void {
            // A version loads on its own the record it belongs to, which is the `record` of this answer and would
            // load its own default relations unchecked: it is left out. The other relations a version loads on its
            // own obey the rule.
            $q->getQuery()->without('versionable');
            $this->query_builder->guardEngineQuery($q->getQuery(), $this->relationAuthorizer(), $requestData->request);
            $q->latest();

            if (isset($requestData->limit)) {
                $q->take($requestData->limit);
            }
        });

        if (! preview() && $this->useHasApproval($model)) {
            $query->with('modifications');
        }

        $data = $query->sole();

        $this->applyComputedMethods($data, $requestData);

        $history_relation = $data->getRelation('history');
        $history_array = [];

        if ($history_relation !== null && $history_relation instanceof Collection) {
            $history_array = $history_relation->toArray();
        }

        $meta = new CrudMeta(
            class: $model::class,
            table: $model->getTable(),
            cachedAt: Date::now(),
        );

        // The model serializes its own record, so the attributes it hides from the anonymous user and the media
        // projection of the api guard apply; the history and the modifications are given beside it.
        $record_array = (clone $data)->unsetRelation('history')->unsetRelation('modifications')->toArray();

        $payload = [
            'record' => $record_array,
            'history' => $history_array,
        ];

        return new CrudResult(
            data: $payload,
            meta: $meta,
        );
    }

    public function tree(TreeRequestData $requestData): CrudResult
    {
        $model = $requestData->model;

        throw_unless($this->useRecursiveRelationships($model), UnexpectedValueException::class, sprintf("'%s' is not a hierarchical class", $requestData->mainEntity));

        // 1. Check permission
        $permission_name = $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        $tree_relation_type = [];

        if ($requestData->parents && $requestData->children) {
            $tree_relation_type = 'bloodline';
        } elseif ($requestData->parents) {
            $tree_relation_type = 'ancestorsAndSelf';
        } elseif ($requestData->children) {
            $tree_relation_type = 'descendantsAndSelf';
        }

        // 2. Build query and apply ACL filters. The tree relation is the engine's own, added after the
        //    request relations so that it is not taken for a relation the model loads on its own.
        $this->authorizeRequestedRelations($requestData);
        $query = $model->newQuery();
        $this->auth->applyAclFiltersToQuery($query, $permission_name);
        $this->query_builder->prepareAuthorizedQuery($query, $requestData, $this->relationAuthorizer());

        if (is_string($tree_relation_type)) {
            // The nodes are records of the same entity: the root's ACL leaves out the ones it hides. The tree query
            // reads a recursive expression named after neither the table nor the model, so the visible keys are
            // matched on the key column of that expression.
            $visible = $this->visibleRelatedKeys($requestData->request, $model, $model->newInstance(), $model->getKeyName());

            $query->with([$tree_relation_type => function (Relation $nodes) use ($visible, $model, $requestData): void {
                // Added after the request relations, so the guard has not seen this query: the relations the nodes
                // load on their own, and the ones their appended attributes read (a cover), follow the rule here,
                // once for all the nodes.
                $this->query_builder->guardEngineQuery($nodes->getQuery(), $this->relationAuthorizer(), $requestData->request);

                if ($visible !== null) {
                    $nodes->getQuery()->whereIn($nodes->getQuery()->getModel()->qualifyColumn($model->getKeyName()), $visible);
                }
            }]);
        }

        $data = $requestData->request->has(is_array($requestData->primaryKey) ? $requestData->primaryKey[0] : $requestData->primaryKey)
            ? $query->sole()
            : $query->get();

        $this->applyComputedMethods($data, $requestData);

        $meta = new CrudMeta(
            class: $model::class,
            table: $model->getTable(),
            cachedAt: Date::now(),
        );

        return new CrudResult(
            data: $data,
            meta: $meta,
        );
    }

    public function insert(ModifyRequestData $requestData): CrudResult
    {
        $model = $requestData->model;
        $this->assertCrudWriteAllowed($model, 'insert');
        $this->auth->ensurePermission($requestData->request, $model->getTable(), 'insert', $model->getConnectionName());
        $changes = $requestData->changes;
        $discarded_values = $this->removeNonFillableProperties($model, $changes);

        $created = $model->create($changes);

        throw_unless($created, LogicException::class, 'Record not created');

        $captured = $this->pendingRequestOf($created);

        if ($captured instanceof Modification) {
            return $this->capturedResult([$captured]);
        }

        $error = $discarded_values === [] ? null : implode(', ', $discarded_values);

        return new CrudResult(
            data: $created,
            error: $error,
            statusCode: Response::HTTP_CREATED,
        );
    }

    public function update(ModifyRequestData $requestData): CrudResult
    {
        $model = $requestData->model;
        $this->assertCrudWriteAllowed($model, 'update');
        $permission_name = $this->auth->ensurePermission($requestData->request, $model->getTable(), 'update', $model->getConnectionName());

        $key_value = $this->getModelKeyValue($requestData);
        $found_records = $this->aclFiltered(
            $model->newQuery()->where($this->keyValueToWhereCondition($model, $key_value)),
            $permission_name,
        )->lazy(100);
        $changes = $requestData->changes;
        $discarded_values = $this->removeNonFillableProperties($model, $changes);
        $relations = $this->resolveSyncableRelations($model, $requestData->relations, $requestData->request);

        $updated_records = new Collection();
        $captured = [];
        $found_count = 0;
        $lock_version = $this->requestedLockVersion($requestData);
        $request = $requestData->request;

        $model->getConnection()->transaction(function () use ($found_records, $updated_records, $changes, $relations, $lock_version, $request, &$found_count, &$captured): void {
            foreach ($found_records as $found_record) {
                $found_count++;

                if ($lock_version !== null && method_exists($found_record, 'currentLockVersion')) {
                    // Optimistic locking: the version the client read. Setting it here makes the
                    // update fail loudly if somebody else has written the row in the meantime.
                    $found_record->setAttribute($found_record::lockVersionColumn(), $lock_version);
                }

                // The record and its relations are one revision of it (D42).
                $write = function () use ($found_record, $changes, $relations, $request, &$captured): bool {
                    /** @psalm-suppress InvalidArgument */
                    $mutated = (bool) $found_record->update($changes);
                    $pending = $mutated ? null : $this->pendingRequestOf($found_record);

                    if ($pending instanceof Modification) {
                        $captured[] = $pending;
                    }

                    if ($relations !== []) {
                        $this->syncModelRelations($request, $found_record, $relations);
                        $mutated = true;
                    }

                    return $mutated;
                };
                $mutated = (bool) (method_exists($found_record, 'withinRevision') ? $found_record->withinRevision($write) : $write());

                if ($mutated) {
                    $updated_records->add($found_record->fresh());
                }
            }
        });
        throw_if($found_count === 0 && $requestData->request->has('id'), ModelNotFoundException::class, 'No model Found');

        $error = $this->filterExpectedDiscardedForError($discarded_values, $requestData);

        if ($captured !== []) {
            return $this->capturedResult($captured, $found_count === 1 ? null : $updated_records, $error);
        }

        return new CrudResult(
            data: $updated_records,
            error: $error,
        );
    }

    public function delete(ModifyRequestData $requestData): CrudResult
    {
        $model = $requestData->model;
        $this->assertCrudWriteAllowed($model, 'delete');
        $permission_name = $this->auth->ensurePermission($requestData->request, $model->getTable(), 'forceDelete', $model->getConnectionName());
        $key_value = $this->getModelKeyValue($requestData);
        $found_records = $this->aclFiltered(
            $model->newQuery()->where($this->keyValueToWhereCondition($model, $key_value)),
            $permission_name,
        )->lazy(100);

        $found_count = 0;
        $deleted_count = 0;
        $captured = [];
        $model->getConnection()->transaction(function () use ($found_records, &$found_count, &$deleted_count, &$captured): void {
            foreach ($found_records as $found_record) {
                $found_count++;

                if ($found_record->forceDelete()) {
                    $deleted_count++;
                } elseif (($pending = $this->pendingRequestOf($found_record)) instanceof Modification) {
                    $captured[] = $pending;
                }
            }
        });
        throw_if($found_count === 0 && $requestData->request->has('id'), ModelNotFoundException::class, 'No model Found');

        if ($captured !== []) {
            return $this->capturedResult($captured, $found_count === 1 ? null : ['deleted' => $deleted_count]);
        }

        return new CrudResult(
            data: ['deleted' => $deleted_count],
            statusCode: Response::HTTP_OK,
        );
    }

    public function doActivateOperation(ModifyRequestData $requestData, string $operation): CrudResult
    {
        $model = $requestData->model;
        $is_activate = $operation === 'activate';

        // activate restores a soft-deleted record (restore permission); inactivate
        // soft-deletes a live one (delete permission). The permission must match the
        // operation on both gates — previously the entity gate distinguished them but
        // the user gate always required 'restore', so soft-deleting demanded the
        // restore permission.
        $required_permission = $is_activate ? 'restore' : 'delete';
        $this->assertCrudWriteAllowed($model, $required_permission);
        $permission_name = $this->auth->ensurePermission($requestData->request, $model->getTable(), $required_permission, $model->getConnectionName());
        $key_value = $this->getModelKeyValue($requestData);
        $found_record = $this->aclFiltered(
            $this->newQueryWithTrashed($model)->where($this->keyValueToWhereCondition($model, $key_value)),
            $permission_name,
        )->firstOrFail();

        if ($is_activate) {
            throw_unless(method_exists($found_record, 'restore'), LogicException::class, 'Record not activated');

            if (! $found_record->restore()) {
                $captured = $this->pendingRequestOf($found_record);
                throw_unless($captured instanceof Modification, LogicException::class, 'Record not activated');

                return $this->capturedResult([$captured]);
            }

            return new CrudResult(
                data: $found_record,
            );
        }

        if (! $found_record->delete()) {
            $captured = $this->pendingRequestOf($found_record);
            throw_unless($captured instanceof Modification, LogicException::class, 'Record not inactivated');

            return $this->capturedResult([$captured]);
        }

        return new CrudResult(
            data: $found_record,
        );
    }

    public function activate(ModifyRequestData $requestData): CrudResult
    {
        return $this->doActivateOperation($requestData, 'activate');
    }

    public function inactivate(ModifyRequestData $requestData): CrudResult
    {
        return $this->doActivateOperation($requestData, 'inactivate');
    }

    public function approve(ModifyRequestData $requestData): CrudResult
    {
        return $this->doApproveOperation($requestData, 'approve');
    }

    public function disapprove(ModifyRequestData $requestData): CrudResult
    {
        return $this->doApproveOperation($requestData, 'disapprove');
    }

    /**
     * Withdraw a request the caller authored, before its decision. The record needs only to be
     * readable: the request is the author's own, and the vote service refuses anybody else.
     */
    public function withdraw(ModifyRequestData $requestData): CrudResult
    {
        $model = $requestData->model;
        $this->auth->ensurePermission($requestData->request, $model->getTable(), 'select', $model->getConnectionName());

        $modification_key = $requestData->changes['modification'] ?? null;
        throw_unless(is_int($modification_key) || is_string($modification_key), InvalidArgumentException::class, 'The modification to withdraw is required.');

        $user = Auth::user();
        throw_unless($user instanceof User, LogicException::class, 'Authenticated user is required.');

        $modification = (new Modification())->setConnection($model->getConnectionName())->newQuery()
            ->where('modifiable_type', $model::class)
            ->whereKey($modification_key)
            ->firstOrFail();

        resolve(ModificationVoteService::class)->withdraw($user, $modification);

        return new CrudResult(
            data: ['withdrawn' => $modification->getKey()],
        );
    }

    public function lock(ModifyRequestData $requestData): CrudResult
    {
        return $this->doLockOperation($requestData, 'lock');
    }

    public function unlock(ModifyRequestData $requestData): CrudResult
    {
        return $this->doLockOperation($requestData, 'unlock');
    }

    public function clearModelCache(CrudRequestData $requestData): CrudResult
    {
        $model = $requestData->model;
        $table = $model->getTable();
        $cache = Cache::store();

        if ($cache instanceof CacheRepository) {
            $cache->clearByEntity($model);
        }

        return new CrudResult(
            data: $table . ' cached cleared',
            statusCode: Response::HTTP_OK,
        );
    }

    /**
     * Active modifications for an entity type (publisher approval inbox).
     *
     * @return CrudResult{data: list<array<string, mixed>>}
     */
    public function pendingApprovals(CrudRequestData $requestData): CrudResult
    {
        $model = $requestData->model;
        $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'approve',
            $model->getConnectionName(),
        );

        $connection = $model->getConnectionName();
        $modification_prototype = (new Modification())->setConnection($connection);

        $query = $this->decidableModifications($modification_prototype->newQuery()->activeOnly(), $model)
            ->with(['modifiable', 'modifier'])
            ->oldest();

        $modifications = $this->aclFilteredIfDefined($query, PermissionName::forModel(new Modification(), 'select'))->get();

        $rows = $modifications->map(self::pendingApprovalRow(...))->values();

        return new CrudResult(
            data: $rows,
            meta: new CrudMeta(
                totalRecords: $rows->count(),
                currentRecords: $rows->count(),
                class: $model::class,
                table: $model->getTable(),
                cachedAt: Date::now(),
            ),
        );
    }

    /**
     * Soft-kept disapproval for the authenticated modifier on one record (editor rejection banner).
     */
    /**
     * Every pending request the user can vote on, on any entity, plus their own. Voting follows
     * the table's `approve` or `disapprove` permission; the author never votes on their request.
     */
    public function allPendingApprovals(User $user): CrudResult
    {
        $votable_types = Modification::query()
            ->activeOnly()
            ->distinct()
            ->pluck('modifiable_type')
            ->filter(static fn (mixed $type): bool => is_string($type) && class_exists($type) && is_subclass_of($type, Model::class)
                && ($user->can(PermissionName::forModel(new $type(), 'approve'))))
            ->values()
            ->all();

        $query = Modification::query()
            ->activeOnly()
            ->where(function (Builder $visible) use ($votable_types, $user): void {
                foreach ($votable_types as $type) {
                    $visible->orWhere(fn (Builder $votable): Builder => $this->decidableModifications($votable, new $type()));
                }

                $visible->orWhere(static fn (Builder $mine): Builder => $mine->where('modifier_type', $user::class)->where('modifier_id', $user->getKey()));
            })
            ->with(['modifiable', 'modifier'])
            ->oldest();

        $modifications = $this->aclFilteredIfDefined($query, PermissionName::forModel(new Modification(), 'select'))->get();

        $rows = $modifications->map(static function (Modification $modification) use ($user): Fluent {
            $row = self::pendingApprovalRow($modification);
            $type = $modification->modifiable_type;
            $is_mine = $modification->modifier_type === $user::class && (string) $modification->modifier_id === (string) $user->getKey();

            $row['modifiable_type'] = $type;
            $row['entity'] = is_string($type) && class_exists($type) ? (new $type())->getTable() : null;
            $row['operation'] = $modification->operation->value;
            $row['is_mine'] = $is_mine;
            // Somebody else's request is listed only when the user may decide on it.
            $row['can_vote'] = ! $is_mine;

            return $row;
        })->values();

        return new CrudResult(
            data: $rows,
            meta: new CrudMeta(
                totalRecords: $rows->count(),
                currentRecords: $rows->count(),
                cachedAt: Date::now(),
            ),
        );
    }

    public function latestDisapproval(CrudRequestData $requestData): CrudResult
    {
        $model = $requestData->model;
        $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'select',
            $model->getConnectionName(),
        );

        $user = Auth::user();
        throw_unless($user instanceof User, LogicException::class, 'Authenticated user is required.');

        $record_id = $requestData->request->input('id');
        $found_record = $model->newQuery()->whereKey($record_id)->firstOrFail();

        $connection = $found_record->getConnectionName();
        $modification_prototype = (new Modification())->setConnection($connection);

        $modifier_types = array_values(array_unique([
            $user::class,
            User::class,
            \App\Models\User::class,
        ]));

        /** @var Modification|null $modification */
        $modification = $modification_prototype->newQuery()
            ->where('modifiable_type', $model::class)
            ->where('modifiable_id', $found_record->getKey())
            ->where('modifier_id', $user->getKey())
            ->whereIn('modifier_type', $modifier_types)
            ->inactiveOnly()
            ->whereHas('disapprovals')
            ->with(['disapprovals' => static fn ($query) => $query->latest('id')])
            ->latest('id')
            ->first();

        if ($modification === null) {
            return new CrudResult(
                data: null,
                meta: new CrudMeta(
                    totalRecords: 0,
                    currentRecords: 0,
                    class: $model::class,
                    table: $model->getTable(),
                    cachedAt: Date::now(),
                ),
            );
        }

        /** @var Disapproval|null $disapproval */
        $disapproval = $modification->disapprovals->first();

        return new CrudResult(
            data: new Fluent([
                'id' => $modification->modifiable_id,
                'modification_id' => $modification->getKey(),
                'reason' => $disapproval?->reason,
                'modifications' => $modification->modifications,
                'disapproved_at' => $disapproval?->getAttribute('created_at'),
                'disapprover_id' => $disapproval?->disapprover_id,
                'disapprover_type' => $disapproval?->disapprover_type,
            ]),
            meta: new CrudMeta(
                totalRecords: 1,
                currentRecords: 1,
                class: $model::class,
                table: $model->getTable(),
                cachedAt: Date::now(),
            ),
        );
    }

    /**
     * One pending request as the inbox lists it: the record, its label and the quorum still missing.
     */
    private static function pendingApprovalRow(Modification $modification): Fluent
    {
        $modifiable = $modification->modifiable;
        $label = null;

        if ($modifiable instanceof Model) {
            $attributes = $modifiable->getAttributes();
            $label = $attributes['title'] ?? $attributes['name'] ?? null;
        }

        return new Fluent([
            'id' => $modification->modifiable_id,
            'modification_id' => $modification->getKey(),
            'title' => $label,
            'created_at' => $modification->getAttribute('created_at'),
            'approvers_required' => $modification->approvers_required,
            'approvers_remaining' => $modification->approversRemaining,
            'modifier_id' => $modification->modifier_id,
            'modifier_type' => $modification->modifier_type,
        ]);
    }

    /**
     * The properties the filters of a request name, through their nested groups. A scope filter names none, and a
     * relation filter comes only from an ACL.
     *
     * @return list<string>
     */
    private static function filterProperties(FiltersGroup $filters): array
    {
        $properties = [];

        foreach ($filters->filters as $filter) {
            if ($filter instanceof FiltersGroup) {
                array_push($properties, ...self::filterProperties($filter));
            } elseif ($filter instanceof Filter) {
                $properties[] = $filter->property;
            }
        }

        return $properties;
    }

    /**
     * Narrow a modifications query to the requests on $model's table the user may decide on: the
     * table's `approve` ACL restricts which records, and a pending create, which has no record yet
     * for the ACL to read, stays in. A trashed record awaiting a restore, or one hidden while its
     * deletion waits, is still a record to decide on.
     *
     * @param  Builder<Modification>  $query
     * @return Builder<Modification>
     */
    private function decidableModifications(Builder $query, Model $model): Builder
    {
        $query->where('modifiable_type', $model::class);

        $permission = PermissionName::forModel($model, 'approve');

        if (! $this->permissionExists($permission) || ! $this->auth->getAclFilters($permission) instanceof FiltersGroup) {
            return $query;
        }

        $records = $this->aclFiltered(
            $this->newQueryWithTrashed($model)->withoutGlobalScope(PendingDeletionStrategy::HIDE_SCOPE)->select($model->getQualifiedKeyName()),
            $permission,
        );

        return $query->where(static fn (Builder $decidable): Builder => $decidable->whereNull('modifiable_id')->orWhereIn('modifiable_id', $records));
    }

    /**
     * Count occurrences of each value of a facet field within an ACL-scoped query.
     *
     * A real base-table column is counted with a single portable `GROUP BY`
     * aggregate on the underlying query (nothing hydrated into PHP). A computed
     * accessor has no column to group on, so it falls back to hydrating the models
     * and counting the resolved attribute values in memory. Both paths return a
     * value-keyed map of integer counts.
     *
     * @return \Illuminate\Support\Collection<array-key, int>
     */
    private function columnValueCounts(Builder $query, string $field, bool $is_real_column): \Illuminate\Support\Collection
    {
        if (! $is_real_column) {
            return $query->get()->pluck($field)->countBy();
        }

        return $query
            ->toBase()
            ->reorder()
            ->select($field)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($field)
            ->pluck('aggregate', $field)
            ->map(static fn (mixed $count): int => (int) $count);
    }

    /**
     * Refuses a request that names a relation whose entity the caller may not select (spec 8.1, R6), and one
     * that names something that is not a relation, before any of those names is called.
     *
     * @throws AuthorizationException when the caller may not select an entity the request names
     * @throws InvalidArgumentException when a name of the request is not a relation
     */
    private function authorizeRequestedRelations(SelectRequestData $request_data, bool $lenient_filters = false, bool $include_columns = true): void
    {
        $this->assertComputedColumns($request_data);
        $this->assertReadableColumns($request_data, $include_columns);

        $this->relationAuthorizer()->authorizePaths(
            $request_data->request,
            $request_data->model,
            $this->query_builder->requestedRelationPaths($request_data->model, $request_data, $lenient_filters, $include_columns),
        );
    }

    /**
     * Refuses, before anything is queried, a request that would read through the query a column the caller does
     * not receive in the serialization (a column hidden from the anonymous user, a media column outside the
     * projection): the sorts, the filter properties, the group_by keys and the aggregated columns. The filters
     * are the caller's own: the ACL filters, which name columns on their own authority, are merged afterwards.
     *
     * @throws InvalidArgumentException when a name of the request is not readable by the caller
     */
    private function assertReadableColumns(SelectRequestData $request_data, bool $include_columns): void
    {
        $model = $request_data->model;

        if ($include_columns) {
            foreach ($request_data->columns as $column) {
                if ($column->type->isAggregateColumn() && ! Str::endsWith($column->name, '.*')) {
                    ReadableColumnGuard::assertReadablePath($model, $column->name);
                }
            }
        }

        if (! $request_data instanceof ListRequestData) {
            return;
        }

        foreach ($request_data->sort ?? [] as $sort) {
            ReadableColumnGuard::assertReadablePath($model, (string) $sort->property);
        }

        foreach ($request_data->group_by as $group) {
            ReadableColumnGuard::assertReadablePath($model, $group);
        }

        if ($request_data->filters instanceof FiltersGroup) {
            $this->assertReadableFilters($model, $request_data->filters);
        }
    }

    /**
     * @throws InvalidArgumentException when a filter property is not readable by the caller
     */
    private function assertReadableFilters(Model $model, FiltersGroup $filters): void
    {
        foreach (self::filterProperties($filters) as $property) {
            ReadableColumnGuard::assertReadablePath($model, $property);
        }
    }

    /**
     * The facet fields of a request obey the readable-column rule of its sorts and filters: a facet counts the
     * values of its field, which is reading them.
     *
     * @throws InvalidArgumentException when a facet field is not readable by the caller
     */
    private function assertReadableFacet(Model $model, FacetQuery $facet): void
    {
        if ($facet->relation === null) {
            ReadableColumnGuard::assertReadablePath($model, $facet->groupBy);

            foreach ($facet->fields as $field) {
                ReadableColumnGuard::assertReadablePath($model, $field);
            }

            if ($facet->labelField !== null) {
                ReadableColumnGuard::assertReadablePath($model, $facet->labelField);
            }

            return;
        }

        ReadableColumnGuard::assertReadablePath($model, $facet->relation);
        $related = $this->resolveManyRelation($model, $facet->relation)?->getRelated();

        if (! $related instanceof Model) {
            return;
        }

        foreach ([...$facet->fields, ...($facet->labelField === null ? [] : [$facet->labelField])] as $field) {
            ReadableColumnGuard::assertReadablePath($related, $field);
        }
    }

    /**
     * Refuses, before anything is queried or called, a request that would make a model run code it did not
     * declare for the CRUD read: a `method` column the model does not declare as computable, an appended
     * attribute without an accessor, a group_by key naming a method.
     *
     * @throws InvalidArgumentException when a name of the request is not computable or not readable
     */
    private function assertComputedColumns(SelectRequestData $request_data): void
    {
        $model = $request_data->model;
        $table = $model->getTable();

        foreach ($request_data->columns as $column) {
            if ($column->type !== ColumnType::Method && $column->type !== ColumnType::Append) {
                continue;
            }

            $segments = explode('.', str_replace($table . '.', '', $column->name));
            $name = (string) array_pop($segments);
            $target = ComputedColumnGuard::modelAt($model, $segments);

            if ($column->type === ColumnType::Method) {
                ComputedColumnGuard::assertMethod($target, $name);
            } else {
                ComputedColumnGuard::assertAppend($target, $name);
            }
        }

        if ($request_data instanceof ListRequestData) {
            foreach ($request_data->group_by as $group) {
                // The grouping reads the relation from each row: one the CRUD never loads cannot be grouped on.
                throw_if(
                    Str::contains($group, '.') && QueryBuilder::isBlackListedPath(Str::beforeLast($group, '.')),
                    InvalidArgumentException::class,
                    sprintf('"%s" cannot be grouped on: the CRUD does not load that relation.', $group),
                );

                ComputedColumnGuard::assertReadablePath($model, $group);
            }
        }
    }

    /**
     * The relations of a detail record the related ACL left partial, with how many records each lost (R7).
     *
     * @return array<string, array{hidden: int}>
     */
    private function partialRelations(Request $request, Model $record): array
    {
        $partial = [];

        foreach (array_keys($record->getRelations()) as $relation) {
            if (! is_string($relation)) {
                continue;
            }

            $hidden = $this->relationAuthorizer()->hiddenCount($request, $record, $relation);

            if ($hidden > 0) {
                $partial[$relation] = ['hidden' => $hidden];
            }
        }

        return $partial;
    }

    private function relationAuthorizer(): RelationAuthorizer
    {
        return $this->relation_authorizer ?? resolve(RelationAuthorizer::class);
    }

    private function aggregateEngine(): AggregateEngine
    {
        return $this->aggregate_engine ?? resolve(AggregateEngine::class);
    }

    /**
     * The keys (`$key_column` values) of the related rows the caller may see, for a facet query that reads a
     * related table directly; null when nothing narrows them.
     *
     * @throws AuthorizationException when the caller may not select the related entity
     */
    private function visibleRelatedKeys(Request $request, Model $parent, Model $related, string $key_column): ?BaseQueryBuilder
    {
        return $this->relationAuthorizer()->visibleKeysQuery($request, $parent, $related, $key_column)?->toBase();
    }

    /**
     * Facet over a BelongsToMany/MorphToMany relation's pivot: keys are related model
     * ids and the double counter counts distinct parent rows per related key. Parent
     * ACL and filters are enforced through a bounded id subquery, never a join into
     * the aggregated query, so parent scopes cannot collide with related columns. A
     * relation facet's own selection is excluded from its filtered counts, keeping
     * cross-filtering live. Labels, search and sort resolve on the related table.
     */
    private function facetRelationValues(ListRequestData $base, FacetQuery $facet, string $permission_name): FacetPage
    {
        $model = $base->model;
        $relation = $this->resolveManyRelation($model, (string) $facet->relation);

        if ($relation === null) {
            return new FacetPage([], 0, $facet->page, $facet->perPage);
        }

        $pivot_table = $relation->getTable();
        $qualified_foreign = $pivot_table . '.' . $relation->getForeignPivotKeyName();
        $qualified_related = $pivot_table . '.' . $relation->getRelatedPivotKeyName();
        $related = $relation->getRelated();
        $related_key_name = $relation->getRelatedKeyName();
        $related_key = $related->getTable() . '.' . $related_key_name;

        // The related rows obey their own permission and ACL, as a loaded relation does.
        $related_visible = $this->visibleRelatedKeys($base->request, $model, $related, $related_key_name);

        // A `relation.column` label field is a locale-scoped translation join keyed
        // by the pivot's related key; a bare one is a column on the related table.
        $label_translation = $facet->labelField !== null
            ? $this->relationTranslationLabel($related, $facet->labelField)
            : null;
        $label_bare = $label_translation === null && $facet->labelField !== null && $facet->labelField !== ''
            ? $related->getTable() . '.' . $facet->labelField
            : null;
        $label = $label_translation !== null
            ? $label_translation['table'] . '.' . $label_translation['column']
            : $label_bare;

        $filtered_ids = fn (): BaseQueryBuilder => $this->relationParentIds($base->request, $model, $permission_name, $base->filters, $facet->relation);
        $acl_ids = fn (): BaseQueryBuilder => $this->relationParentIds($base->request, $model, $permission_name, null, null);

        // Aggregate over the pivot on the query builder (never Eloquent) so heavy
        // related models are not hydrated from a partial select. Related global
        // scopes (soft deletes) are honoured through a bounded id subquery, and the
        // related/translation tables are joined only when a label is needed.
        $pivot_query = fn (): BaseQueryBuilder => $related->getConnection()->query()
            ->from($pivot_table)
            ->whereIn($qualified_related, $related->newQuery()->toBase()->select($related_key_name))
            ->when($related_visible !== null, fn (BaseQueryBuilder $query): BaseQueryBuilder => $query->whereIn($qualified_related, $related_visible))
            ->when(
                $relation instanceof MorphToMany,
                fn (BaseQueryBuilder $query): BaseQueryBuilder => $query->where($pivot_table . '.' . $relation->getMorphType(), $relation->getMorphClass()),
            )
            ->when(
                $label_translation !== null,
                fn (BaseQueryBuilder $query): BaseQueryBuilder => $query->leftJoin(
                    $label_translation['table'],
                    fn (JoinClause $join): JoinClause => $join
                        ->on($label_translation['table'] . '.' . $label_translation['foreign'], '=', $qualified_related)
                        ->where($label_translation['table'] . '.locale', '=', $label_translation['locale']),
                ),
            )
            ->when(
                $label_bare !== null,
                fn (BaseQueryBuilder $query): BaseQueryBuilder => $query->leftJoin($related->getTable(), $related_key, '=', $qualified_related),
            );

        $searched = fn (BaseQueryBuilder $query): BaseQueryBuilder => $query->when(
            $facet->search !== null,
            fn (BaseQueryBuilder $q): BaseQueryBuilder => $q->where($label ?? $qualified_related, 'like', '%' . $facet->search . '%'),
        );

        $distinct_values = (int) $searched($pivot_query()->whereIn($qualified_foreign, $filtered_ids()))
            ->distinct()
            ->count($qualified_related);

        $page_query = $searched($pivot_query()->whereIn($qualified_foreign, $filtered_ids()))
            ->groupBy($qualified_related)
            ->selectRaw($qualified_related . ' as facet_key')
            ->selectRaw('count(distinct ' . $qualified_foreign . ') as aggregate');

        $this->orderRelationFacet($page_query, $qualified_related, $facet->sort, $label);

        $rows = $page_query->forPage($facet->page, $facet->perPage)->get();

        $page_keys = [];
        $counts = [];

        foreach ($rows as $row) {
            $page_keys[] = $row->facet_key;
            $counts[$row->facet_key] = (int) $row->aggregate;
        }

        $totals = $this->relationFacetTotals($pivot_query, $qualified_foreign, $qualified_related, $acl_ids(), $page_keys);
        $attributes = $this->resolveRelationFacetLabels($related, $related_key_name, $page_keys, $facet->fields);

        $values = array_map(static fn (mixed $value): array => [
            'key' => $value,
            'total' => $totals[$value] ?? 0,
            'count' => $counts[$value] ?? 0,
            'attributes' => $attributes[$value] ?? [],
        ], $page_keys);

        return new FacetPage(array_values($values), $distinct_values, $facet->page, $facet->perPage);
    }

    /**
     * Facet over a column reached through a single-hop to-one relation (e.g. group
     * by `place.country`): the parent rows are joined to the related table and
     * grouped by the related column, counting distinct parents per value. The value
     * is its own label, so no label resolution is needed. Parent ACL and filters are
     * enforced through a bounded id subquery, and the facet's own selection
     * (`<relation>.<column>`) is excluded to keep cross-filtering live.
     *
     * @param  array{related: Model, relatedTable: string, foreignKey: string, ownerKey: string, column: string}  $to_one
     */
    private function facetRelatedColumnValues(ListRequestData $base, FacetQuery $facet, string $permission_name, array $to_one): FacetPage
    {
        $model = $base->model;
        $table = $model->getTable();
        $qualified_pk = $table . '.' . $model->getKeyName();
        $qualified_group = $to_one['relatedTable'] . '.' . $to_one['column'];

        $filtered_ids = fn (): BaseQueryBuilder => $this->relationParentIds($base->request, $model, $permission_name, $base->filters, $facet->groupBy);

        // The related rows obey their own permission and ACL, as a loaded relation does.
        $related_visible = $this->visibleRelatedKeys($base->request, $model, $to_one['related'], $to_one['ownerKey']);
        $acl_ids = fn (): BaseQueryBuilder => $this->relationParentIds($base->request, $model, $permission_name, null, null);

        $joined = fn (): BaseQueryBuilder => $model->getConnection()->query()
            ->from($table)
            ->join($to_one['relatedTable'], $table . '.' . $to_one['foreignKey'], '=', $to_one['relatedTable'] . '.' . $to_one['ownerKey'])
            ->when($related_visible !== null, fn (BaseQueryBuilder $query): BaseQueryBuilder => $query->whereIn($to_one['relatedTable'] . '.' . $to_one['ownerKey'], $related_visible));

        $searched = fn (BaseQueryBuilder $query): BaseQueryBuilder => $query->when(
            $facet->search !== null,
            fn (BaseQueryBuilder $q): BaseQueryBuilder => $q->where($qualified_group, 'like', '%' . $facet->search . '%'),
        );

        $distinct_values = (int) $searched($joined()->whereIn($qualified_pk, $filtered_ids()))
            ->distinct()
            ->count($qualified_group);

        $page_query = $searched($joined()->whereIn($qualified_pk, $filtered_ids()))
            ->groupBy($qualified_group)
            ->selectRaw($qualified_group . ' as facet_key')
            ->selectRaw('count(distinct ' . $qualified_pk . ') as aggregate');

        $this->orderRelationFacet($page_query, $qualified_group, $facet->sort, $qualified_group);

        $rows = $page_query->forPage($facet->page, $facet->perPage)->get();

        $page_keys = [];
        $counts = [];

        foreach ($rows as $row) {
            $page_keys[] = $row->facet_key;
            $counts[$row->facet_key] = (int) $row->aggregate;
        }

        $totals = [];

        if ($page_keys !== []) {
            $total_rows = $joined()
                ->whereIn($qualified_pk, $acl_ids())
                ->whereIn($qualified_group, $page_keys)
                ->groupBy($qualified_group)
                ->selectRaw($qualified_group . ' as facet_key')
                ->selectRaw('count(distinct ' . $qualified_pk . ') as aggregate')
                ->get();

            foreach ($total_rows as $row) {
                $totals[$row->facet_key] = (int) $row->aggregate;
            }
        }

        $values = array_map(static fn (mixed $value): array => [
            'key' => $value,
            'total' => $totals[$value] ?? 0,
            'count' => $counts[$value] ?? 0,
            'attributes' => [$facet->groupBy => $value],
        ], $page_keys);

        return new FacetPage(array_values($values), $distinct_values, $facet->page, $facet->perPage);
    }

    /**
     * Fail fast when a facet's grouping target cannot be resolved to a real DB
     * column/relation, so a magic-accessor or typo yields a clear message instead of
     * a cryptic SQL error deep in the aggregate query.
     *
     * @param  array{related: Model, relatedTable: string, foreignKey: string, ownerKey: string, column: string}|null  $to_one
     */
    private function assertFacetResolvable(Model $model, FacetQuery $facet, ?array $to_one): void
    {
        if ($facet->relation !== null) {
            throw_if(
                $this->resolveManyRelation($model, $facet->relation) === null,
                InvalidArgumentException::class,
                sprintf("Facet relation '%s' is not a BelongsToMany/MorphToMany relation on %s.", $facet->relation, $model::class),
            );

            return;
        }

        if (str_contains($facet->groupBy, '.')) {
            throw_if(
                $to_one === null,
                InvalidArgumentException::class,
                sprintf("Facet groupBy '%s' is not a single-hop BelongsTo column on %s.", $facet->groupBy, $model::class),
            );

            $this->assertFacetColumnExists($model, $to_one['relatedTable'], $to_one['column'], $facet->groupBy);

            return;
        }

        $this->assertFacetColumnExists($model, $model->getTable(), $this->facetKey($facet->groupBy), $facet->groupBy);
    }

    /**
     * Assert a facet grouping column is a real column on its table (never a computed
     * accessor whose value lives on another entity).
     */
    private function assertFacetColumnExists(Model $model, string $table, string $column, string $group_by): void
    {
        throw_unless(
            $model->getConnection()->getSchemaBuilder()->hasColumn($table, $column),
            InvalidArgumentException::class,
            sprintf(
                "Facet groupBy '%s' resolves to column '%s.%s', which does not exist. If it is a computed accessor, group by its real column or relation path (e.g. 'place.country').",
                $group_by,
                $table,
                $column,
            ),
        );
    }

    /**
     * Resolve a `relation.column` group key to its single-hop to-one relation join,
     * or null when it is not a dotted path over a {@see BelongsTo}.
     *
     * @return array{related: Model, relatedTable: string, foreignKey: string, ownerKey: string, column: string}|null
     */
    private function resolveToOneColumn(Model $model, string $group_by): ?array
    {
        $dot = mb_strpos($group_by, '.');

        if ($dot === false || mb_strpos($group_by, '.', $dot + 1) !== false) {
            return null;
        }

        $relation_object = RelationGuard::relationOf($model->newInstance(), mb_substr($group_by, 0, $dot));

        if (! $relation_object instanceof BelongsTo) {
            return null;
        }

        return [
            'related' => $relation_object->getRelated(),
            'relatedTable' => $relation_object->getRelated()->getTable(),
            'foreignKey' => $relation_object->getForeignKeyName(),
            'ownerKey' => $relation_object->getOwnerKeyName(),
            'column' => mb_substr($group_by, $dot + 1),
        ];
    }

    /**
     * Bounded parent-id subquery: ACL plus the request filters (minus the facet's
     * own relation selection, when given), selecting only the parent key so it can
     * feed a `whereIn` without joining into the aggregated query.
     */
    private function relationParentIds(Request $request, Model $model, string $permission_name, ?FiltersGroup $filters, ?string $relation): BaseQueryBuilder
    {
        if ($filters instanceof FiltersGroup && $relation !== null) {
            $filters = $this->excludeFacetField($filters, $relation);
        }

        return $this->listedRowsQuery($request, $model, $permission_name, $filters)
            ->toBase()
            ->select($model->getTable() . '.' . $model->getKeyName());
    }

    /**
     * The rows of the model a list with these filters shows the caller: the row-level ACL, then the filters.
     *
     * @return Builder<Model>
     */
    private function listedRowsQuery(Request $request, Model $model, string $permission_name, ?FiltersGroup $filters): Builder
    {
        $query = $model->newQuery();
        $this->auth->applyAclFiltersToQuery($query, $permission_name);

        if ($filters instanceof FiltersGroup) {
            $relation_columns = [];
            $this->query_builder->applyFilters($query, $filters, $relation_columns, $this->relationAuthorizer(), $request);
        }

        return $query;
    }

    /**
     * Refuses, before anything is queried, a field of an aggregate request the engine may not read (spec 4.2, 4.5):
     * a measure field is a column of the entity itself; a group field is a column of the entity or of a single-hop
     * to-one relation (`customer.country`); a group label is a column of the to-one relation whose foreign key is the
     * group field; a period field is a column of the entity. The caller must be able to read each of them, a
     * measure must suit its column, and a period field and the field of a time bucket must be date columns. The
     * sort names no field: it follows the first measure or the group.
     *
     * @throws AggregateException
     */
    private function assertAggregateFields(Model $model, AggregateQuery $query): void
    {
        foreach ($query->measures as $index => $measure) {
            if ($measure->field !== null) {
                $this->assertAggregateColumn($model, $model, $measure->field, $measure->field, 'measures.' . $index . '.field');
            }
        }

        $group = $query->group;

        if ($group instanceof AggregateGroup) {
            if (str_contains($group->field, '.')) {
                $to_one = $this->resolveToOneColumn($model, $group->field);
                $this->assertAggregateColumn($model, $to_one['related'] ?? null, $to_one['column'] ?? '', $group->field, 'group_by.field');
            } else {
                $this->assertAggregateColumn($model, $model, $group->field, $group->field, 'group_by.field');
            }

            if ($group->label !== null) {
                $this->assertAggregateLabel($model, $group->field, $group->label);
            }
        }

        $period_field = null;

        if ($query->period !== null) {
            $period_field = $query->period['field'] ?? null;
            $period_field = is_string($period_field) ? $period_field : '';
            $this->assertAggregateColumn($model, $model, $period_field, $period_field, 'period.field');
        }

        foreach ($query->measures as $index => $measure) {
            $this->assertMeasurable($model, $measure, 'measures.' . $index . '.field');
        }

        if ($group instanceof AggregateGroup && $group->bucket instanceof TimeBucket) {
            $this->assertTemporal($model, $group->field, 'group_by.field');
        }

        if ($period_field !== null) {
            $this->assertTemporal($model, $period_field, 'period.field');
        }
    }

    /**
     * The filters of an aggregate obey the rules of its other fields (spec 4.2), each refused under
     * `filters.<property>`: a property of the entity itself exists first, as a column, a JSON path of one or a
     * property the filters read elsewhere ({@see ModelPropertyResolver}), and then the caller must be able to read
     * every name of the path of a property, through its relations. Checked before the relations the filters name
     * are authorized, whose guards refuse an unreadable column with the exception they raise for a name that is
     * not a relation.
     *
     * @throws AggregateException INVALID_FIELD when a property of the entity is none of those, UNREADABLE_FIELD when the caller may not read a name of a path
     */
    private function assertAggregateFilters(Model $model, FiltersGroup $filters): void
    {
        foreach (self::filterProperties($filters) as $property) {
            $field = 'filters.' . $property;

            if (
                ! str_contains($property, '.')
                && ! ColumnKinds::exists($model, Str::before($property, '->'))
                && ModelPropertyResolver::resolve($model, $property) === null
            ) {
                throw $this->unknownAggregateField($property, $field);
            }

            $this->assertReadableAggregatePath($model, $property, $field);
        }
    }

    /**
     * A period is measured on, and a time bucket cuts, a date or date-time column of the entity itself (spec 4.4).
     *
     * @throws AggregateException INVALID_FIELD
     */
    private function assertTemporal(Model $model, string $column, string $field): void
    {
        if (ColumnKinds::of($model, $column) !== ColumnKind::Temporal) {
            throw new AggregateException(
                AggregateErrorCode::InvalidField,
                sprintf('"%s" is not a date column of the entity: a period and a time bucket need a column of dates.', $column),
                $field,
            );
        }
    }

    /**
     * @param  Model|null  $owner  The model whose table holds the column; null when the path reaches no table.
     * @param  string  $path  The field as the request names it, read by the caller from the entity.
     * @param  string  $field  The place of the field in the request, for the error.
     *
     * @throws AggregateException INVALID_FIELD when the column is not on the table, UNREADABLE_FIELD when the caller may not read the path
     */
    private function assertAggregateColumn(Model $model, ?Model $owner, string $column, string $path, string $field): void
    {
        if (! $owner instanceof Model || ! ColumnKinds::exists($owner, $column)) {
            throw $this->unknownAggregateField($path, $field);
        }

        $this->assertReadableAggregatePath($model, $path, $field);
    }

    /**
     * A group label is a column of the to-one relation whose foreign key is the group field (spec 4.7): a
     * `BelongsTo`, or a label source the model declares. It is judged as the label resolver will read it: a
     * declared source whose translation resolves ({@see facetLabelSourceTranslation()}, which the resolver calls
     * on the same related model) reads its translation column, so that column is the only one a request may name
     * through it; any other target, a declared source whose translation does not resolve included, reads the
     * column of the related model.
     *
     * @throws AggregateException
     */
    private function assertAggregateLabel(Model $model, string $group_field, string $label): void
    {
        $target = $this->resolveLabelRelation($model, $group_field, $label);

        if ($target === null) {
            throw $this->unknownAggregateField($label, 'group_by.label');
        }

        $source = $model instanceof ProvidesFacetLabelSources ? ($model->facetLabelSources()[Str::before($label, '.')] ?? null) : null;
        $translation = $source instanceof FacetLabelSource
            && $group_field === $this->facetKey($source->foreignKey)
            && $target['related'] instanceof $source->relatedClass
                ? $this->facetLabelSourceTranslation($target['related'], $source)
                : null;

        $exists = $translation !== null
            ? $target['column'] === $translation['column']
            : ColumnKinds::exists($target['related'], $target['column']);

        if (! $exists) {
            throw $this->unknownAggregateField($label, 'group_by.label');
        }

        $this->assertReadableAggregatePath($model, $label, 'group_by.label');
    }

    /**
     * @throws AggregateException UNREADABLE_FIELD when the caller may not read the path
     */
    private function assertReadableAggregatePath(Model $model, string $path, string $field): void
    {
        try {
            ReadableColumnGuard::assertReadablePath($model, $path);
        } catch (InvalidArgumentException) {
            throw new AggregateException(AggregateErrorCode::UnreadableField, sprintf('"%s" cannot be read by this caller.', $path), $field);
        }
    }

    private function unknownAggregateField(string $path, string $field): AggregateException
    {
        return new AggregateException(AggregateErrorCode::InvalidField, sprintf('"%s" is not a column this aggregate can read.', $path), $field);
    }

    /**
     * A measure suits its column (spec 4.5): `count` any column, `min` and `max` a numeric or temporal one, `sum` and
     * `avg` a numeric one that is not a key.
     *
     * @throws AggregateException NOT_MEASURABLE
     */
    private function assertMeasurable(Model $model, AggregateMeasure $measure, string $field): void
    {
        if ($measure->field === null) {
            return;
        }

        $kind = ColumnKinds::of($model, $measure->field);

        $refusal = match ($measure->fn) {
            'min', 'max' => $kind === ColumnKind::Other ? 'min and max need a column of numbers or dates' : null,
            'sum', 'avg' => $kind !== ColumnKind::Numeric || ColumnKinds::isKey($model, $measure->field) ? 'sum and avg need a column of numbers that is not a key' : null,
            default => null,
        };

        if ($refusal !== null) {
            throw new AggregateException(
                AggregateErrorCode::NotMeasurable,
                sprintf('"%s" cannot be measured with %s: %s.', $measure->field, $measure->fn, $refusal),
                $field,
            );
        }
    }

    /**
     * `total` per related key: the distribution ignoring the request filters (ACL
     * only), restricted to the page's keys so it stays bounded.
     *
     * @param  callable(): BaseQueryBuilder  $pivot_query
     * @param  list<mixed>  $page_keys
     * @return array<mixed, int>
     */
    private function relationFacetTotals(callable $pivot_query, string $qualified_foreign, string $qualified_related, BaseQueryBuilder $acl_ids, array $page_keys): array
    {
        if ($page_keys === []) {
            return [];
        }

        $rows = $pivot_query()
            ->whereIn($qualified_foreign, $acl_ids)
            ->whereIn($qualified_related, $page_keys)
            ->groupBy($qualified_related)
            ->selectRaw($qualified_related . ' as facet_key')
            ->selectRaw('count(distinct ' . $qualified_foreign . ') as aggregate')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->facet_key] = (int) $row->aggregate;
        }

        return $totals;
    }

    /**
     * Resolve display fields for a relation facet's page keys as bounded reads over
     * the related table. Base columns only here; a translated label column is
     * resolved by {@see resolveTranslatedRelationLabels} in the caller.
     *
     * @param  list<mixed>  $page_keys
     * @param  list<string>  $fields
     * @return array<mixed, array<string, mixed>>
     */
    private function resolveRelationFacetLabels(Model $related, string $related_key, array $page_keys, array $fields): array
    {
        if ($fields === [] || $page_keys === []) {
            return [];
        }

        $columns = [];
        $translation_fields = [];

        foreach ($fields as $field) {
            if (! str_contains($field, '.')) {
                $columns[] = $field;

                continue;
            }

            $translation = $this->relationTranslationLabel($related, $field);

            if ($translation !== null) {
                $translation_fields[] = ['field' => $field, 'translation' => $translation];
            }
        }

        $resolved = [];

        $this->resolveRelationBaseLabels($related, $related_key, $page_keys, array_values(array_unique($columns)), $resolved);

        foreach ($translation_fields as $spec) {
            $this->resolveTranslatedRelationLabel($page_keys, $spec['field'], $spec['translation'], $resolved);
        }

        return $resolved;
    }

    /**
     * @param  list<mixed>  $page_keys
     * @param  list<string>  $columns
     * @param  array<mixed, array<string, mixed>>  $resolved
     */
    private function resolveRelationBaseLabels(Model $related, string $related_key, array $page_keys, array $columns, array &$resolved): void
    {
        if ($columns === []) {
            return;
        }

        // Read on the query builder (applying the related scopes, never Eloquent
        // hydration) so a heavy related model is not built from a partial select.
        $rows = $related->newQuery()
            ->toBase()
            ->whereIn($related->getTable() . '.' . $related_key, $page_keys)
            ->get(array_values(array_unique([$related_key, ...$columns])));

        foreach ($rows as $row) {
            $value = $row->{$related_key};

            foreach ($columns as $column) {
                $resolved[$value] ??= [];
                $resolved[$value][$column] ??= $row->{$column};
            }
        }
    }

    /**
     * Resolve a locale-scoped translated label for a relation facet's page keys in
     * one bounded read over the translation table.
     *
     * @param  list<mixed>  $page_keys
     * @param  array{model: Model, table: string, foreign: string, column: string, locale: string}  $translation
     * @param  array<mixed, array<string, mixed>>  $resolved
     */
    private function resolveTranslatedRelationLabel(array $page_keys, string $field, array $translation, array &$resolved): void
    {
        $rows = $translation['model']->newQuery()
            ->whereIn($translation['foreign'], $page_keys)
            ->where('locale', $translation['locale'])
            ->get([$translation['foreign'], $translation['column']]);

        foreach ($rows as $row) {
            $value = $row->{$translation['foreign']};
            $resolved[$value] ??= [];
            $resolved[$value][$field] ??= $row->{$translation['column']};
        }
    }

    /**
     * Parse a `relation.column` label spec into a locale-scoped translation join
     * target when `relation` is a {@see HasMany} translation relation on the related
     * model; null otherwise.
     *
     * @return array{model: Model, table: string, foreign: string, column: string, locale: string}|null
     */
    private function relationTranslationLabel(Model $related, string $spec): ?array
    {
        $dot = mb_strpos($spec, '.');

        if ($dot === false || mb_strpos($spec, '.', $dot + 1) !== false) {
            return null;
        }

        $relation_object = RelationGuard::relationOf($related->newInstance(), mb_substr($spec, 0, $dot));

        if (! $relation_object instanceof HasMany) {
            return null;
        }

        $translation_model = $relation_object->getRelated();

        return [
            'model' => $translation_model,
            'table' => $translation_model->getTable(),
            'foreign' => $relation_object->getForeignKeyName(),
            'column' => mb_substr($spec, $dot + 1),
            'locale' => LocaleContext::get(),
        ];
    }

    /**
     * Reflectively resolve a BelongsToMany/MorphToMany relation by name, or null
     * when it is absent or of another kind.
     */
    private function resolveManyRelation(Model $model, string $relation): ?BelongsToMany
    {
        if ($relation === '') {
            return null;
        }

        $object = RelationGuard::relationOf($model->newInstance(), $relation);

        return $object instanceof BelongsToMany ? $object : null;
    }

    /**
     * Order a relation facet's page: by the grouped count, the related key, or a
     * related label column (a real column in this joined query, so no subquery is
     * needed). A key tiebreak keeps count paging deterministic.
     */
    private function orderRelationFacet(BaseQueryBuilder $query, string $key, FacetSort $sort, ?string $label): void
    {
        // The label column is joined but not grouped: order by its per-group MIN so the
        // query stays valid under MySQL's only_full_group_by (one label per key anyway).
        $label_expression = $label !== null && $label !== $key
            ? 'min(' . $query->getGrammar()->wrap($label) . ')'
            : null;

        match ($sort) {
            FacetSort::CountDesc => $query->orderByRaw('aggregate desc')->orderBy($key),
            FacetSort::CountAsc => $query->orderByRaw('aggregate asc')->orderBy($key),
            FacetSort::KeyAsc => $query->orderBy($key),
            FacetSort::KeyDesc => $query->orderByDesc($key),
            FacetSort::LabelAsc => $label_expression !== null ? $query->orderByRaw($label_expression . ' asc')->orderBy($key) : $query->orderBy($label ?? $key),
            FacetSort::LabelDesc => $label_expression !== null ? $query->orderByRaw($label_expression . ' desc')->orderByDesc($key) : $query->orderByDesc($label ?? $key),
        };
    }

    /**
     * Validate the relation-sync payload against the model's whitelist and shape,
     * before any write, and normalize the id lists. A relation whose related entity
     * the writer may not read cannot be synced at all (spec 8.2).
     *
     * @param  array<string, mixed>  $relations
     *
     * @throws UnexpectedValueException when a relation is not whitelisted or is not many-to-many
     * @throws AuthorizationException when the writer may not read the related entity of a relation
     *
     * @return array<string, list<int>>
     */
    private function resolveSyncableRelations(Model $model, array $relations, Request $request): array
    {
        if ($relations === []) {
            return [];
        }

        $whitelist = $model instanceof ProvidesSyncableRelations ? $model->syncableRelations() : [];

        $resolved = [];

        foreach ($relations as $name => $ids) {
            throw_unless(
                is_string($name) && in_array($name, $whitelist, true),
                UnexpectedValueException::class,
                sprintf("Relation '%s' is not syncable on %s.", (string) $name, $model->getTable()),
            );

            $relation = $model->{$name}();

            throw_unless(
                $relation instanceof BelongsToMany,
                UnexpectedValueException::class,
                sprintf("Relation '%s' on %s is not a many-to-many relation.", $name, $model->getTable()),
            );
            throw_unless(
                $this->relationAuthorizer()->isReadable($request, $model, $relation->getRelated()),
                AuthorizationException::class,
                sprintf('User not allowed to read %s through %s', $relation->getRelated()->getTable(), $model->getTable()),
            );

            $resolved[$name] = $this->normalizeRelationIds(is_array($ids) ? $ids : []);
        }

        return $resolved;
    }

    /**
     * Sync each already-validated relation on a record from its list of ids.
     *
     * @param  array<string, list<int>>  $relations
     *
     * @throws AuthorizationException when a submitted id is outside the related records the writer can read
     */
    private function syncModelRelations(Request $request, Model $record, array $relations): void
    {
        foreach ($relations as $name => $ids) {
            $relation = $record->{$name}();

            if ($relation instanceof BelongsToMany) {
                $relation->sync($this->syncTarget($request, $record, $relation, $ids));
            }
        }
    }

    /**
     * The ids a relation is synced to: the submitted ids plus the attached records the writer cannot read, so a
     * hidden record is never detached and its pivot row is never touched. Additions and removals are thus
     * computed on the visible subset (spec 8.2). With no ACL narrowing the related entity, the submitted ids
     * are the target as they are.
     *
     * @param  BelongsToMany<Model, Model>  $relation
     * @param  list<int>  $submitted
     *
     * @throws AuthorizationException when a submitted id is outside the readable set, whether it is hidden or does not exist
     *
     * @return list<int>
     */
    private function syncTarget(Request $request, Model $record, BelongsToMany $relation, array $submitted): array
    {
        $related = $relation->getRelated();
        $key = $relation->getRelatedKeyName();
        $visible = $this->relationAuthorizer()->visibleKeysQuery($request, $record, $related, $key);

        if (! $visible instanceof Builder) {
            return $submitted;
        }

        $readable = static fn (array $ids): Builder => $related->newQueryWithoutScopes()
            ->whereIn($related->qualifyColumn($key), $ids)
            ->whereIn($related->qualifyColumn($key), $visible->toBase());

        $unreadable = $submitted !== [] && $readable($submitted)->count() !== count($submitted);

        throw_if(
            $unreadable,
            AuthorizationException::class,
            sprintf('User not allowed to attach records of %s they cannot read', $related->getTable()),
        );

        /** @var list<int|string> $attached */
        $attached = $relation->newPivotQuery()->pluck($relation->getRelatedPivotKeyName())->all();

        if ($attached === []) {
            return $submitted;
        }

        $readable_attached = $readable($attached)->pluck($key)->map(static fn (mixed $id): string => (string) $id)->all();
        $hidden = array_values(array_filter(
            $attached,
            static fn (int|string $id): bool => ! in_array((string) $id, $readable_attached, true),
        ));

        return array_values(array_unique([...$submitted, ...array_map(static fn (int|string $id): int => (int) $id, $hidden)]));
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return list<int>
     */
    private function normalizeRelationIds(array $ids): array
    {
        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));
    }

    /**
     * @param  array{related: Model, relatedTable: string, ownerKey: string, column: string}|null  $label_relation
     */
    private function orderFacetPage(BaseQueryBuilder $query, string $base_table, string $key, FacetSort $sort, ?array $label_relation): void
    {
        match ($sort) {
            // A stable key tiebreak keeps paging deterministic when counts tie.
            FacetSort::CountDesc => $query->orderByRaw('count(*) desc')->orderBy($key),
            FacetSort::CountAsc => $query->orderByRaw('count(*) asc')->orderBy($key),
            FacetSort::KeyAsc => $query->orderBy($key),
            FacetSort::KeyDesc => $query->orderByDesc($key),
            FacetSort::LabelAsc => $this->orderFacetByLabel($query, $base_table, $key, $label_relation, 'asc'),
            FacetSort::LabelDesc => $this->orderFacetByLabel($query, $base_table, $key, $label_relation, 'desc'),
        };
    }

    /**
     * Order a grouped facet page by a relation label without joining: a
     * correlated subquery yields each group's label (the label is functionally
     * dependent on the group key, so one row). With no resolvable label relation
     * it falls back to the key, so a label sort never silently does nothing.
     *
     * @param  array{related: Model, relatedTable: string, ownerKey: string, column: string}|null  $label_relation
     */
    private function orderFacetByLabel(BaseQueryBuilder $query, string $base_table, string $key, ?array $label_relation, string $direction): void
    {
        if ($label_relation === null) {
            $query->orderBy($key, $direction);

            return;
        }

        if (isset($label_relation['translation'])) {
            // Translated label: the translation row is keyed by the group key.
            $translation = $label_relation['translation'];
            $subquery = $translation['model']->newQuery()
                ->select($translation['column'])
                ->where('locale', $translation['locale'])
                ->whereColumn($translation['model']->getTable() . '.' . $translation['foreign'], $base_table . '.' . $key)
                ->limit(1)
                ->toBase();

            $query->orderBy($subquery, $direction);

            return;
        }

        $subquery = $label_relation['related']->newQuery()
            ->select($label_relation['column'])
            ->whereColumn($label_relation['relatedTable'] . '.' . $label_relation['ownerKey'], $base_table . '.' . $key)
            ->limit(1)
            ->toBase();

        $query->orderBy($subquery, $direction);
    }

    /**
     * Resolve a `relation.column` label target to its single-hop BelongsTo — only
     * when the relation's foreign key is the facet's group key, so the label can
     * be reached from the group key alone.
     *
     * @return array{related: Model, relatedTable: string, ownerKey: string, column: string}|null
     */
    private function resolveLabelRelation(Model $model, string $key, string $label_field): ?array
    {
        $dot = mb_strpos($label_field, '.');

        if ($dot === false || mb_strpos($label_field, '.', $dot + 1) !== false) {
            return null;
        }

        $relation = mb_substr($label_field, 0, $dot);
        $target = $this->labelRelationTarget($model, $key, $relation);

        return $target === null ? null : [...$target, 'column' => mb_substr($label_field, $dot + 1)];
    }

    /**
     * Resolve the label target a facet's group key can reach in a single hop:
     * either a declared {@see BelongsTo} whose foreign key is the group key, or a
     * {@see FacetLabelSource} the model registers for a foreign key exposed without
     * a relation (e.g. through an accessor). Both are keyed by the group key alone,
     * so no join enters the aggregated query.
     *
     * @return array{related: Model, relatedTable: string, ownerKey: string}|null
     */
    private function labelRelationTarget(Model $model, string $key, string $relation): ?array
    {
        // A model-declared source wins: it is an explicit choice and can express a
        // translated label that a BelongsTo alone cannot.
        $declared = $this->declaredLabelTarget($model, $key, $relation);

        if ($declared !== null) {
            return $declared;
        }

        if (method_exists($model, $relation)) {
            $relation_object = RelationGuard::relationOf($model->newInstance(), $relation);

            if ($relation_object instanceof BelongsTo && $key === $this->facetKey($relation_object->getForeignKeyName())) {
                $related = $relation_object->getRelated();

                return [
                    'related' => $related,
                    'relatedTable' => $related->getTable(),
                    'ownerKey' => $relation_object->getOwnerKeyName(),
                ];
            }
        }

        return null;
    }

    /**
     * Build a label target from a model-declared {@see FacetLabelSource} when its
     * foreign key is the facet's group key.
     *
     * @return array{related: Model, relatedTable: string, ownerKey: string}|null
     */
    private function declaredLabelTarget(Model $model, string $key, string $relation): ?array
    {
        if (! $model instanceof ProvidesFacetLabelSources) {
            return null;
        }

        $source = $model->facetLabelSources()[$relation] ?? null;

        if (! $source instanceof FacetLabelSource || $key !== $this->facetKey($source->foreignKey)) {
            return null;
        }

        $related = new $source->relatedClass;

        if (! $related instanceof Model) {
            return null;
        }

        $target = [
            'related' => $related,
            'relatedTable' => $related->getTable(),
            'ownerKey' => $source->ownerKey,
        ];

        $translation = $this->facetLabelSourceTranslation($related, $source);

        if ($translation !== null) {
            $target['translation'] = $translation;
        }

        return $target;
    }

    /**
     * Resolve a declared source's locale-scoped translation target (the related
     * model's HasMany translation relation), keyed by the facet group key since the
     * translation's foreign key equals the related id equals the group key value.
     *
     * @return array{model: Model, foreign: string, column: string, locale: string}|null
     */
    private function facetLabelSourceTranslation(Model $related, FacetLabelSource $source): ?array
    {
        if ($source->translationRelation === null || $source->translationColumn === null) {
            return null;
        }

        if (! method_exists($related, $source->translationRelation)) {
            return null;
        }

        try {
            $relation_object = $related->{$source->translationRelation}();
        } catch (Throwable) {
            return null;
        }

        if (! $relation_object instanceof HasMany) {
            return null;
        }

        return [
            'model' => $relation_object->getRelated(),
            'foreign' => $relation_object->getForeignKeyName(),
            'column' => $source->translationColumn,
            'locale' => LocaleContext::get(),
        ];
    }

    /**
     * `total` per key: the value distribution ignoring the request filters (ACL
     * only), restricted to the page's keys so it stays bounded.
     *
     * @param  list<mixed>  $page_keys
     * @return array<mixed, int>
     */
    private function facetTotals(Model $model, string $permission_name, string $key, array $page_keys): array
    {
        if ($page_keys === []) {
            return [];
        }

        $query = $model->newQuery();
        $this->auth->applyAclFiltersToQuery($query, $permission_name);

        $rows = $query->toBase()
            ->reorder()
            ->select($key)
            ->selectRaw('count(*) as aggregate')
            ->whereIn($key, $page_keys)
            ->groupBy($key)
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[$row->{$key}] = (int) $row->aggregate;
        }

        return $totals;
    }

    /**
     * The key≠label second step: resolve the requested display fields per key in
     * bounded reads over the page's keys — never a join into the aggregated query,
     * so `GROUP BY` stays on one portable column. Base-table columns resolve
     * directly; a single-dot `relation.column` resolves through a single-hop
     * {@see BelongsTo} whose foreign key is the facet key (e.g. group by
     * `license_id`, label `license.uuid`). Multi-hop paths and non-BelongsTo
     * relations are skipped; label search/sort remain deferred.
     *
     * @param  list<mixed>  $page_keys
     * @param  list<string>  $fields
     * @return array<mixed, array<string, mixed>>
     */
    private function resolveFacetLabels(Model $model, string $key, array $page_keys, array $fields, Request $request): array
    {
        if ($fields === [] || $page_keys === []) {
            return [];
        }

        $columns = [];
        $relation_fields = [];

        foreach ($fields as $field) {
            $dot = mb_strpos($field, '.');

            if ($dot === false) {
                $columns[] = $field;

                continue;
            }

            // Single-hop only: a second dot is a multi-hop path, deferred.
            if (mb_strpos($field, '.', $dot + 1) !== false) {
                continue;
            }

            $relation = mb_substr($field, 0, $dot);
            $relation_fields[$relation][] = ['field' => $field, 'column' => mb_substr($field, $dot + 1)];
        }

        $resolved = [];

        $this->resolveBaseColumnLabels($model, $key, $page_keys, array_values(array_unique($columns)), $resolved);

        foreach ($relation_fields as $relation => $specs) {
            $this->resolveRelationLabels($request, $model, $key, $page_keys, $relation, $specs, $resolved);
        }

        return $resolved;
    }

    /**
     * @param  list<mixed>  $page_keys
     * @param  list<string>  $columns
     * @param  array<mixed, array<string, mixed>>  $resolved
     */
    private function resolveBaseColumnLabels(Model $model, string $key, array $page_keys, array $columns, array &$resolved): void
    {
        if ($columns === []) {
            return;
        }

        $rows = $model->newQuery()
            ->whereIn($key, $page_keys)
            ->get(array_values(array_unique([$key, ...$columns])));

        foreach ($rows as $row) {
            $value = $row->{$key};

            foreach ($columns as $column) {
                $resolved[$value] ??= [];
                $resolved[$value][$column] ??= $row->{$column};
            }
        }
    }

    /**
     * @param  list<mixed>  $page_keys
     * @param  list<array{field: string, column: string}>  $specs
     * @param  array<mixed, array<string, mixed>>  $resolved
     */
    private function resolveRelationLabels(Request $request, Model $model, string $key, array $page_keys, string $relation, array $specs, array &$resolved): void
    {
        // Only a single-hop label target whose key is the facet key can be resolved
        // from the page's keys without a join (a BelongsTo or a declared source).
        $target = $this->labelRelationTarget($model, $key, $relation);

        if ($target === null) {
            return;
        }

        // The label is read on the related entity: its permission and ACL apply, and a hidden record gives no label.
        $visible = $this->visibleRelatedKeys($request, $model, $target['related'], $target['ownerKey']);

        if (isset($target['translation'])) {
            $this->resolveTranslatedFacetLabels($target['translation'], $page_keys, $specs, $resolved, $visible);

            return;
        }

        $owner_key = $target['ownerKey'];
        $columns = array_column($specs, 'column');

        $related = $target['related']->newQuery()
            ->whereIn($owner_key, $page_keys)
            ->when($visible !== null, fn (Builder $query): Builder => $query->whereIn($owner_key, $visible))
            ->get(array_values(array_unique([$owner_key, ...$columns])))
            ->keyBy($owner_key);

        foreach ($page_keys as $page_key) {
            $row = $related->get($page_key);

            foreach ($specs as $spec) {
                $resolved[$page_key] ??= [];
                $resolved[$page_key][$spec['field']] = $row?->{$spec['column']};
            }
        }
    }

    /**
     * Resolve a base-column FK facet's translated labels for the page keys: the
     * translation row is keyed by the group key, so one bounded locale-scoped read
     * fills every requested field with the translated column.
     *
     * @param  array{model: Model, foreign: string, column: string, locale: string}  $translation
     * @param  list<mixed>  $page_keys
     * @param  list<array{field: string, column: string}>  $specs
     * @param  array<mixed, array<string, mixed>>  $resolved
     * @param  BaseQueryBuilder|null  $visible  the related keys the caller may see; null when nothing narrows them
     */
    private function resolveTranslatedFacetLabels(array $translation, array $page_keys, array $specs, array &$resolved, ?BaseQueryBuilder $visible = null): void
    {
        $rows = $translation['model']->newQuery()
            ->whereIn($translation['foreign'], $page_keys)
            ->when($visible !== null, fn (Builder $query): Builder => $query->whereIn($translation['foreign'], $visible))
            ->where('locale', $translation['locale'])
            ->get([$translation['foreign'], $translation['column']])
            ->keyBy($translation['foreign']);

        foreach ($page_keys as $page_key) {
            $row = $rows->get($page_key);

            foreach ($specs as $spec) {
                $resolved[$page_key] ??= [];
                $resolved[$page_key][$spec['field']] = $row?->{$translation['column']};
            }
        }
    }

    /**
     * Rebuild a FiltersGroup without the nodes targeting the given facet field, so a
     * facet's own selection does not suppress the counts of its other values.
     */
    private function excludeFacetField(FiltersGroup $filters, string $field): FiltersGroup
    {
        $kept = [];

        foreach ($filters->filters as $node) {
            if ($node instanceof FiltersGroup) {
                $kept[] = $this->excludeFacetField($node, $field);

                continue;
            }

            if ($node instanceof Filter && $this->facetFieldMatches($node->property, $field)) {
                continue;
            }

            $kept[] = $node;
        }

        return new FiltersGroup($kept, $filters->operator);
    }

    private function facetFieldMatches(string $property, string $field): bool
    {
        if ($property === $field) {
            return true;
        }

        // Relation facet: the field is the relation name and its selection targets a
        // column on it (field `categories`, property `categories.id`), so the whole
        // relation membership filter is the facet's own selection.
        if (str_contains($property, '.') && $field === mb_strstr($property, '.', true)) {
            return true;
        }

        return $this->facetKey($property) === $this->facetKey($field);
    }

    /**
     * The bare column name (last dot segment) used as the facet's response key.
     */
    private function facetKey(string $field): string
    {
        $position = mb_strrpos($field, '.');

        return $position === false ? $field : mb_substr($field, $position + 1);
    }

    /**
     * @param  array<int, string>  $key_columns
     * @return array{id: mixed, updated_at: string|null}
     */
    private function mapFreshnessRow(object $row, array $key_columns, ?string $updated_at_column): array
    {
        $id = count($key_columns) === 1
            ? $row->{$key_columns[0]}
            : array_map(static fn (string $key): mixed => $row->{$key}, $key_columns);

        return [
            'id' => $id,
            'updated_at' => $this->formatFreshnessUpdatedAt(
                is_string($updated_at_column) && $updated_at_column !== ''
                    ? ($row->{$updated_at_column} ?? null)
                    : null,
            ),
        ];
    }

    private function formatFreshnessUpdatedAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // toBase() returns MySQL "Y-m-d H:i:s" (app TZ). Normalize to ISO-8601 UTC
            // so clients can compare with Eloquent JSON timestamps without false positives.
            return Date::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<int|string>
     */
    private function normalizeFreshnessCheckIds(ListRequestData $requestData): array
    {
        $raw = $requestData->request->input('check_ids', []);

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            if (is_int($value) || is_string($value)) {
                $ids[] = $value;
            } elseif (is_numeric($value)) {
                $ids[] = (string) $value;
            }

            if (count($ids) >= 100) {
                break;
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    /**
     * Classify snapshot ids against the filtered set (no page window) and current page items.
     *
     * @param  list<array{id: mixed, updated_at: string|null}>  $items
     * @param  string|array<int, string>  $key_name
     * @param  array<int, string>  $key_columns
     * @param  list<int|string>  $check_ids
     * @return list<array{id: int|string, status: 'on_page'|'off_page'|'gone', updated_at: string|null}>
     */
    private function buildFreshnessPresence(
        Builder $filtered_query,
        array $items,
        string|array $key_name,
        array $key_columns,
        string $table,
        ?string $updated_at_column,
        array $check_ids,
    ): array {
        if ($check_ids === [] || is_array($key_name)) {
            return [];
        }

        $page_ids = [];

        foreach ($items as $item) {
            $page_ids[(string) $item['id']] = true;
        }

        $found_rows = (clone $filtered_query)
            ->whereIn($table . '.' . $key_name, $check_ids)
            ->toBase()
            ->get();

        $found_by_id = [];

        foreach ($found_rows as $row) {
            $mapped = $this->mapFreshnessRow($row, $key_columns, $updated_at_column);
            $found_by_id[(string) $mapped['id']] = $mapped;
        }

        $presence = [];

        foreach ($check_ids as $id) {
            $key = (string) $id;

            if (! isset($found_by_id[$key])) {
                $presence[] = [
                    'id' => $id,
                    'status' => 'gone',
                    'updated_at' => null,
                ];

                continue;
            }

            $presence[] = [
                'id' => $found_by_id[$key]['id'],
                'status' => isset($page_ids[$key]) ? 'on_page' : 'off_page',
                'updated_at' => $found_by_id[$key]['updated_at'],
            ];
        }

        return $presence;
    }

    private function assertCrudWriteAllowed(Model $model, string $operation): void
    {
        if ($model instanceof RestrictsCrudWrites && in_array($operation, $model->deniedCrudWrites(), true)) {
            throw CrudWriteNotAllowedException::for($model, $operation);
        }
    }

    private function useRecursiveRelationships(Model $model): bool
    {
        return class_uses_trait($model, HasRecursiveRelationships::class);
    }

    private function useHasApproval(Model $model): bool
    {
        return class_uses_trait($model, HasApprovals::class);
    }

    private function hasHistory(Model $model): bool
    {
        return class_uses_trait($model, HasVersions::class);
    }

    /**
     * Null only on a vote that names its modifications instead of a record (a pending create).
     *
     * @return array<string, mixed>|string|int|null
     */
    private function getModelKeyValue(ModifyRequestData $filters): array|string|int|null
    {
        /** @var string|array<int,string> $key */
        $key = $this->getModelPrimaryKeyName($filters->model);

        if (is_string($key)) {
            return $filters->{$key};
        }

        $key_value = array_flip($key);

        foreach ($key as $k) {
            $key_value[$k] = $filters->{$k};
        }

        return $key_value;
    }

    /**
     * Normalize key value to an array suitable for Builder::where() (column => value or [col => val, ...]).
     *
     * @param  array<string, mixed>|string|int  $keyValue
     * @return array<string, mixed>
     */
    private function keyValueToWhereCondition(Model $model, array|string|int $keyValue): array
    {
        return is_array($keyValue) ? $keyValue : [$model->getKeyName() => $keyValue];
    }

    /**
     * Filter discarded-value messages so that request-metadata keys (filters, primary key) are not reported as errors.
     *
     * @param  array<int, string>  $discardedMessages
     */
    private function filterExpectedDiscardedForError(array $discardedMessages, ModifyRequestData $requestData): ?string
    {
        $pk_keys = is_array($requestData->primaryKey) ? $requestData->primaryKey : [$requestData->primaryKey];
        $expected = array_merge(['filters'], $pk_keys);
        $unexpected = array_filter($discardedMessages, static fn (string $msg): bool => array_all($expected, fn (string|int $key): bool => ! str_contains($msg, "'{$key}'")));

        return $unexpected === [] ? null : implode(', ', $unexpected);
    }

    private function searchWithScout(SearchRequestData $requestData, string $permissionName): CrudResult
    {
        $model = $requestData->model;

        if (is_array($model->getKeyName())) {
            return new CrudResult(
                data: null,
                error: 'Full-search operation does not support composite primary keys yet.',
                statusCode: Response::HTTP_BAD_REQUEST,
            );
        }

        /** @var class-string<Model> $model_class */
        $model_class = $model::class;
        $builder = $model_class::search($requestData->qs);

        if ($model instanceof ProvidesDefaultSearchFilters) {
            foreach ($model->defaultSearchFilters() as $field => $value) {
                $builder->where($field, $value);
            }
        }

        $request_filters = $requestData->filters;
        $this->auth->injectAclFilters($requestData, $permissionName);

        try {
            $this->searchConstraintApplier()->apply($builder, $model, $requestData->filters, $requestData->sort);
        } catch (InvalidArgumentException $exception) {
            return $this->invalidSearchConstraintsResult($exception->getMessage());
        }

        if ($requestData->page !== null) {
            $paginator = $builder->paginate($requestData->pagination, 'page', $requestData->page);
            $search_results = $paginator->getCollection();
            $total_records = $paginator->total();
        } else {
            $search_results = $builder->take($requestData->limit)->get();
            $total_records = $search_results->count();
        }

        $ids = $search_results
            ->map(static fn (Model $record): mixed => $record->getKey())
            ->filter(static fn (mixed $key): bool => $key !== null && $key !== '')
            ->values();

        if ($ids->isEmpty()) {
            return new CrudResult(
                data: new Collection(),
                meta: $this->buildSearchMeta($requestData, 0, 0),
            );
        }

        $query = $this->searchHitsQuery($model, $ids->all(), $permissionName, $request_filters, $requestData);
        $this->query_builder->applyRequestedRelations($query, $requestData, $this->relationAuthorizer());

        $records = $query->get();

        $records_by_key = $records->keyBy(static fn (Model $record): string => (string) $record->getKey());
        $records = new Collection(
            $ids
                ->map(static fn (mixed $id): ?Model => $records_by_key->get((string) $id))
                ->filter()
                ->values()
                ->all(),
        );

        $this->applyComputedMethods($records, $requestData);

        $data = $requestData->group_by !== []
            ? $this->applyGroupBy($records, $requestData->group_by)
            : $records;

        $current_records = $data->count();

        return new CrudResult(
            data: $data,
            meta: $this->buildSearchMeta($requestData, $total_records, $current_records),
        );
    }

    private function searchWithAdvanced(SearchRequestData $requestData, string $permissionName): CrudResult
    {
        $advanced_search = $this->advanced_search ?? app(AdvancedSearchService::class);

        if (! $advanced_search->available($requestData->model)) {
            return new CrudResult(
                data: null,
                error: 'Orchestrated search pipeline is not available for the configured search driver.',
                statusCode: Response::HTTP_NOT_IMPLEMENTED,
            );
        }

        if ($requestData->group_by !== []) {
            return $this->invalidSearchConstraintsResult('Orchestrated search does not support CRUD group_by yet.');
        }

        $request_filters = $requestData->filters;
        $this->auth->injectAclFilters($requestData, $permissionName);

        $cost_guard = app(SearchCostGuard::class);
        $withheld_reason = $requestData->cost === SearchDepth::Deep && ! $cost_guard->allowsDeep($this->searchPersonKey($requestData))
            ? SearchCostGuard::RATE_LIMITED
            : null;

        try {
            $result = $advanced_search->search(
                $requestData->model,
                $requestData->qs,
                $requestData->page ?? 1,
                max(1, $requestData->page !== null ? $requestData->pagination : $requestData->limit),
                $requestData->filters,
                $requestData->sort,
                $requestData->matching,
                $requestData->matching_options,
                $requestData->cost,
                $cost_guard->clampRetries($requestData->retry),
                $withheld_reason,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->invalidSearchConstraintsResult($exception->getMessage());
        }

        return $this->searchResultFromAdvancedResult($requestData, $result, $permissionName, $request_filters);
    }

    /**
     * Who a deep search is counted against: the signed-in person, else the address the request came from.
     */
    private function searchPersonKey(SearchRequestData $requestData): string
    {
        $user = $requestData->request->user();

        return $user !== null ? 'user:' . $user->getAuthIdentifier() : 'ip:' . $requestData->request->ip();
    }

    /**
     * @param  FiltersGroup|null  $requestFilters  The filters of the request, without the ACL filters merged in
     */
    private function searchResultFromAdvancedResult(SearchRequestData $requestData, AdvancedSearchResult $result, string $permissionName, ?FiltersGroup $requestFilters = null): CrudResult
    {
        $model = $requestData->model;
        $ids = $result->ids();

        if ($ids === []) {
            return new CrudResult(
                data: new Collection(),
                meta: $this->buildAdvancedSearchMeta($requestData, $result, 0),
            );
        }

        $query = $this->searchHitsQuery($model, $ids, $permissionName, $requestFilters, $requestData);
        $this->query_builder->applyRequestedRelations($query, $requestData, $this->relationAuthorizer());

        $records = $query->get();
        $records_by_key = $records->keyBy(static fn (Model $record): string => (string) $record->getKey());
        $data = new Collection(
            collect($ids)
                ->map(static fn (string $id): ?Model => $records_by_key->get($id))
                ->filter()
                ->values()
                ->all(),
        );

        $this->applyComputedMethods($data, $requestData);

        return new CrudResult(
            data: $data,
            meta: $this->buildAdvancedSearchMeta($requestData, $result, $data->count()),
        );
    }

    /**
     * The records behind the hits of a search, reloaded by key and authorized again.
     *
     * The ACL row filters of the user reach the engine as filters, but they are not the only barrier: a
     * strategy that dropped them (the text half of a hybrid search once did), or an index that is out of
     * date, would otherwise let a hit through that the user may not see, since only a model that implements
     * {@see IAuthorizesSearchRehydration} checked it again. The same filters are applied to this database
     * query, which is the semantics they have everywhere else in the CRUD service, and the model's own
     * rehydration guard comes on top.
     *
     * The filters of the request are applied by the engine, except the nodes no engine takes (a scope, which
     * only the database can run, and the OR group holding it): that part is applied here, so a request scope
     * narrows the hits as it narrows a list. The ACL filters need no such part: they are applied here whole.
     *
     * @param  array<int, mixed>  $ids
     * @param  FiltersGroup|null  $requestFilters  The filters of the request, without the ACL filters merged in
     * @return Builder<Model>
     */
    private function searchHitsQuery(Model $model, array $ids, string $permissionName, ?FiltersGroup $requestFilters = null, ?SearchRequestData $requestData = null): Builder
    {
        $query = $model->newQuery()->whereKey($ids);

        $this->auth->applyAclFiltersToQuery($query, $permissionName);

        $database_only = $requestFilters instanceof FiltersGroup ? ScoutSearchConstraintApplier::databaseOnlyPart($requestFilters) : null;

        // applyFilters() joins the group as one nested condition, so an OR group never widens the key and ACL conditions.
        if ($database_only instanceof FiltersGroup) {
            $relation_columns = [];
            $this->query_builder->applyFilters($query, $database_only, $relation_columns, $this->relationAuthorizer(), $requestData?->request);
        }

        if ($model instanceof IAuthorizesSearchRehydration) {
            $query = $model->authorizeSearchRehydration($query);
        }

        return $query;
    }

    private function searchConstraintApplier(): ScoutSearchConstraintApplier
    {
        return $this->search_constraint_applier ?? app(ScoutSearchConstraintApplier::class);
    }

    private function invalidSearchConstraintsResult(string $message): CrudResult
    {
        return new CrudResult(
            data: null,
            error: $message,
            statusCode: Response::HTTP_BAD_REQUEST,
        );
    }

    private function buildSearchMeta(SearchRequestData $requestData, int $totalRecords, int $currentRecords): CrudMeta
    {
        $model = $requestData->model;

        return new CrudMeta(
            totalRecords: $totalRecords,
            currentRecords: $currentRecords,
            currentPage: $requestData->page,
            totalPages: $requestData->page !== null ? $requestData->calculateTotalPages($totalRecords) : null,
            pagination: $requestData->pagination,
            from: $requestData->from,
            to: $requestData->to,
            class: $model::class,
            table: $model->getTable(),
            cachedAt: Date::now(),
        );
    }

    private function buildAdvancedSearchMeta(SearchRequestData $requestData, AdvancedSearchResult $result, int $currentRecords): CrudMeta
    {
        $model = $requestData->model;

        return new CrudMeta(
            totalRecords: $result->total,
            currentRecords: $currentRecords,
            currentPage: $result->page,
            totalPages: $result->totalPages,
            pagination: $result->perPage,
            from: $requestData->from,
            to: $requestData->to,
            class: $model::class,
            table: $model->getTable(),
            cachedAt: Date::now(),
            search: $result->meta,
        );
    }

    private function doApproveOperation(ModifyRequestData $requestData, string $operation): CrudResult
    {
        $model = $requestData->model;
        $this->assertCrudWriteAllowed($model, $operation);
        $permission_name = $this->auth->ensurePermission($requestData->request, $model->getTable(), 'approve', $model->getConnectionName());

        $requested = $requestData->changes['modification'] ?? null;
        $reason = $requestData->changes['reason'] ?? null;
        $vote_reason = is_string($reason) ? $reason : null;

        // A pending create has no record yet: it is named by its modification alone.
        $key_value = $this->getModelKeyValue($requestData);
        $has_record_key = is_array($key_value)
            ? array_filter($key_value, static fn (mixed $value): bool => $value !== null && $value !== '') !== []
            : $key_value !== null && $key_value !== '';
        throw_if(! $has_record_key && ! is_array($requested), InvalidArgumentException::class, 'A record id or the modifications to vote on are required.');

        $found_record = $has_record_key
            ? $this->aclFiltered(
                $this->newQueryWithTrashed($model)->where($this->keyValueToWhereCondition($model, $key_value)),
                $permission_name,
            )->firstOrFail()
            : null;

        $user = Auth::user();
        throw_unless($user instanceof User, LogicException::class, 'Authenticated user is required.');
        $connection = ($found_record ?? $model)->getConnection();
        $modification_prototype = (new Modification())->setConnection($connection->getName());

        $connection->transaction(function () use ($model, $found_record, $user, $operation, $modification_prototype, $requested, $vote_reason): void {
            $modifications = $modification_prototype->newQuery()
                ->where('modifiable_type', ($found_record ?? $model)::class)
                ->when(
                    $found_record instanceof Model,
                    static fn (Builder $query): Builder => $query->where('modifiable_id', $found_record?->getKey()),
                    static fn (Builder $query): Builder => $query->whereNull('modifiable_id'),
                );

            if (is_array($requested)) {
                // Only the named requests, and every one of them must belong to this record.
                $modifications = $modifications->whereKey($requested)->lockForUpdate()->get();

                throw_if($modifications->count() !== count($requested), ModelNotFoundException::class, 'No modification found for this record with the given id');
                throw_if($modifications->contains(static fn (Modification $modification): bool => ! $modification->active), DomainException::class, 'A decided request cannot be voted on.');
            } else {
                $modifications = $modifications->activeOnly()->oldest()->lockForUpdate()->get();

                throw_if($modifications->isEmpty(), LogicException::class, sprintf('No modifications to be %sd', $operation));
            }

            foreach ($modifications as $modification) {
                $this->castApprovalVote($user, $modification, $found_record, $operation, $vote_reason);
            }
        });

        if (! $found_record instanceof Model) {
            return new CrudResult(
                data: ['modifications' => array_values($requested ?? [])],
            );
        }

        $found_record->refresh();

        return new CrudResult(
            data: $found_record,
        );
    }

    /**
     * The request a write on this record was just turned into, or null when the write ran.
     */
    private function pendingRequestOf(Model $record): ?Modification
    {
        return method_exists($record, 'pendingModification') ? $record->pendingModification() : null;
    }

    /**
     * A write sent for approval answers 202 with the request. A single-record write carries the
     * request itself; a multi-record one lists the requests next to what was applied directly.
     *
     * @param  non-empty-list<Modification>  $captured
     */
    private function capturedResult(array $captured, mixed $applied = null, ?string $error = null): CrudResult
    {
        $requests = array_map(
            static fn (Modification $modification): array => ['modification' => $modification->getKey(), 'operation' => $modification->operation->value],
            $captured,
        );

        return new CrudResult(
            data: $applied === null && count($requests) === 1 ? $requests[0] : ['modifications' => $requests, 'applied' => $applied],
            error: $error,
            statusCode: Response::HTTP_ACCEPTED,
        );
    }

    /**
     * @param  "approve"|"disapprove"  $operation
     */
    private function castApprovalVote(User $user, Modification $modification, ?Model $modifiable, string $operation, ?string $reason): void
    {
        resolve(ModificationVoteService::class)->cast($user, $modification, $operation === 'approve', $reason, $modifiable);
    }

    /**
     * One verb, three deliberately different acts.
     *
     * A **lease** is what the edit form takes: an owned, expiring lock that says "I am working on
     * this". It needs no permission of its own beyond `update`, the same right the form already
     * required, and it is what every caller gets unless it asks for something else. A **hold** is an
     * owned lock with no deadline, and a **freeze** is an ownerless one that blocks everybody;
     * both are deliberate administrative acts and both need `lock`.
     *
     * Nothing here is a heartbeat. The client re-calls this operation and reads the answer: an
     * unchanged `affected_records` means the lock it holds is still its own and still valid, and in
     * that case not a single column is written.
     *
     * @param  "lock"|"unlock"  $operation
     */
    private function doLockOperation(ModifyRequestData $requestData, string $operation): CrudResult
    {
        $model = $requestData->model;
        $this->assertCrudWriteAllowed($model, $operation);

        // Through Locked, not the trait alone: a table whose `lock_{table}` setting is
        // off is a table without locks, and asking to take one on it is the same
        // mistake as asking on a model that never had them.
        throw_unless(new Locked()->usesHasLocks($model), BadMethodCallException::class, $model::class . " doesn't support locks");

        $request = $requestData->request;
        $is_single = $request->has('id');
        $intent = $operation === 'lock' ? $this->resolveLockIntent($requestData) : null;

        $key_value = $this->getModelKeyValue($requestData);
        $query = $model->newQuery()->where($this->keyValueToWhereCondition($model, $key_value));

        if ($intent instanceof LockIntent) {
            $this->aclFiltered($query, $intent->permission_name);
        }

        $found_records = $query->lazy(100);
        $found_count = 0;
        $affected_records = new Collection();

        $model->getConnection()->transaction(function () use ($found_records, $affected_records, $intent, $requestData, $is_single, &$found_count): void {
            foreach ($found_records as $found_record) {
                $found_count++;

                $changed = $intent instanceof LockIntent
                    ? $this->applyLock($found_record, $intent, $is_single)
                    : $this->applyUnlock($found_record, $requestData, $is_single);

                if ($changed) {
                    $affected_records->add($found_record->fresh());
                }
            }
        });
        throw_if($found_count === 0, ModelNotFoundException::class, 'No model Found');

        return new CrudResult(
            data: $affected_records,
        );
    }

    /**
     * Reads what the caller is asking for, and authorizes it.
     *
     * The permission is chosen by the shape of the lock being asked for, not by who is asking: a
     * user who happens to hold `lock` still only takes a lease when it opens an edit form, because
     * the form does not ask to freeze anything.
     */
    private function resolveLockIntent(ModifyRequestData $requestData): LockIntent
    {
        $model = $requestData->model;
        $request = $requestData->request;
        $table = $model->getTable();
        $connection = $model->getConnectionName();

        $until_is_explicit = $request->has('locked_until');
        $until = $until_is_explicit ? $this->parseLockDeadline($request->input('locked_until')) : null;

        if ($request->boolean('freeze')) {
            $permission_name = $this->auth->ensurePermission($request, $table, 'lock', $connection);

            return new LockIntent(
                freeze: true,
                user_id: null,
                until: $until,
                until_is_explicit: true,
                permission_name: $permission_name,
            );
        }

        $user = Auth::user();

        // Without an owner the write would produce a freeze, so an anonymous caller asking for a
        // lease would silently block the record for everyone. Refuse rather than degrade.
        throw_unless(
            $user instanceof Authenticatable,
            AuthorizationException::class,
            'An anonymous caller cannot take a lock: a lock with no owner blocks the record for everybody.',
        );

        $permission_name = $this->auth->ensurePermission($request, $table, 'update', $connection);

        if (! $until_is_explicit) {
            $until = now()->addSeconds(new Locked()->leaseTtl());
        } elseif (! $until instanceof DateTimeInterface) {
            // An owned lock with no deadline is a hold: it never lapses on its own, so it is an
            // administrative act rather than part of the edit lifecycle.
            $this->auth->ensurePermission($request, $table, 'lock', $connection);
        }

        return new LockIntent(
            freeze: false,
            user_id: (int) $user->getAuthIdentifier(),
            until: $until,
            until_is_explicit: $until_is_explicit,
            permission_name: $permission_name,
        );
    }

    private function parseLockDeadline(mixed $value): ?DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        throw_unless(is_string($value) || is_numeric($value), InvalidArgumentException::class, 'locked_until must be a date.');

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            throw new InvalidArgumentException('locked_until is not a valid date.');
        }
    }

    /**
     * @return bool whether anything was written
     */
    private function applyLock(ILockableModel&Model $record, LockIntent $intent, bool $is_single): bool
    {
        $locked = new Locked();
        $owner = $record->getAttribute($locked->lockedByColumn());
        $owner_id = $owner === null ? null : (int) $owner;

        if (! $record->isLocked()) {
            $record->lock($intent->freeze ? null : $this->lockOwner($intent), $intent->until);

            return true;
        }

        $held_by_caller = $owner_id !== null && $owner_id === $intent->user_id;
        $frozen = $owner_id === null;

        if ($intent->freeze && $frozen) {
            return $this->moveDeadline($record, $intent, $locked);
        }

        if (! $held_by_caller) {
            // Somebody else's lease, or a freeze. Reporting it is only useful when the caller named
            // a single record; a bulk call skips what it may not touch and says so by omission.
            throw_if($is_single, LockedModelException::class, $this->lockConflictMessage($record, $locked));

            return false;
        }

        if ($intent->freeze) {
            // The caller is turning its own lease into a freeze: a different lock, so it starts now.
            $record->lock(null, $intent->until);

            return true;
        }

        return $this->moveDeadline($record, $intent, $locked);
    }

    /**
     * Applies the deadline of an intent to a lock the caller already holds.
     *
     * The lease deadline is fixed when the lock is taken, not rolling. This method is only reached
     * when the caller's own lock is still valid, so an implicit refresh has nothing to do: writing
     * the configured TTL again would rewrite the row on every poll, would move a deadline the user
     * never asked to move, and would silently shorten a lock deliberately held for longer. Doing
     * nothing is what makes the periodic re-lock a pure read.
     *
     * An explicit deadline is different: it is an assignment, and the lock is the caller's own to
     * shorten or extend.
     */
    private function moveDeadline(Model $record, LockIntent $intent, Locked $locked): bool
    {
        if (! $intent->until_is_explicit) {
            return false;
        }

        $current = $record->getAttribute($locked->lockedUntilColumn());
        $current = $current === null ? null : Carbon::parse($current);

        if ($current === null && $intent->until === null) {
            return false;
        }

        if ($current !== null && $intent->until !== null && $current->equalTo($intent->until)) {
            return false;
        }

        $record->setLockDeadline($intent->until);

        return true;
    }

    /**
     * @return bool whether anything was written
     */
    private function applyUnlock(ILockableModel&Model $record, ModifyRequestData $requestData, bool $is_single): bool
    {
        if (! $record->isLocked()) {
            // Already in the target state. Not an error, and not a 304 either: the envelope says so
            // by returning no affected record.
            return false;
        }

        $locked = new Locked();
        $owner = $record->getAttribute($locked->lockedByColumn());
        $user = Auth::user();
        $held_by_caller = $owner !== null
            && $user instanceof Authenticatable
            && (int) $owner === (int) $user->getAuthIdentifier();

        if ($held_by_caller) {
            // Releasing your own lock is the owner's right and needs no permission of its own.
            $record->forceUnlock();

            return true;
        }

        $model = $requestData->model;
        $permission_name = $this->auth->ensurePermission(
            $requestData->request,
            $model->getTable(),
            'unlock',
            $model->getConnectionName(),
        );

        if (! $this->recordMatchesAcl($record, $permission_name)) {
            throw_if($is_single, LockedModelException::class, $this->lockConflictMessage($record, $locked));

            return false;
        }

        $record->forceUnlock();

        return true;
    }

    /**
     * The optimistic version the client claims to be holding, if any.
     *
     * Only honoured when the request names a single record. A version belongs to one row that one
     * client read; applying it to every row a bulk criteria matched would either fail at random or,
     * worse, pass and silently overwrite rows the client never saw.
     */
    private function requestedLockVersion(ModifyRequestData $requestData): ?int
    {
        if (! $requestData->request->has('id')) {
            return null;
        }

        $version = $requestData->changes['lock_version'] ?? $requestData->request->input('lock_version');

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * Narrows a write query to the rows the caller's ACL actually reaches.
     *
     * The entity-level check says the caller may perform the operation at all; without this, every
     * write path resolved its rows by key alone, so a row-level ACL that hid a record from a list
     * did nothing to stop the same user updating, deleting or approving it by id.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    /**
     * Apply a permission's ACL when the permission exists. The pending-approvals lists read
     * permissions the user need not hold, and a permission nobody defined has no ACL to apply.
     */
    private function aclFilteredIfDefined(Builder $query, string $permission_name): Builder
    {
        return $this->permissionExists($permission_name) ? $this->aclFiltered($query, $permission_name) : $query;
    }

    private function permissionExists(string $permission_name): bool
    {
        return Permission::query()->where('name', $permission_name)->exists();
    }

    private function aclFiltered(Builder $query, string $permission_name): Builder
    {
        $this->auth->applyAclFiltersToQuery($query, $permission_name);

        return $query;
    }

    /**
     * Whether the record is still reachable once the ACL row filters of a permission are applied.
     *
     * The entity-level check says the caller may perform the operation at all; this says whether it
     * may perform it on *this* row.
     */
    private function recordMatchesAcl(Model $record, string $permission_name): bool
    {
        $query = $record->newQueryWithoutScopes()->whereKey($record->getKey());
        $this->auth->applyAclFiltersToQuery($query, $permission_name);

        return $query->exists();
    }

    private function lockOwner(LockIntent $intent): ?Authenticatable
    {
        $user = Auth::user();

        return $intent->freeze ? null : $user;
    }

    private function lockConflictMessage(Model $record, Locked $locked): string
    {
        $owner = $record->getAttribute($locked->lockedByColumn());
        $until = $record->getAttribute($locked->lockedUntilColumn());

        $who = $owner === null
            ? 'The record is frozen'
            : sprintf('The record is locked by user #%s', $owner);

        return $until === null
            ? $who . ' and the lock has no expiry.'
            : sprintf('%s until %s.', $who, Carbon::parse($until)->toIso8601String());
    }

    private function applyComputedMethods(mixed $data, ListRequestData|SelectRequestData $request_data): void
    {
        $methods_by_relation = $this->extractMethodColumns($request_data);

        if ($methods_by_relation === []) {
            return;
        }

        if ($data instanceof Model) {
            $this->applyMethodsToModel($data, $methods_by_relation);

            return;
        }

        if (is_iterable($data)) {
            foreach ($data as $model) {
                if ($model instanceof Model) {
                    $this->applyMethodsToModel($model, $methods_by_relation);
                }
            }
        }
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function extractMethodColumns(SelectRequestData $request_data): array
    {
        $methods_by_relation = [];
        $main_entity = $request_data->model->getTable();

        foreach ($request_data->columns ?? [] as $column) {
            if ($column->type !== ColumnType::Method) {
                continue;
            }

            $index = str_replace($main_entity . '.', '', $column->name);
            $splitted = preg_split('/\.(?=[^.]*$)/', $index, 2);

            if ($splitted === false) {
                continue;
            }

            $relation = $splitted[1] ?? null ? $splitted[0] : '';
            $method = $splitted[1] ?? $splitted[0];

            if (! array_key_exists($relation, $methods_by_relation)) {
                $methods_by_relation[$relation] = [];
            }

            if (! in_array($method, $methods_by_relation[$relation], true)) {
                $methods_by_relation[$relation][] = $method;
            }
        }

        return $methods_by_relation;
    }

    /**
     * @param  array<string,array<int,string>>  $methods_by_relation
     */
    private function applyMethodsToModel(Model $model, array $methods_by_relation): void
    {
        foreach ($methods_by_relation as $relation_path => $methods) {
            if ($relation_path === '') {
                $this->applyMethodsToTarget($model, $methods);

                continue;
            }

            $this->applyMethodsToRelationPath($model, $relation_path, $methods);
        }
    }

    /**
     * @param  array<int,string>  $methods
     */
    private function applyMethodsToTarget(Model $model, array $methods): void
    {
        foreach ($methods as $method) {
            $value = $this->resolveMethodValue($model, $method);
            $model->setAttribute($method, $value);
        }
    }

    /**
     * @param  array<int,string>  $methods
     */
    private function applyMethodsToRelationPath(Model $model, string $relation_path, array $methods): void
    {
        $segments = explode('.', $relation_path);
        $targets = [$model];

        foreach ($segments as $segment) {
            $next_targets = [];

            foreach ($targets as $target) {
                // Only a relation the query loaded: reading an unloaded one by name would make Eloquent call
                // the same-named method, whatever it is.
                if (! $target->relationLoaded($segment)) {
                    continue;
                }

                $related = $target->getRelation($segment);

                if ($related instanceof Model) {
                    $next_targets[] = $related;

                    continue;
                }

                if (is_iterable($related)) {
                    foreach ($related as $item) {
                        if ($item instanceof Model) {
                            $next_targets[] = $item;
                        }
                    }
                }
            }

            if ($next_targets === []) {
                return;
            }

            $targets = $next_targets;
        }

        foreach ($targets as $target) {
            $this->applyMethodsToTarget($target, $methods);
        }
    }

    private function resolveMethodValue(Model $model, string $method): mixed
    {
        throw_unless(method_exists($model, $method), UnexpectedValueException::class, sprintf('Method %s not found on %s', $method, $model::class));

        throw_if($this->methodRequiresParameters($model, $method), UnexpectedValueException::class, sprintf('Method %s requires parameters on %s', $method, $model::class));

        throw_unless(ComputedColumnGuard::isSafeMethod($model, $method), UnexpectedValueException::class, sprintf('Method %s cannot be computed on %s', $method, $model::class));

        return $model->{$method}();
    }

    private function methodRequiresParameters(Model $model, string $method): bool
    {
        return self::$method_requires_parameters_cache[$model::class . '::' . $method]
            ??= (new ReflectionMethod($model, $method))->getNumberOfRequiredParameters() > 0;
    }

    /**
     * @return string|array<int, string>
     */
    private function getModelPrimaryKeyName(Model $model): array|string
    {
        /** @var string|array<int, string> $key */
        return $model->getKeyName();
    }

    private function resolveKeyFromRequest(Request $request, string $key): mixed
    {
        if ($request instanceof FormRequest && $this->hasBeenValidated($request)) {
            $validated = $request->validated($key);

            if ($validated !== null && $validated !== '') {
                return $validated;
            }
        }

        return $request->input($key) ?? $request->route($key);
    }

    /**
     * Whether the form request went through validation. A caller that builds one by hand, as the AI
     * assistant's tools do, never runs it, and `validated()` on such a request fails on a null validator.
     */
    private function hasBeenValidated(FormRequest $request): bool
    {
        return (fn (): bool => $this->validator !== null)->call($request);
    }

    /**
     * @return Builder<Model>
     */
    private function newQueryWithTrashed(Model $model): Builder
    {
        $query = $model->newQuery();
        $traits = class_uses_recursive($model::class);

        if (in_array(CoreSoftDeletes::class, $traits, true)) {
            $query->withoutGlobalScope(CustomSoftDeletingScope::class);

            return $query;
        }

        if (in_array(EloquentSoftDeletes::class, $traits, true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }
}
