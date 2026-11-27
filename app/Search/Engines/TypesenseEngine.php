<?php

declare(strict_types=1);

namespace Modules\Core\Search\Engines;

use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\TypesenseEngine as BaseTypesenseEngine;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\TextMatchOptions;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Exceptions\MissingSearchSchemaException;
use Modules\Core\Search\Exceptions\SearchCollectionResolutionException;
use Modules\Core\Search\Jobs\ReindexSearchJob;
use Modules\Core\Search\Services\TextMatchOptionsResolver;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Search\Traits\CommonEngineFunctions;
use Modules\Core\Search\Traits\Searchable;
use Override;
use Throwable;
use Typesense\Collection as TypesenseCollection;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Implementation of the search engine for Typesense.
 */
final class TypesenseEngine extends BaseTypesenseEngine implements ISearchEngine
{
    use CommonEngineFunctions;

    /**
     * The value a null string field is indexed with, and the value an equality on null filters on. Typesense neither
     * indexes a null nor takes an empty value in `filter_by`; its documentation advises a placeholder value
     * (https://typesense.org/docs/guide/tips-for-filtering.html). It is alphanumeric, so it is indexed without
     * `symbols_to_index`. Only top-level `string` fields of the mapping get it: a numeric or nested field cannot hold
     * a string, and a filter on its null still finds nothing.
     */
    public const string NULL_PLACEHOLDER = 'typesensenullvalue';

    /**
     * The value a null top-level date is indexed with, and the value an equality on a null date filters on. Typesense
     * filters ranges on numbers only, so a top-level date is an `int64` of seconds since the epoch
     * ({@see \Modules\Core\Search\Translators\TypesenseTranslator}) and cannot hold the string placeholder. A range
     * on a date never matches it ({@see self::comparison()}), as a missing date matches no range on the other engines.
     */
    public const int NULL_TIMESTAMP = -4611686018427387904;

    /**
     * The top-level string fields of the model being indexed by {@see self::update()}.
     *
     * @var list<string>
     */
    private array $null_placeholder_fields = [];

    /**
     * The top-level date fields of the model being indexed by {@see self::update()}.
     *
     * @var list<string>
     */
    private array $date_fields = [];

    /**
     * The top-level date fields of each model class searched, read once from its mapping.
     *
     * @var array<class-string, list<string>>
     */
    private array $date_fields_by_class = [];

    //    public array $config;

    public function offersVectorSearch(): bool
    {
        return true;
    }

    #[Override]
    public function supportsOrchestratedSearch(): bool
    {
        return true;
    }

    #[Override]
    public function supportsOrchestratedVectorSearch(): bool
    {
        return true;
    }

    #[Override]
    public function textMatchCapabilities(): array
    {
        return [
            'typo_tolerance' => true,
            'prefix' => true,
            'exact_match_boost' => false,
            'exact_match_priority' => true,
            'operator' => false,
            'required_terms' => false,
            'required_phrases' => false,
            'degraded' => ['exact_match_boost', 'operator', 'required_terms', 'required_phrases'],
        ];
    }

