<?php

declare(strict_types=1);

namespace Modules\Core\Casts;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Exceptions\ScopeFilterException;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Support\BooleanInput;
use Modules\Core\Support\ScopeGuard;

class ListRequestData extends SelectRequestData
{
    public protected(set) int $pagination;

    public protected(set) ?int $page = null;

    public protected(set) ?int $skip = null;

    public protected(set) ?int $take = null;

    public protected(set) ?int $from = null;

    public protected(set) ?int $to = null;

    public protected(set) ?int $limit;

    public protected(set) bool $count;

    /**
     * Whether the paginated list should compute the exact total (`COUNT(*)`).
     * The real (parsed) default is false — the page path skips the count and
     * reports `hasMore` instead; opt in with `totals=true` for page numbers.
     * The property initializer is only the fallback when the DTO is built without
     * the constructor (e.g. reflection in tests), where counted stays the default.
     */
    public protected(set) bool $totals = true;

    /**
     * @var array<int, Sort>
     */
    public protected(set) array $sort;

    public protected(set) ?FiltersGroup $filters;

    /**
     * Whether a node of the client filters, at any depth, has a `relation` key. The parser has no relation node: it
     * reads such a node as the group of its `filters`, applied to the main record. The aggregate refuses a request
     * that holds one ({@see \Modules\Core\Services\Crud\CrudService::aggregate()}) instead of answering a figure
     * of other rows.
     */
    public protected(set) bool $hasRelationFilterNode = false;

    /**
     * @var array<int, string>
     */
    public protected(set) array $group_by = [];

    /**
     * @var list<string>|null
     */
    private ?array $boolean_attributes = null;

    /**
     * @param  string|array<string>  $primaryKey
     * @param  array<string, mixed>  $validated
     */
    public function __construct(ListRequest $request, string $mainEntity, array $validated, string|array $primaryKey, ?string $module = null)
    {
        parent::__construct($request, $mainEntity, $validated, $primaryKey, $module);

        $this->extractPagination($validated);

        if (! isset($this->limit)) {
            $this->limit = $this->intFromMixed($validated['limit'] ?? null, $this->pagination);
        }

        $this->count = isset($validated['count']) && (bool) $validated['count'];
        $this->totals = array_key_exists('totals', $validated) && (bool) $validated['totals'];
        $this->sort = $this->conformSorts($this->sortInput($validated['sort'] ?? []));
        $this->relations = $this->conformRelations($this->stringList($validated['relations'] ?? []));

        $filters = $validated['filters'] ?? null;

        if (is_array($filters)) {
            $this->filters = $this->parseFilters($filters);
        } else {
            $this->filters = null;
        }

        $group_by = $this->stringList($validated['group_by'] ?? []);

        if ($group_by !== []) {
            $this->addGroupsToColumns($group_by);
            $this->group_by = array_map(
                static fn (string $group): string => preg_replace(sprintf('/^%s\./', $mainEntity), '', $group) ?? $group,
                $group_by,
            );
        }
    }

    public function calculateTotalPages(int $totalRecords): int
    {
        $per_page = max(1, $this->pagination);

        return (int) ceil($totalRecords / $per_page);
    }

