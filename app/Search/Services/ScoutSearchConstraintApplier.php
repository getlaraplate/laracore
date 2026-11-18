<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Search\Exceptions\UnsupportedSearchEngineException;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use ReflectionMethod;

final readonly class ScoutSearchConstraintApplier
{
    public const string FILTER_ERROR = 'Search filters must be applied by the search engine to keep pagination consistent.';

    public const string SORT_ERROR = 'Search sort must be applied by the search engine to keep pagination consistent.';

    /**
     * The part of a filter tree the engine never receives, to be applied when the hits are reloaded from the
     * database: the complement of {@see withoutDatabaseOnlyNodes()}, so that the engine filters AND this part
     * select exactly the rows the whole tree selects.
     *
     * An AND group gives the database-only nodes it holds (and the database-only part of each child group); an
     * OR group that lost anything on the way to the engine is given whole, since the engine received it wider
     * than it is, or not at all. Null when the engine receives the whole tree.
     *
     * @throws InvalidArgumentException when the tree holds a node kind this applier does not know
     */
    public static function databaseOnlyPart(FiltersGroup $filters): ?FiltersGroup
    {
        if ($filters->operator === WhereClause::Or) {
            return self::holdsDatabaseOnlyNode($filters) ? $filters : null;
        }

        $left_out = [];

        foreach ($filters->filters as $node) {
            if ($node instanceof RelationFilter || $node instanceof ScopeFilter) {
                $left_out[] = $node;

                continue;
            }

            if ($node instanceof FiltersGroup) {
                $part = self::databaseOnlyPart($node);

                if ($part instanceof FiltersGroup) {
                    $left_out[] = $part;
                }

                continue;
            }

            // The engine takes a column filter.
            self::columnFilter($node);
        }

        return $left_out === [] ? null : new FiltersGroup($left_out, WhereClause::And);
    }

    /**
     * @param  array<int, \Modules\Core\Casts\Sort>  $sort
     */
    public function apply(mixed $builder, Model $model, ?FiltersGroup $filters = null, array $sort = []): void
    {
        $filters = $filters instanceof FiltersGroup ? self::withoutDatabaseOnlyNodes($filters) : null;

        if ($filters instanceof FiltersGroup) {
            $this->applyFilters($builder, $filters, $model);
        }

        foreach ($sort as $item) {
            $field = $this->fieldName($model, $item->property);

            if ($field === null) {
                throw new InvalidArgumentException(self::SORT_ERROR);
            }

            $builder->orderBy($field, $item->direction->value);
        }
    }

    /**
     * Whether the engine of the model filters on the property: the check {@see apply()} makes before it pushes a
     * column filter, which refuses the search when it fails.
     */
    public function isFilterable(Model $model, string $property): bool
    {
        return $this->filterField($model, $property) !== null;
    }

    /**
     * The filters the engine can take. Two node kinds are never pushed to it: a relation filter (a condition on a
     * related record) and a scope filter (a model scope, which only the database can run). The records behind the
     * hits are checked against them when they are reloaded ({@see databaseOnlyPart()}).
     *
     * A condition left out means "no engine condition", which only ever widens the hits, so it is dropped
     * from an AND group. Dropping it from an OR group would narrow the branch it belongs to, so the whole group
     * is left out instead. Null when nothing is left to push.
     *
     * @throws InvalidArgumentException when the tree holds a node kind this applier does not know: skipping it
     *                                  could drop a condition no reload restores
     */
    private static function withoutDatabaseOnlyNodes(FiltersGroup $filters): ?FiltersGroup
    {
        $kept = [];

        foreach ($filters->filters as $node) {
            $pushable = match (true) {
                $node instanceof RelationFilter, $node instanceof ScopeFilter => null,
                $node instanceof FiltersGroup => self::withoutDatabaseOnlyNodes($node),
                default => self::columnFilter($node),
            };

            if ($pushable === null) {
                if ($filters->operator === WhereClause::Or) {
                    return null;
                }

                continue;
            }

            $kept[] = $pushable;
        }

        return $kept === [] ? null : new FiltersGroup($kept, $filters->operator);
    }

    /**
     * Whether a group holds, at any depth, a node the engine never receives.
     */
    private static function holdsDatabaseOnlyNode(FiltersGroup $filters): bool
    {
        foreach ($filters->filters as $node) {
            if ($node instanceof RelationFilter || $node instanceof ScopeFilter) {
                return true;
            }

            if ($node instanceof FiltersGroup) {
                if (self::holdsDatabaseOnlyNode($node)) {
                    return true;
                }

                continue;
            }

            self::columnFilter($node);
        }

        return false;
    }

    /**
     * A node of none of the other kinds must be a column filter. A node kind this applier does not know is
     * refused: skipping it could drop a condition that no reload restores.
     *
     * @throws InvalidArgumentException when the node is not a column filter
     */
    private static function columnFilter(mixed $node): Filter
    {
        if (! $node instanceof Filter) {
            throw self::unknownNode($node);
        }

        return $node;
    }

    private static function unknownNode(mixed $node): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The search engine cannot take a %s filter node.', get_debug_type($node)));
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function applyAdvancedFiltersToEloquent(mixed $query, array $group): void
    {
        self::applyAdvancedFiltersGroupToEloquent($query, $group);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private static function applyAdvancedFiltersGroupToEloquent(mixed $query, array $group, string $boolean = 'and'): void
    {
        $method = $boolean === WhereClause::Or->value ? 'orWhere' : 'where';

        $query->{$method}(static function (mixed $nested_query) use ($group): void {
            $child_boolean = ($group['operator'] ?? WhereClause::And->value) === WhereClause::Or->value
                ? WhereClause::Or->value
                : WhereClause::And->value;

            foreach (($group['filters'] ?? []) as $filter) {
                if (! is_array($filter)) {
                    continue;
                }

                if (isset($filter['filters'])) {
                    self::applyAdvancedFiltersGroupToEloquent($nested_query, $filter, $child_boolean);

                    continue;
                }

                self::applyAdvancedFilterToEloquent($nested_query, $filter, $child_boolean);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private static function applyAdvancedFilterToEloquent(mixed $query, array $filter, string $boolean): void
    {
        if (is_string($filter['relation'] ?? null) && is_string($filter['relation_field'] ?? null)) {
            self::applyRelationAdvancedFilterToEloquent($query, $filter, $boolean);

            return;
        }

        $field = (string) ($filter['field'] ?? '');
        $operator = (string) ($filter['operator'] ?? '');
        $value = $filter['value'] ?? null;
        $where = $boolean === WhereClause::Or->value ? 'orWhere' : 'where';
        $where_in = $boolean === WhereClause::Or->value ? 'orWhereIn' : 'whereIn';
        $where_not_in = $boolean === WhereClause::Or->value ? 'orWhereNotIn' : 'whereNotIn';
        $where_between = $boolean === WhereClause::Or->value ? 'orWhereBetween' : 'whereBetween';

        match ($operator) {
            FilterOperator::Equals->value => $query->{$where}($field, '=', $value),
            FilterOperator::In->value => $query->{$where_in}($field, is_array($value) ? $value : [$value]),
            FilterOperator::NotEquals->value => $query->{$where_not_in}($field, is_array($value) ? $value : [$value]),
            FilterOperator::Great->value,
            FilterOperator::GreatEquals->value,
            FilterOperator::Less->value,
            FilterOperator::LessEquals->value => $query->{$where}($field, $operator, $value),
            FilterOperator::Between->value => $query->{$where_between}($field, is_array($value) ? array_values($value) : [$value, $value]),
            default => throw new InvalidArgumentException(self::FILTER_ERROR),
        };
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private static function applyRelationAdvancedFilterToEloquent(mixed $query, array $filter, string $boolean): void
    {
        $relation = (string) $filter['relation'];
        $relation_field = (string) $filter['relation_field'];
        $operator = (string) ($filter['operator'] ?? '');
        $value = $filter['value'] ?? null;
        $negative = $operator === FilterOperator::NotEquals->value;
        $method = match (true) {
            $negative && $boolean === WhereClause::Or->value => 'orWhereDoesntHave',
            $negative => 'whereDoesntHave',
            $boolean === WhereClause::Or->value => 'orWhereHas',
            default => 'whereHas',
        };

        $query->{$method}($relation, static function (mixed $relation_query) use ($relation_field, $operator, $value): void {
            if ($operator === FilterOperator::NotEquals->value) {
                if (is_array($value)) {
                    $relation_query->whereIn($relation_field, $value);

                    return;
                }

                $relation_query->where($relation_field, '=', $value);

                return;
            }

            self::applyAdvancedFilterToEloquent($relation_query, [
                'field' => $relation_field,
                'operator' => $operator,
                'value' => $value,
            ], WhereClause::And->value);
        });
    }

    private function applyFilters(mixed $builder, FiltersGroup $filters, Model $model): void
    {
        if ($filters->operator === WhereClause::Or) {
            $this->appendAdvancedFilter($builder, $this->normalizeFiltersGroup($filters, $model));

            return;
        }

        foreach ($filters->filters as $filter) {
            if ($filter instanceof FiltersGroup) {
                if ($filter->operator === WhereClause::Or) {
                    $this->appendAdvancedFilter($builder, $this->normalizeFiltersGroup($filter, $model));

                    continue;
                }

                $this->applyFilters($builder, $filter, $model);

                continue;
            }

            // Only column filters are left: withoutDatabaseOnlyNodes() took out every other node kind.
            if (! $filter instanceof Filter) {
                throw self::unknownNode($filter);
            }

            $this->applyFilter($builder, $filter, $model);
        }
    }

    private function applyFilter(mixed $builder, Filter $filter, Model $model): void
    {
        $field = $this->filterField($model, $filter->property);

        if ($field === null) {
            throw new InvalidArgumentException(self::FILTER_ERROR);
        }

        if (isset($field['relation'])) {
            $this->appendAdvancedFilter($builder, $this->normalizeFilter($filter, $model));

            return;
        }

        $field_name = $field['name'];

        match ($filter->operator) {
            FilterOperator::Equals => $builder->where($field_name, $filter->value),
            FilterOperator::In => $builder->whereIn($field_name, is_array($filter->value) ? $filter->value : [$filter->value]),
            FilterOperator::NotEquals => $builder->whereNotIn($field_name, is_array($filter->value) ? $filter->value : [$filter->value]),
            FilterOperator::Great,
            FilterOperator::GreatEquals,
            FilterOperator::Less,
            FilterOperator::LessEquals,
            FilterOperator::Between => $this->appendAdvancedFilter($builder, $this->normalizeFilter($filter, $model)),
            default => throw new InvalidArgumentException(self::FILTER_ERROR),
        };
    }

    /**
     * @return array{operator: string, filters: list<array<string, mixed>>}
     */
    private function normalizeFiltersGroup(FiltersGroup $filters, Model $model): array
    {
        $out = [];

        foreach ($filters->filters as $filter) {
            // Only column filters and groups are left: withoutDatabaseOnlyNodes() left out every OR group
            // holding another node kind.
            $out[] = match (true) {
                $filter instanceof FiltersGroup => $this->normalizeFiltersGroup($filter, $model),
                $filter instanceof Filter => $this->normalizeFilter($filter, $model),
                default => throw self::unknownNode($filter),
            };
        }

        return [
            'operator' => $filters->operator->value,
            'filters' => $out,
        ];
    }

    /**
     * @return array{field: string, operator: string, value: mixed}
     */
    private function normalizeFilter(Filter $filter, Model $model): array
    {
        $field = $this->filterField($model, $filter->property);

        if ($field === null) {
            throw new InvalidArgumentException(self::FILTER_ERROR);
        }

        if (in_array($filter->operator, [FilterOperator::Like, FilterOperator::NotLike], true)) {
            throw new InvalidArgumentException(self::FILTER_ERROR);
        }

        if ($filter->operator === FilterOperator::Between && (! is_array($filter->value) || count($filter->value) !== 2)) {
            throw new InvalidArgumentException(self::FILTER_ERROR);
        }

        $normalized = [
            'field' => $field['name'],
            'operator' => $filter->operator->value,
            'value' => $filter->value,
        ];

        if (isset($field['relation'], $field['relation_field'])) {
            $normalized['relation'] = $field['relation'];
            $normalized['relation_field'] = $field['relation_field'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function appendAdvancedFilter(mixed $builder, array $filter): void
    {
        $existing = is_array($builder->options['advanced_filters'] ?? null)
            ? $builder->options['advanced_filters']
            : ['operator' => WhereClause::And->value, 'filters' => []];

        $existing['filters'][] = $filter;
        $builder->options['advanced_filters'] = $existing;

        $this->attachEloquentAdvancedFilterCallback($builder);
    }

    private function attachEloquentAdvancedFilterCallback(mixed $builder): void
    {
        if (! method_exists($builder, 'query') || ($builder->options['_advanced_filters_query_attached'] ?? false) === true) {
            return;
        }

        $existing_callback = $builder->queryCallback ?? null;

        $builder->query(static function (mixed $query) use ($builder, $existing_callback): void {
            if ($existing_callback !== null) {
                $existing_callback($query);
            }

            $advanced_filters = $builder->options['advanced_filters'] ?? null;

            if (is_array($advanced_filters)) {
                self::applyAdvancedFiltersToEloquent($query, $advanced_filters);
            }
        });

        $builder->options['_advanced_filters_query_attached'] = true;
    }

    private function fieldName(Model $model, string $property): ?string
    {
        $table_prefix = $model->getTable() . '.';

        if (str_starts_with($property, $table_prefix)) {
            return mb_substr($property, mb_strlen($table_prefix));
        }

        if (str_contains($property, '.')) {
            return null;
        }

        return $property;
    }

    /**
     * @return array{name: string, relation?: string, relation_field?: string}|null
     */
    private function filterField(Model $model, string $property): ?array
    {
        $field = $this->filterPropertyName($model, $property);

        $filterable_fields = $this->filterableFields($model);

        if ($filterable_fields === null) {
            return str_contains($field, '.') ? null : ['name' => $field];
        }

        return $filterable_fields[$field] ?? null;
    }

    private function filterPropertyName(Model $model, string $property): string
    {
        $table_prefix = $model->getTable() . '.';

        if (str_starts_with($property, $table_prefix)) {
            return mb_substr($property, mb_strlen($table_prefix));
        }

        return $property;
    }

    /**
     * @return array<string, array{name: string, relation?: string, relation_field?: string}>|null
     */
    private function filterableFields(Model $model): ?array
    {
        if (method_exists($model, 'getSchemaDefinition')) {
            $method = new ReflectionMethod($model, 'getSchemaDefinition');

            if ($method->isPublic()) {
                $schema = $model->getSchemaDefinition();

                if ($schema instanceof SchemaDefinition) {
                    return $this->filterableFieldsFromSchema($schema);
                }
            }
        }

        if (! method_exists($model, 'getSearchMapping')) {
            return null;
        }

        try {
            $mapping = $model->getSearchMapping();
        } catch (UnsupportedSearchEngineException) {
            return null;
        }

        return is_array($mapping) ? $this->filterableFieldsFromMapping($mapping) : null;
    }

    /**
     * @return array<string, array{name: string, relation?: string, relation_field?: string}>
     */
    private function filterableFieldsFromSchema(SchemaDefinition $schema): array
    {
        $fields = [];

        foreach ($schema->getFields() as $field) {
            if (! $field instanceof FieldDefinition) {
                continue;
            }

            if ($field->hasIndexType(IndexType::Filterable) || $field->hasIndexType(IndexType::Facetable)) {
                $fields[$field->name] = ['name' => $field->name];
            }

            foreach ($this->filterableNestedFieldsFromFieldDefinition($field) as $path => $metadata) {
                $fields[$path] = $metadata;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, array{name: string, relation?: string, relation_field?: string}>
     */
    private function filterableFieldsFromMapping(array $mapping): array
    {
        $fields = [];

        foreach (($mapping['fields'] ?? []) as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                continue;
            }

            if (($field['filterable'] ?? false) === true || ($field['facet'] ?? false) === true) {
                $fields[$field['name']] = ['name' => $field['name']];
            }

            foreach ($this->filterableNestedFieldsFromTypesenseField($field) as $path => $metadata) {
                $fields[$path] = $metadata;
            }
        }

        $properties = $mapping['mappings']['properties'] ?? null;

        if (is_array($properties)) {
            foreach ($properties as $name => $definition) {
                if (! is_string($name) || ! is_array($definition)) {
                    continue;
                }

                $meta = $definition['meta'] ?? null;

                if (($definition['filterable'] ?? false) === true || (is_array($meta) && ($meta['filterable'] ?? false) === true)) {
                    $fields[$name] = ['name' => $name];
                }

                foreach ($this->filterableNestedFieldsFromElasticsearchProperty($name, $definition) as $path => $metadata) {
                    $fields[$path] = $metadata;
                }
            }
        }

        $columns = $mapping['columns'] ?? null;

        if (is_array($columns)) {
            foreach ($columns as $name => $definition) {
                if (! is_string($name) || ! is_array($definition)) {
                    continue;
                }

                if (($definition['filterable'] ?? false) === true) {
                    $fields[$name] = ['name' => $name];
                }

                foreach ($this->filterableNestedFieldsFromDatabaseColumn($name, $definition) as $path => $metadata) {
                    $fields[$path] = $metadata;
                }
            }
        }

        return $fields;
    }

    /**
     * @return array<string, array{name: string, relation?: string, relation_field?: string}>
     */
    private function filterableNestedFieldsFromFieldDefinition(FieldDefinition $field): array
    {
        if (! isset($field->options['properties']) || ! is_array($field->options['properties'])) {
            return [];
        }

        $fields = [];
        $relation = is_string($field->options['relation'] ?? null) ? $field->options['relation'] : null;

        foreach ($field->options['properties'] as $name => $definition) {
            if (! is_string($name) || ! $this->nestedDefinitionFilterable($definition)) {
                continue;
            }

            $path = $field->name . '.' . $name;
            $fields[$path] = ['name' => $path];

            if ($relation !== null) {
                $fields[$path]['relation'] = $relation;
                $fields[$path]['relation_field'] = $name;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, array{name: string, relation?: string, relation_field?: string}>
     */
    private function filterableNestedFieldsFromTypesenseField(array $field): array
    {
        $parent = $field['name'] ?? null;

        if (! is_string($parent)) {
            return [];
        }

        $fields = [];
        $relation = is_string($field['relation'] ?? null) ? $field['relation'] : null;

        foreach (($field['fields'] ?? []) as $nested) {
            if (! is_array($nested) || ! is_string($nested['name'] ?? null)) {
                continue;
            }

            if (($nested['filterable'] ?? false) !== true && ($nested['facet'] ?? false) !== true) {
                continue;
            }

            $path = $parent . '.' . $nested['name'];
            $fields[$path] = ['name' => $path];

            if ($relation !== null) {
                $fields[$path]['relation'] = $relation;
                $fields[$path]['relation_field'] = $nested['name'];
            }
        }

        if (str_contains($parent, '.') && (($field['filterable'] ?? false) === true || ($field['facet'] ?? false) === true)) {
            $fields[$parent] = ['name' => $parent];
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, array{name: string, relation?: string, relation_field?: string}>
     */
    private function filterableNestedFieldsFromElasticsearchProperty(string $name, array $definition): array
    {
        $properties = $definition['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        $fields = [];
        $meta = $definition['meta'] ?? [];
        $relation = is_array($meta) && is_string($meta['relation'] ?? null) ? $meta['relation'] : null;

        foreach ($properties as $property => $property_definition) {
            if (! is_string($property) || ! is_array($property_definition)) {
                continue;
            }

            $property_meta = $property_definition['meta'] ?? [];

            if (! is_array($property_meta) || ($property_meta['filterable'] ?? false) !== true) {
                continue;
            }

            $path = $name . '.' . $property;
            $fields[$path] = ['name' => $path];

            if ($relation !== null) {
                $fields[$path]['relation'] = $relation;
                $fields[$path]['relation_field'] = $property;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, array{name: string, relation?: string, relation_field?: string}>
     */
    private function filterableNestedFieldsFromDatabaseColumn(string $name, array $definition): array
    {
        $properties = $definition['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        $fields = [];
        $relation = is_string($definition['relation'] ?? null) ? $definition['relation'] : null;

        foreach ($properties as $property => $property_definition) {
            if (! is_string($property) || ! is_array($property_definition)) {
                continue;
            }

            if (($property_definition['filterable'] ?? false) !== true) {
                continue;
            }

            $path = $name . '.' . $property;
            $fields[$path] = ['name' => $path];

            if ($relation !== null) {
                $fields[$path]['relation'] = $relation;
                $fields[$path]['relation_field'] = $property;
            }
        }

        return $fields;
    }

    private function nestedDefinitionFilterable(mixed $definition): bool
    {
        return is_array($definition)
            && (($definition['filterable'] ?? false) === true || ($definition['facet'] ?? false) === true);
    }
}