    /**
     * @param  array<string,mixed>  $options  Index options
     * @param  bool  $force  Force index creation even if it already exists
     *
     * @throws Exception
     * @throws \Http\Client\Exception
     */
    #[Override]
    public function createIndex(mixed $name, array $options = [], bool $force = false): void
    {
        $collection = is_string($name) ? $name : 'unknown';

        try {
            $matched = $this->matchModelToCollectionName($name);

            throw_if($matched === null, SearchCollectionResolutionException::class, 'Unable to resolve collection name for index creation.');

            $model = $matched['model'];
            $collection = $matched['collection'];

            if (! $force && $this->checkIndex($model)) {
                return;
            }

            if (method_exists($model, 'getSearchMapping')) {
                $schema = $model->getSearchMapping();
            } elseif (method_exists($model, 'toSearchableIndex')) {
                $schema = $model->toSearchableIndex();
            } else {
                throw new MissingSearchSchemaException('No schema definition method found on model ' . $model::class);
            }

            if ($force) {
                // Drop existing collection to ensure schema is up to date
                try {
                    $this->typesense->collections[$collection]->delete();
                } catch (Exception) {
                    // Ignore if it does not exist
                }
            }

            // Add collection name to schema
            $schema['name'] = $collection;

            $this->typesense->collections->create($schema);
            Log::info(sprintf("Typesense collection '%s' created", $collection));
        } catch (Exception $exception) {
            Log::error(sprintf("Error creating Typesense collection '%s'", $collection), [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    #[Override]
    public function search(Builder $builder): mixed
    {
        // Check if it's a vector search.
        if ($this->isVectorSearch($builder)) {
            return $this->performVectorSearch($builder);
        }

        // Otherwise, use the traditional search.
        return parent::search($builder);
    }

    /**
     * Scout's update, with each null in a top-level string field of the model's mapping indexed as
     * {@see self::NULL_PLACEHOLDER}, so an equality on null finds the row.
     *
     * @param  \Illuminate\Support\Collection<int, Model>  $models
     */
    #[Override]
    public function update($models): void
    {
        $first = $models->first();
        $this->null_placeholder_fields = $first instanceof Model ? $this->stringFields($first) : [];
        $this->date_fields = $first instanceof Model ? $this->dateFields($first) : [];

        try {
            parent::update($models);
        } finally {
            $this->null_placeholder_fields = [];
            $this->date_fields = [];
        }
    }

    /**
     * A filter of the engine, with no model: a date is recognised by its value only. The searches of a model use
     * {@see self::filterString()} with the model's date fields.
     *
     * @param  array<string, mixed>  $filters
     */
    #[Override]
    public function buildSearchFilters(array $filters): string
    {
        return $this->filterString($filters, []);
    }

    #[Override]
    public function buildSearchParameters(Builder $builder, int $page, ?int $perPage): array
    {
        $parameters = parent::buildSearchParameters($builder, $page, $perPage);
        $text_match = app(TextMatchOptionsResolver::class)->forBuilder($builder);
        $parameters = array_merge(
            $parameters,
            $this->buildTextMatchParameters($text_match),
        );

        if ($text_match->requiredTerms !== [] || $text_match->requiredPhrases !== []) {
            $parameters['q'] = implode(' ', array_filter([
                $text_match->query,
                ...$text_match->requiredTerms,
                ...$text_match->requiredPhrases,
            ], static fn (string $value): bool => $value !== ''));
        }

        $advanced_filters = $builder->options['advanced_filters'] ?? null;

        if (! is_array($advanced_filters)) {
            return $parameters;
        }

        $advanced_filter = $this->filterString($advanced_filters, $this->dateFields($builder->model));

        if ($advanced_filter === '') {
            return $parameters;
        }

        $existing_filter = is_string($parameters['filter_by'] ?? null) ? $parameters['filter_by'] : '';
        $parameters['filter_by'] = $existing_filter !== ''
            ? $existing_filter . ' && ' . $advanced_filter
            : $advanced_filter;

        return $parameters;
    }

    /**
     * @return array<string, bool|int>
     */
    public function buildTextMatchParameters(TextMatchOptions $options): array
    {
        return [
            'num_typos' => $options->typoTolerance && $options->fuzzyTokenLimit > 0 ? $options->maxEdits : 0,
            'prefix' => $options->prefix,
            'min_len_1typo' => $options->minimumTermLength,
            'min_len_2typo' => $options->twoEditMinimumTermLength,
            'prioritize_exact_match' => $options->exactMatchBoost > 0.0,
        ];
    }

    #[Override]
    public function reindex(string $modelClass): void
    {
        dispatch(new ReindexSearchJob($modelClass));
    }

    #[Override]
    /**
     * @param  class-string<Model&Searchable>  $modelClass
     *
     * @throws \Http\Client\Exception
     * @throws TypesenseClientError
     * @throws JsonException
     */
    public function sync(string $modelClass, ?int $id = null, ?string $from = null): int
    {
        $this->ensureIndex(new $modelClass);

        $model_instance = new $modelClass;
        $query = $this->newQueryIncludingTrashed($modelClass);

        // Filters
        if ($id !== null && $id !== 0) {
            $query->where('id', $id);
        } elseif (! in_array($from, [null, '', '0'], true)) {
            $query->where('updated_at', '>', Date::parse($from));
        } else {
            $last_indexed = $this->getLastIndexedTimestamp($model_instance);

            if ($last_indexed) {
                $query->where('updated_at', '>', $last_indexed);
            }
        }

        $count = $query->count();

        // If no records, do nothing
        if ($count === 0) {
            return 0;
        }

        // Sync each record
        $query->chunk(100, function (Collection $records): void {
            $this->update($records);
        });

        return $count;
    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws TypesenseClientError
    //     * @throws \Http\Client\Exception
    //     */
    //    public function getTimeBasedMetrics(Model $model, array $filters = [], string $interval = '1M'): array
    //    {
    //        $collection = $model->searchableAs();
    //
    //        $searchParams = [
    //            'q' => '*',
    //            'group_by' => $filters['date_field'] ?? 'valid_from',
    //            'group_limit' => 100,
    //        ];
    //
    //        if (isset($filters['filter'])) {
    //            $searchParams['filter_by'] = $this->buildFilter($filters['filter']);
    //        }
    //
    //        $response = $this->typesense->collections[$collection]->documents->search($searchParams);
    //
    //        return $response['grouped_hits'] ?? [];
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws TypesenseClientError
    //     * @throws \Http\Client\Exception
    //     */
    //    public function getTermBasedMetrics(Model $model, string $field, array $filters = [], int $size = 10): array
    //    {
    //        $collection = $model->searchableAs();
    //
    //        $searchParams = [
    //            'q' => '*',
    //            'group_by' => $field,
    //            'group_limit' => $size,
    //        ];
    //
    //        if (isset($filters['filter'])) {
    //            $searchParams['filter_by'] = $this->buildFilter($filters['filter']);
    //        }
    //
    //        $response = $this->typesense->collections[$collection]->documents->search($searchParams);
    //
    //        return $response['grouped_hits'] ?? [];
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws TypesenseClientError
    //     * @throws \Http\Client\Exception
    //     */
    //    public function getGeoBasedMetrics(Model $model, string $geoField = 'geocode', array $filters = []): array
    //    {
    //        return $this->getGroupedData($model, $geoField, $filters, 100);
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws TypesenseClientError
    //     * @throws \Http\Client\Exception
    //     */
    //    public function getNumericFieldStats(Model $model, string $field, array $filters = []): array
    //    {
    //        $collection = $model->searchableAs();
    //
    //        $searchParams = [
    //            'q' => '*',
    //            'group_by' => $field,
    //            'group_limit' => 1,
    //        ];
    //
    //        if (isset($filters['filter'])) {
    //            $searchParams['filter_by'] = $this->buildFilter($filters['filter']);
    //        }
    //
    //        $response = $this->typesense->collections[$collection]->documents->search($searchParams);
    //
    //        return $response['grouped_hits'][0] ?? [];
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws TypesenseClientError
    //     * @throws \Http\Client\Exception
    //     */
    //    public function getHistogram(Model $model, string $field, array $filters = [], $interval = 50): array
    //    {
    //        return $this->getGroupedData($model, $field, $filters, 100);
    //    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getSearchMapping(Model $model): array
    {
        // Default mapping for Typesense
        $mapping = [
            'name' => $model->searchableAs(),
            'fields' => [
                'id' => ['name' => 'id', 'type' => 'string', 'index' => true],
                'entity' => ['name' => 'entity', 'type' => 'string', 'facet' => true],
                'connection' => ['name' => 'connection', 'type' => 'string', 'facet' => true],
                self::INDEXED_AT_FIELD => ['name' => self::INDEXED_AT_FIELD, 'type' => 'string', 'sort' => true],
            ],
        ];

        if (VectorProfileContext::get() instanceof VectorProfile && $this->offersVectorSearch()) {
            // Typesense vector field configuration
            // The embedding is pre-computed, so we just need to define it as float[]
            $mapping['fields']['embedding'] = [
                'name' => 'embedding',
                'type' => 'float[]',
                'optional' => true,
            ];
        }

        if (method_exists($model, 'getSearchMapping')) {
            $model_additional_mapping = $model->getSearchMapping();
            $fields = $model_additional_mapping['fields'] ?? (Arr::isList($model_additional_mapping) ? $model_additional_mapping : []);

            foreach ($fields as $field) {
                $mapping['fields'][$field['name']] = $field;
            }
        }

        $mapping['fields'] = array_values($mapping['fields']);

        return $mapping;
    }

    /**
     * @param  string|Model|class-string<Model>  $model
     *
     * @throws \Http\Client\Exception
     */
    #[Override]
    public function checkIndex(string|Model $model): bool
    {
        try {
            if ($model instanceof Model) {
                $collection = $this->resolveSearchableCollectionName($model);

                if ($collection === null) {
                    return false;
                }
            } elseif (class_exists($model)) {
                $collection = new $model()->searchableAs();
            } else {
                $collection = $model;
            }

            $this->typesense->collections[$collection]->retrieve();

            return true;
        } catch (Exception) {
            return false;
        }
    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws TypesenseClientError
    //     * @throws \Http\Client\Exception
    //     *
    //     * @return array|mixed
    //     */
    //    private function getGroupedData(Model $model, string $field, array $filters, int $limit): mixed
    //    {
    //        $collection = $model->searchableAs();
    //
    //        $searchParams = [
    //            'q' => '*',
    //            'group_by' => $field,
    //            'group_limit' => $limit,
    //        ];
    //
    //        if (isset($filters['filter'])) {
    //            $searchParams['filter_by'] = $this->buildFilter($filters['filter']);
    //        }
    //
    //        $response = $this->typesense->collections[$collection]->documents->search($searchParams);
    //
    //        return $response['grouped_hits'] ?? [];
    //    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function health(): array
    {
        $health = $this->typesense->health->retrieve();
        $metrics = $this->typesense->metrics->retrieve();

        return [
            'status' => $health['ok'] ? 'success' : 'danger',
            'metrics' => $metrics,
        ];
    }

    #[Override]
    public function ping(): bool
    {
        return ($this->typesense->health->retrieve()['ok'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function stats(): array
    {
        return $this->typesense->collections->retrieve();
    }

    /**
     * Scout's filter of the wheres, written by {@see self::wheresFilter()}: Scout's own takes no null (its
     * `parseFilterValue()` refuses one) and knows no date, so the default exclusion of extended rows
     * (`<type column> IS NULL`) and every date condition failed there.
     *
     * @param  Builder<covariant Model>  $builder
     */
    #[Override]
    protected function filters(Builder $builder): string
    {
        return $this->wheresFilter($builder);
    }

    /**
     * @param  array<string, mixed>  $filters  An advanced filter tree, or a `field => value` map
     * @param  list<string>  $date_fields
     */
    private function filterString(array $filters, array $date_fields): string
    {
        if (isset($filters['operator'], $filters['filters'])) {
            return $this->buildAdvancedSearchFilter($filters, $date_fields);
        }

        $filter_strings = [];

        foreach ($filters as $field => $value) {
            $field = (string) $field;

            $filter_strings[] = match (true) {
                is_array($value) && count($value) === 2 && is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null) => $this->comparison($field, 'between', $value, $date_fields),
                is_array($value) => $this->comparison($field, 'in', $value, $date_fields),
                default => $this->comparison($field, '=', $value, $date_fields),
            };
        }

        return implode(' && ', array_filter($filter_strings));
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $date_fields
     */
    private function buildAdvancedSearchFilter(array $filter, array $date_fields): string
    {
        if (isset($filter['filters']) && is_array($filter['filters'])) {
            $joiner = ($filter['operator'] ?? 'and') === 'or' ? ' || ' : ' && ';
            $parts = array_values(array_filter(array_map(
                fn (mixed $item): string => is_array($item) ? $this->buildAdvancedSearchFilter($item, $date_fields) : '',
                $filter['filters'],
            )));
            $joined = implode($joiner, $parts);

            return ($filter['operator'] ?? 'and') === 'or' ? sprintf('(%s)', $joined) : $joined;
        }

        return $this->comparison((string) ($filter['field'] ?? ''), (string) ($filter['operator'] ?? '='), $filter['value'] ?? null, $date_fields);
    }

    /**
     * The wheres, whereIns and whereNotIns of a builder as one filter, the fields of `$skip` left out.
     *
     * @param  Builder<covariant Model>  $builder
     * @param  list<string>  $skip
     */
    private function wheresFilter(Builder $builder, array $skip = []): string
    {
        $date_fields = $this->dateFields($builder->model);
        $parts = [];

        foreach ($builder->wheres as $key => $where) {
            // Scout 11 appends ['field', 'operator', 'value']; a Scout 10 entry is `field => value`.
            [$field, $operator, $value] = is_array($where) && array_key_exists('field', $where)
                ? [(string) $where['field'], (string) ($where['operator'] ?? '='), $where['value'] ?? null]
                : [(string) $key, '=', $where];

            if (! in_array($field, $skip, true)) {
                $parts[] = $this->comparison($field, $operator, $value, $date_fields);
            }
        }

        foreach ($builder->whereIns as $field => $values) {
            if (! in_array($field, $skip, true)) {
                $parts[] = $this->comparison((string) $field, 'in', $values, $date_fields);
            }
        }

        foreach ($builder->whereNotIns as $field => $values) {
            if (! in_array($field, $skip, true)) {
                $parts[] = $this->comparison((string) $field, '!=', array_values((array) $values), $date_fields);
            }
        }

        return implode(' && ', array_filter($parts));
    }

    /**
     * One condition in `filter_by` syntax. On a date field (`$date_fields`) the value is a timestamp, a null is
     * {@see self::NULL_TIMESTAMP}, and a range also excludes that value, so a null date matches no range.
     *
     * @param  list<string>  $date_fields
     */
    private function comparison(string $field, string $operator, mixed $value, array $date_fields): string
    {
        $is_date = in_array($field, $date_fields, true);
        $format = fn (mixed $item): string => $this->formatTypesenseFilterValue($item, $is_date);
        $list = static fn (array $items): string => '[' . implode(',', array_map($format, array_values($items))) . ']';
        $bounds = is_array($value) ? array_values($value) : [$value, $value];

        $condition = match ($operator) {
            '=' => is_array($value) ? sprintf('%s:%s', $field, $list($value)) : sprintf('%s:=%s', $field, $format($value)),
            'in' => sprintf('%s:%s', $field, $list(is_array($value) ? $value : [$value])),
            '!=' => sprintf('%s:!=%s', $field, is_array($value) ? $list($value) : $format($value)),
            '>', '>=', '<', '<=' => sprintf('%s:%s%s', $field, $operator, $format($value)),
            'between' => sprintf('%s:>=%s && %s:<=%s', $field, $format($bounds[0] ?? null), $field, $format($bounds[1] ?? null)),
            default => '',
        };

        if ($condition !== '' && $is_date && in_array($operator, ['>', '>=', '<', '<=', 'between'], true)) {
            return sprintf('(%s && %s:!=%d)', $condition, $field, self::NULL_TIMESTAMP);
        }

        return $condition;
    }

    /**
     * @param  array<int, mixed>  $documents
     *
     * @throws \JsonException
     * @throws TypesenseClientError
     * @throws \Http\Client\Exception
     */
    #[Override]
    protected function importDocuments(TypesenseCollection $collectionIndex, array $documents, ?string $action = null): SupportCollection
    {
        foreach ($documents as $index => $document) {
            if (! is_array($document)) {
                continue;
            }

            foreach ($this->null_placeholder_fields as $field) {
                if (array_key_exists($field, $document) && $document[$field] === null) {
                    $documents[$index][$field] = self::NULL_PLACEHOLDER;
                }
            }

            foreach ($this->date_fields as $field) {
                $documents[$index][$field] = self::timestamp($document[$field] ?? null) ?? self::NULL_TIMESTAMP;
            }
        }

        return parent::importDocuments($collectionIndex, $documents, $action);
    }

    /**
     * The top-level fields the model's mapping types as `string`.
     *
     * @return list<string>
     */
    private function stringFields(Model $model): array
    {
        $fields = [];

        foreach ($this->getSearchMapping($model)['fields'] ?? [] as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'string' && is_string($field['name'] ?? null) && ! str_contains($field['name'], '.')) {
                $fields[] = $field['name'];
            }
        }

        return $fields;
    }

    /**
     * The top-level fields the model's mapping types as `int64`: the dates
     * ({@see \Modules\Core\Search\Translators\TypesenseTranslator}), read once per class.
     *
     * @return list<string>
     */
    private function dateFields(Model $model): array
    {
        if (isset($this->date_fields_by_class[$model::class])) {
            return $this->date_fields_by_class[$model::class];
        }

        $fields = [];

        try {
            $mapping = $this->getSearchMapping($model);
        } catch (Throwable) {
            $mapping = [];
        }

        foreach ($mapping['fields'] ?? [] as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'int64' && is_string($field['name'] ?? null) && ! str_contains($field['name'], '.')) {
                $fields[] = $field['name'];
            }
        }

        return $this->date_fields_by_class[$model::class] = $fields;
    }

    /**
     * Seconds since the epoch of a date value: a date, a number, or a string a date can be read from. Null when the
     * value is null or no date.
     */
    private static function timestamp(mixed $value): ?int
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            is_int($value) => $value,
            is_float($value), is_string($value) && is_numeric($value) => (int) $value,
            is_string($value) && $value !== '' => rescue(static fn (): int => Date::parse($value)->getTimestamp(), null, false),
            default => null,
        };
    }

    private function formatTypesenseFilterValue(mixed $value, bool $is_date = false): string
    {
        if ($is_date) {
            return (string) (self::timestamp($value) ?? self::NULL_TIMESTAMP);
        }

        if ($value instanceof DateTimeInterface) {
            return (string) $value->getTimestamp();
        }

        if ($value === null) {
            return sprintf('"%s"', self::NULL_PLACEHOLDER);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return sprintf('"%s"', $value);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @throws \Http\Client\Exception
     * @throws TypesenseClientError
     */
    /**
     * @param  Builder<covariant Model>  $builder
     */
    private function performVectorSearch(Builder $builder): mixed
    {
        $model = $builder->model;

        if (! $model instanceof Model) {
            return parent::search($builder);
        }

        $collection = $this->resolveSearchableCollectionName($model);

        if ($collection === null) {
            return parent::search($builder);
        }

        // Extract the vector from the builder
        $vector = $this->extractVectorFromBuilder($builder);

        if ($vector === []) {
            // Fallback to regular search if no vector provided
            return parent::search($builder);
        }

        // Build vector query for Typesense
        // Typesense expects vector_query in format: "field_name:([vector_values])"
        $vectorString = implode(',', array_map(static fn (float|int|string $v): string => (string) $v, $vector));

        $searchParams = [
            'q' => $builder->query ?: '*',
            'vector_query' => sprintf('%s:(%s)', $this->resolveVectorField($model), $vectorString),
            'per_page' => $builder->limit ?: 10,
        ];

        // Add filters if any are present
        if (! empty($builder->wheres)) {
            $filters = $this->buildFiltersFromBuilder($builder);

            if ($filters !== '') {
                $searchParams['filter_by'] = $filters;
            }
        }

        try {
            $response = $this->typesense->collections[$collection]->documents->search($searchParams);

            // Transform results to match Scout's expected format
            $hits = $response['hits'] ?? [];

            if (! is_array($hits)) {
                return collect();
            }

            /** @var list<array<string, mixed>> $normalized_hits */
            $normalized_hits = array_values(array_filter($hits, is_array(...)));

            return collect($normalized_hits)->map(static function (array $hit): array {
                $document = is_array($hit['document'] ?? null) ? $hit['document'] : [];
                $document_id = $document['id'] ?? null;
                $document['_id'] = is_scalar($document_id) ? (string) $document_id : null;
                $document['_score'] = is_numeric($hit['text_match'] ?? null) ? (float) $hit['text_match'] : 0.0;

                return $document;
            });
        } catch (TypesenseClientError $e) {
            Log::error('Typesense vector search failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private function buildFiltersFromBuilder(Builder $builder): string
    {
        $filters = [$this->wheresFilter($builder, ['vector', 'embedding', $this->resolveVectorField($builder->model)])];
        $advanced_filters = $builder->options['advanced_filters'] ?? null;

        if (is_array($advanced_filters)) {
            $filters[] = $this->filterString($advanced_filters, $this->dateFields($builder->model));
        }

        return implode(' && ', array_filter($filters));
    }
}