    /**
     * Merge additional filters (e.g., ACL filters) with existing filters.
     *
     * If no existing filters, the new filters become the only filters.
     * If existing filters exist, they are wrapped with the new filters using AND operator.
     *
     * This ensures mandatory filters (like ACL restrictions) cannot be bypassed.
     *
     * @param  FiltersGroup  $filters  The filters to merge (typically ACL filters)
     */
    public function mergeFilters(FiltersGroup $filters): void
    {
        if (! $this->filters instanceof FiltersGroup) {
            $this->filters = $filters;

            return;
        }

        // Wrap existing filters with new filters using AND
        // Result: new_filters AND existing_filters
        $this->filters = new FiltersGroup(
            filters: [$filters, $this->filters],
            operator: WhereClause::And,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function extractPagination(array $validated): void
    {
        if (isset($validated['pagination']) || isset($validated['page'])) {
            $default_pagination = $this->getDefaultPagination();
            $this->take = $this->intFromMixed($validated['pagination'] ?? null, $default_pagination);
            $this->pagination = $this->intFromMixed($validated['pagination'] ?? null, $default_pagination);
            $this->page = $this->intFromMixed($validated['page'] ?? null, 1);
            $this->skip = ($this->page - 1) * $this->pagination;
            $this->from = $this->skip + 1;
            // `to` is the inclusive index of the last row on the page, so a page of
            // `pagination` rows spans [from, from + pagination - 1]. listByPagination
            // slices with take(to - from + 1), which must equal `pagination`.
            $this->to = $this->from + $this->pagination - 1;
        } elseif (isset($validated['from']) || isset($validated['to'])) {
            $this->from = $this->intFromMixed($validated['from'] ?? null, 1);
            $this->skip = $this->from - 1;
            $this->to = array_key_exists('to', $validated) ? $this->intFromMixed($validated['to'], 0) : null;

            if ($this->to !== null && $this->to !== 0) {
                $this->take = $this->to - $this->from;
                $this->pagination = $this->to - $this->from;
            }
        } elseif (isset($validated['limit'])) {
            $this->take = $this->intFromMixed($validated['limit'], 1);
            $this->limit = $this->intFromMixed($validated['limit'], 1);
            $this->page = 1;
            $this->skip = 0;
            $this->pagination = $this->limit;
        } else {
            $this->page = 1;
            $this->skip = 0;
            $this->take = $this->getDefaultPagination();
            $this->pagination = $this->take;
            // `page` is set, so list() routes to listByPagination, which needs a
            // populated from/to window; without it the slice would collapse to a
            // single row (take(null - null + 1)).
            $this->from = 1;
            $this->to = $this->from + $this->pagination - 1;
        }
    }

    /**
     * A filter without an operator is an equality; one that names an operator must name one the request accepts, in
     * either spelling ({@see FilterOperator::tryFromRequestOperator()}). Reading an unknown one as equals would answer
     * over the wrong rows.
     *
     * @param  array<string, mixed>  $filter
     *
     * @throws ValidationException when the filter names an operator that is in neither spelling
     */
    protected function conformFilterOperators(array &$filter): void
    {
        if (array_key_exists('operator', $filter)) {
            $operator = FilterOperator::tryFromRequestValue($filter['operator']);

            if (! $operator instanceof FilterOperator) {
                throw ValidationException::withMessages(['filters' => 'Unknown filter operator. Use one of: ' . FilterOperator::requestVocabulary() . '.']);
            }

            $filter['operator'] = $operator;
        } elseif (array_key_exists('value', $filter)) {
            $filter['operator'] = FilterOperator::Equals;
        }
    }

    /**
     * @param  array{property:string,value:mixed,operator:FilterOperator}  $filter
     */
    protected function conformFilterValue(array &$filter): void
    {
        if ($filter['value'] === 'null' || $filter['value'] === null) {
            $filter['value'] = null;
        } elseif (in_array($filter['operator'], [FilterOperator::Like, FilterOperator::NotLike], true)) {
            $value = $filter['value'];

            if (is_string($value) && ! Str::startsWith($value, '%') && ! Str::endsWith($value, '%')) {
                $filter['value'] = '%' . $value . '%';
            }
        } elseif ($filter['operator'] === FilterOperator::In && is_string($filter['value'])) {
            $filter['value'] = is_json($filter['value']) ? json_decode($filter['value'], true) : explode(',', $filter['value']);
        }

        if (! $this->isBooleanFilterProperty($filter['property'])) {
            return;
        }

        if (is_array($filter['value'])) {
            $filter['value'] = array_map(
                static fn (mixed $item): mixed => BooleanInput::coerce($item),
                $filter['value'],
            );

            return;
        }

        $filter['value'] = BooleanInput::coerce($filter['value']);
    }

    private function intFromMixed(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int, string|array{property:string,direction:SortDirection|string}>
     */
    private function sortInput(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $sorts = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $sorts[] = $item;

                continue;
            }

            if (! is_array($item) || ! isset($item['property']) || ! is_string($item['property'])) {
                continue;
            }

            $direction = $item['direction'] ?? SortDirection::Asc;

            if (! is_string($direction) && ! $direction instanceof SortDirection) {
                $direction = SortDirection::Asc;
            }

            $sorts[] = [
                'property' => $item['property'],
                'direction' => $direction,
            ];
        }

        return $sorts;
    }

    private function isBooleanFilterProperty(string $property): bool
    {
        $bare = str_contains($property, '.') ? Str::afterLast($property, '.') : $property;

        return in_array($bare, $this->booleanAttributes(), true);
    }

    /**
     * @return list<string>
     */
    private function booleanAttributes(): array
    {
        return $this->boolean_attributes ??= BooleanInput::attributeNames($this->model);
    }

    /**
     * @param  array<mixed, mixed>  $raw
     */
    private function parseFilters(array $raw): FiltersGroup
    {
        $parsed = $this->conformFilterNode($raw, 0);

        if ($parsed instanceof FiltersGroup) {
            return $parsed;
        }

        if (is_array($parsed)) {
            return new FiltersGroup($parsed);
        }

        return new FiltersGroup([$parsed]);
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return Filter|FiltersGroup|ScopeFilter|array<int, Filter|FiltersGroup|ScopeFilter>
     */
    private function conformFilterNode(array $raw, int $level): Filter|FiltersGroup|ScopeFilter|array
    {
        if (Arr::isList($raw)) {
            $result = [];

            foreach ($raw as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $conformed = $this->conformFilterNode($item, $level + 1);

                if ($conformed instanceof Filter || $conformed instanceof FiltersGroup || $conformed instanceof ScopeFilter) {
                    $result[] = $conformed;
                } elseif (is_array($conformed)) {
                    array_push($result, ...$conformed);
                }
            }

            if ($level === 0) {
                return new FiltersGroup($result);
            }

            return $result;
        }

        if (array_key_exists('relation', $raw)) {
            $this->hasRelationFilterNode = true;
        }

        if (array_key_exists('scope', $raw)) {
            return $this->conformScopeNode($raw);
        }

        if (Arr::has($raw, 'filters')) {
            $nested = $raw['filters'];

            if (! is_array($nested)) {
                return new FiltersGroup([]);
            }

            $operator = WhereClause::And;

            if (($raw['operator'] ?? null) !== null) {
                $operator = WhereClause::tryFromRequestValue($raw['operator']);

                if (! $operator instanceof WhereClause) {
                    throw ValidationException::withMessages(['filters' => 'Unknown filter group operator. Use one of: ' . WhereClause::requestVocabulary() . '.']);
                }
            }

            $conformed = $this->conformFilterNode($nested, $level + 1);
            $filters_list = is_array($conformed) ? $conformed : [$conformed];

            return new FiltersGroup($filters_list, $operator);
        }

        $filter = $raw;
        $this->conformFilterOperators($filter);
        $property = $filter['property'] ?? null;
        $operator = $filter['operator'] ?? FilterOperator::Equals;

        if (! is_string($property) || ! $operator instanceof FilterOperator) {
            return new Filter('', null, FilterOperator::Equals);
        }

        $typed_filter = [
            'property' => $property,
            'value' => $filter['value'] ?? null,
            'operator' => $operator,
        ];
        $this->conformFilterValue($typed_filter);

        return new Filter($typed_filter['property'], $typed_filter['value'], $typed_filter['operator']);
    }

    /**
     * A scope node of the request. Every tree this parser reads is client input (ACL filters arrive as objects through
     * {@see mergeFilters()}), and not every caller validates it first, so the parser is the gate: the node must name a
     * scope the model exposes to clients, with parameters taken literally ({@see ScopeGuard::assertClientFilterable()}).
     *
     * @param  array<mixed, mixed>  $raw
     *
     * @throws ValidationException when the node is malformed or may not be used by a client; the message names the
     *                             scope and the reason, never the model class nor a parameter value
     */
    private function conformScopeNode(array $raw): ScopeFilter
    {
        $scope = $raw['scope'];
        $parameters = $raw['parameters'] ?? [];

        if (! is_string($scope) || $scope === '' || ! is_array($parameters) || ! Arr::isList($parameters)) {
            throw ValidationException::withMessages(['filters' => 'A scope filter names a scope and lists its parameters.']);
        }

        $filter = new ScopeFilter($scope, $parameters);

        try {
            ScopeGuard::assertClientFilterable($this->model, $filter);
        } catch (ScopeFilterException $exception) {
            throw ValidationException::withMessages(['filters' => $exception->getMessage()]);
        }

        return $filter;
    }

    /**
     * @param  array<int, string>  $relations
     * @return array<int, string>
     */
    private function conformRelations(array $relations): array
    {
        return array_map(function (string $relation): string {
            $result = preg_replace(
                [sprintf('/^%s\./', $this->mainEntity), sprintf('/^%s\./', $this->model->getTable())],
                '',
                $relation,
            );

            return $result ?? $relation;
        }, $relations);
    }

    private function getDefaultPagination(): int
    {
        $value = app(PerModelSettingResolver::class)->int('crud.pagination', 25);

        return max(1, $value);
    }

    /**
     * @param  array<int, string|array{property:string,direction:SortDirection|string}>  $sorts
     * @return array<int, Sort>
     */
    private function conformSorts(array $sorts): array
    {
        $result = [];

        foreach ($sorts as $value) {
            if (is_string($value)) {
                $result[] = new Sort($value);
            } else {
                $result[] = new Sort($value['property'], $value['direction'] ?? SortDirection::Asc);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function addGroupsToColumns(array $groups): void
    {
        if ($this->columns !== []) {
            $all_columns_name = array_map(fn (Column $column): string => $column->name, $this->columns);

            foreach ($groups as $group) {
                if (! in_array($group, $all_columns_name, true)) {
                    $this->columns[] = new Column($group);
                }
            }
        }
    }
}
