<?php

declare(strict_types=1);

namespace Modules\Core\Search\Engines;

use Elastic\Adapter\Search\SearchResult;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\ScoutDriverPlus\Engine as BaseElasticsearchEngine;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Modules\Core\Search\Contracts\ILocaleFilterableEngine;
use Modules\Core\Search\Contracts\IReportsVectorDimensions;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\TextMatchOptions;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Exceptions\MissingSearchSchemaException;
use Modules\Core\Search\Exceptions\SearchCollectionResolutionException;
use Modules\Core\Search\Jobs\ReindexSearchJob;
use Modules\Core\Search\Schema\MappingStructureComparator;
use Modules\Core\Search\Services\SearchQueryAnalyzer;
use Modules\Core\Search\Services\TextMatchOptionsResolver;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Search\Traits\CommonEngineFunctions;
use Modules\Core\Search\Traits\Searchable;
use Modules\Core\Services\ElasticsearchService;
use Override;
use RuntimeException;
use stdClass;

/**
 * Implementation of the search engine for Elasticsearch.
 */
final class ElasticsearchEngine extends BaseElasticsearchEngine implements ILocaleFilterableEngine, IReportsVectorDimensions, ISearchEngine
{
    use CommonEngineFunctions;

    //    public array $config;

    #[Override]
    public function supportsVectorSearch(): bool
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
            'exact_match_boost' => true,
            'operator' => true,
            'required_terms' => true,
            'required_phrases' => true,
            'degraded' => false,
        ];
    }

    /**
     * @throws Exception
     * @throws \Http\Client\Exception
     */
    /**
     * @param  array<string, mixed>  $options
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

            // Get mapping from the model
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
                    $this->deleteIndex($collection);
                } catch (Exception) {
                    // Ignore if it does not exist
                }
            }

            // Add collection name to schema
            $schema['name'] = $collection;

            parent::createIndex($collection, $schema);

            // ScoutDriverPlus's createIndex only creates a bare index; ES then maps
            // fields dynamically as documents arrive. Dynamic mapping cannot infer
            // per-language analyzers or a `dense_vector` (including the nested
            // `embeddings.vector` field), so push the full translated mapping
            // explicitly. `italian`/`english` are built-in ES analyzers, so no
            // custom `settings` block is required for them.
            $properties = is_array($schema['mappings']['properties'] ?? null) ? $schema['mappings']['properties'] : [];

            if ($properties !== []) {
                $mapped = [];

                foreach ($properties as $name => $definition) {
                    $mapped[$name] = is_array($definition) ? self::sanitizeMappingProperty($definition) : $definition;
                }

                ElasticsearchService::getInstance()->createIndex(
                    $collection,
                    [],
                    ['properties' => $mapped],
                );
            }

            Log::info(sprintf("Elasticsearch collection '%s' created", $collection));
        } catch (Exception $exception) {
            Log::error(sprintf("Error creating Elasticsearch collection '%s'", $collection), [
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
    public function search(Builder $builder): SearchResult
    {
        if ($this->isVectorSearch($builder)) {
            return $this->performVectorSearch($builder);
        }

        return $this->performKeywordSearch($builder, $builder->limit ?: 10, 1);
    }

    #[Override]
    public function paginate(Builder $builder, $perPage, $page): SearchResult
    {
        // Mirror search(): a vector-carrying builder must run the kNN query.
        // Without this, paginated vector/hybrid searches silently degraded to a
        // plain keyword query, returning documents in id order instead of by
        // similarity.
        if ($this->isVectorSearch($builder)) {
            return $this->performVectorSearch($builder);
        }

        return $this->performKeywordSearch($builder, (int) $perPage, (int) $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    #[Override]
    public function buildSearchFilters(array $filters): array
    {
        if (isset($filters['operator'], $filters['filters'])) {
            return [$this->buildAdvancedSearchFilter($filters)];
        }

        $esFilters = [];

        foreach ($filters as $field => $value) {
            if (is_array($value) && isset($value['type'])) {
                // Advanced filters with explicit type
                switch ($value['type']) {
                    case 'term':
                        $esFilters[] = ['term' => [$field => $value['value']]];

                        break;
                    case 'terms':
                        $esFilters[] = ['terms' => [$field => $value['value']]];

                        break;
                    case 'range':
                        $esFilters[] = ['range' => [$field => $value['value']]];

                        break;
                    case 'match':
                        $esFilters[] = ['match' => [$field => $value['value']]];

                        break;
                    case 'wildcard':
                        $esFilters[] = ['wildcard' => [$field => $value['value']]];

                        break;
                    case 'exists':
                        $esFilters[] = ['exists' => ['field' => $field]];

                        break;
                    case 'geo_distance':
                        $esFilters[] = [
                            'geo_distance' => [
                                'distance' => $value['distance'],
                                $field => $value['point'],
                            ],
                        ];

                        break;
                }
            } elseif (is_array($value) && count($value) === 2 && isset($value[0]) && isset($value[1])) {
                // Assume it's a range
                $esFilters[] = [
                    'range' => [
                        $field => [
                            'gte' => $value[0],
                            'lte' => $value[1],
                        ],
                    ],
                ];
            } else {
                // Simple exact match filter
                $esFilters[] = ['term' => [$field => $value]];
            }
        }

        return $esFilters;
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
     * @throws JsonException
     */
    public function sync(string $modelClass, ?int $id = null, ?string $from = null): int
    {
        throw_unless(class_exists($modelClass), InvalidArgumentException::class, sprintf('Class %s does not exist', $modelClass));

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
    //     * @throws ServerResponseException
    //     * @throws ClientResponseException
    //     */
    //    public function vectorSearch(array $vector, array $options = []): array
    //    {
    //        $client = ElasticsearchService::getInstance()->client;
    //        $index = $options['index'] ?? null;
    //
    //        $params = [
    //            'index' => $index,
    //            'body' => [
    //                'query' => [
    //                    'bool' => [
    //                        'must' => [],
    //                        'should' => [
    //                            [
    //                                'script_score' => [
    //                                    'query' => ['match_all' => new stdClass()],
    //                                    'script' => [
    //                                        'source' => "cosineSimilarity(params.query_vector, 'embedding') + 1.0",
    //                                        'params' => ['query_vector' => $vector],
    //                                    ],
    //                                ],
    //                            ],
    //                        ],
    //                        'minimum_should_match' => 1,
    //                    ],
    //                ],
    //            ],
    //        ];
    //
    //        // Configure result size
    //        if (isset($options['size'])) {
    //            $params['size'] = $options['size'];
    //        }
    //
    //        // Configure pagination
    //        if (isset($options['from'])) {
    //            $params['from'] = $options['from'];
    //        }
    //
    //        // Filters
    //        if (isset($options['filters']) && $options['filters'] !== []) {
    //            $filters = $this->buildSearchFilters($options['filters']);
    //
    //            if ($filters !== []) {
    //                $params['body']['query']['bool']['must'] = array_merge(
    //                    $params['body']['query']['bool']['must'],
    //                    $filters,
    //                );
    //            }
    //        }
    //
    //        // Sorting
    //        $params['body']['sort'] = $options['sort'] ?? ['_score' => ['order' => 'desc']];
    //
    //        // Specific fields only
    //        if (isset($options['_source'])) {
    //            $params['body']['_source'] = $options['_source'];
    //        }
    //
    //        // Execute query
    //        $results = $client->search($params);
    //
    //        return $results->asArray();
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws ServerResponseException
    //     * @throws ClientResponseException
    //     */
    //    public function getTimeBasedMetrics(Model $model, array $filters = [], string $interval = '1M'): array
    //    {
    //        //        $client = ElasticsearchService::getInstance()->getClient();
    //        //        $index = $model->searchableAs();
    //
    //        $response = $model::searchQuery()
    //            ->bool()
    //            ->must('match', ['entity' => $model->getTable()])
    //            ->aggregation('over_time', [
    //                'date_histogram' => [
    //                    'field' => $filters['date_field'] ?? 'valid_from',
    //                    'calendar_interval' => $interval,
    //                ],
    //            ])
    //            ->size(0)
    //            ->execute();
    //
    //        //        $query = [
    //        //            'index' => $index,
    //        //            'body' => [
    //        //                'size' => 0,
    //        //                'query' => [
    //        //                    'bool' => [
    //        //                        'must' => [
    //        //                            ['match' => ['entity' => $model->getTable()]],
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //                'aggs' => [
    //        //                    'over_time' => [
    //        //                        'date_histogram' => [
    //        //                            'field' => $filters['date_field'] ?? 'valid_from',
    //        //                            'calendar_interval' => $interval,
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //            ],
    //        //        ];
    //
    //        // Add filters if present
    //        //        $response = $this->setFilters($filters, $query, $client);
    //
    //        return $response['aggregations']['over_time']['buckets'] ?? [];
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws ServerResponseException
    //     * @throws ClientResponseException
    //     */
    //    public function getTermBasedMetrics(Model $model, string $field, array $filters = [], int $size = 10): array
    //    {
    //        //        $client = ElasticsearchService::getInstance()->getClient();
    //        //        $index = $this->getIndexName($model);
    //
    //        $response = $model::searchQuery()
    //            ->bool()
    //            ->must('match', ['entity' => $model->getTable()])
    //            ->aggregation('by_term', [
    //                'terms' => [
    //                    'field' => $field,
    //                    'size' => $size,
    //                ],
    //            ])
    //            ->size(0)
    //            ->execute();
    //
    //        //        $query = [
    //        //            'index' => $index,
    //        //            'body' => [
    //        //                'size' => 0,
    //        //                'query' => [
    //        //                    'bool' => [
    //        //                        'must' => [
    //        //                            ['match' => ['entity' => $model->getTable()]],
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //                'aggs' => [
    //        //                    'by_term' => [
    //        //                        'terms' => [
    //        //                            'field' => $field,
    //        //                            'size' => $size,
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //            ],
    //        //        ];
    //
    //        // Add filters if present
    //        //        $response = $this->setFilters($filters, $query, $client);
    //
    //        return $response['aggregations']['by_term']['buckets'] ?? [];
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws ServerResponseException
    //     * @throws ClientResponseException
    //     */
    //    public function getGeoBasedMetrics(Model $model, string $geoField = 'geocode', array $filters = []): array
    //    {
    //        //        $client = ElasticsearchService::getInstance()->getClient();
    //        //        $index = $this->getIndexName($model);
    //
    //        $response = $model::searchQuery()
    //            ->bool()
    //            ->must('match', ['entity' => $model->getTable()])
    //            ->aggregation('geo_clusters', [
    //                'geohash_grid' => [
    //                    'field' => $geoField,
    //                    'precision' => 5,
    //                ],
    //            ])
    //            ->size(0)
    //            ->execute();
    //
    //        //        $query = [
    //        //            'index' => $index,
    //        //            'body' => [
    //        //                'size' => 0,
    //        //                'query' => [
    //        //                    'bool' => [
    //        //                        'must' => [
    //        //                            ['match' => ['entity' => $model->getTable()]],
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //                'aggs' => [
    //        //                    'geo_clusters' => [
    //        //                        'geohash_grid' => [
    //        //                            'field' => $geoField,
    //        //                            'precision' => 5,
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //            ],
    //        //        ];
    //        //
    //        //        // Add filters if present
    //        //        $response = $this->setFilters($filters, $query, $client);
    //
    //        return $response['aggregations']['geo_clusters']['buckets'] ?? [];
    //    }

    //    /**
    //     * @param  Model&Searchable  $model
    //     *
    //     * @throws ServerResponseException
    //     * @throws ClientResponseException
    //     */
    //    public function getNumericFieldStats(Model $model, string $field, array $filters = []): array
    //    {
    //        //        $client = ElasticsearchService::getInstance()->getClient();
    //        //        $index = $this->getIndexName($model);
    //
    //        $response = $model::searchQuery()
    //            ->bool()
    //            ->must('match', ['entity' => $model->getTable()])
    //            ->aggregation('field_stats', [
    //                'stats' => [
    //                    'field' => $field,
    //                ],
    //            ])
    //            ->size(0)
    //            ->execute();
    //
    //        //        $query = [
    //        //            'index' => $index,
    //        //            'body' => [
    //        //                'size' => 0,
    //        //                'query' => [
    //        //                    'bool' => [
    //        //                        'must' => [
    //        //                            ['match' => ['entity' => $model->getTable()]],
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //                'aggs' => [
    //        //                    'field_stats' => [
    //        //                        'stats' => [
    //        //                            'field' => $field,
    //        //                        ],
    //        //                    ],
    //        //                ],
    //        //            ],
    //        //        ];
    //        //
    //        //        // Add filters if present
    //        //        $response = $this->setFilters($filters, $query, $client);
    //
    //        return $response['aggregations']['field_stats'] ?? [];
    //    }

    //    /**
    //     * @throws ServerResponseException
    //     * @throws ClientResponseException
    //     */
    //    public function getHistogram(Model $model, string $field, array $filters = [], $interval = 50): array
    //    {
    //        $client = $ElasticsearchService::getInstance()->client;
    //        $index = $this->getIndexName($model);
    //
    //        $query = [
    //            'index' => $index,
    //            'body' => [
    //                'size' => 0,
    //                'query' => [
    //                    'bool' => [
    //                        'must' => [
    //                            ['match' => ['entity' => $model->getTable()]],
    //                        ],
    //                    ],
    //                ],
    //                'aggs' => [
    //                    'histogram' => [
    //                        'histogram' => [
    //                            'field' => $field,
    //                            'interval' => $interval,
    //                        ],
    //                    ],
    //                ],
    //            ],
    //        ];
    //
    //        // Add filters if present
    //        $response = $this->setFilters($filters, $query, $client);
    //
    //        return $response['aggregations']['histogram']['buckets'] ?? [];
    //    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function getSearchMapping(Model $model): array
    {
        if (method_exists($model, 'getSearchMapping')) {
            return $model->getSearchMapping();
        }

        // Default mapping for Elasticsearch
        $mapping = [
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'keyword'],
                    'entity' => ['type' => 'keyword'],
                    'connection' => ['type' => 'keyword'],
                    self::INDEXED_AT_FIELD => ['type' => 'date'],
                ],
            ],
        ];

        // Add a vector field if needed
        $profile = VectorProfileContext::get();

        if ($profile instanceof VectorProfile && $this->supportsVectorSearch()) {
            $mapping['mappings']['properties']['embedding'] = [
                'type' => 'dense_vector',
                'dims' => $profile->dimensions,
                'index' => true,
                'similarity' => $profile->similarity,
            ];
        }

        return $mapping;
    }

    /**
     * @param  string|Model|class-string<Model>  $model
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

            return $this->indexManager->exists($collection);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Verify the live index mapping still matches the model's declared schema.
     *
     * checkIndex() only proves the index exists; it cannot see that a field's
     * type drifted (e.g. `title` was `text` and the model now declares an
     * `object` with per-locale sub-fields), which makes every write fail with a
     * document_parsing_exception. This compares declared field types against the
     * live mapping and returns false when they diverge. Missing indexes and
     * unreadable mappings fail open, so only a genuine, existing mismatch is
     * flagged.
     */
    public function checkIndexStructure(string|Model $model): bool
    {
        return $this->structureMismatches($model) === [];
    }

    /**
     * The dimension of the `embeddings.vector` field in the model's live index mapping, or null when the
     * index, the field or the mapping is absent or unreadable.
     */
    #[Override]
    public function indexedVectorDimensions(Model $model): ?int
    {
        try {
            $collection = $this->resolveSearchableCollectionName($model);

            if ($collection === null || ! $this->indexManager->exists($collection)) {
                return null;
            }

            $dimensions = data_get(ElasticsearchService::getInstance()->getMapping($collection), 'embeddings.properties.vector.dims');

            return is_numeric($dimensions) ? (int) $dimensions : null;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The declared-vs-live mapping mismatches for a model's index, as
     * human-readable lines (dotted field paths). Empty when the structure
     * matches and — failing open — when the index is missing, its mapping is
     * unreadable, or the model exposes no schema, so only a genuine, existing
     * divergence is reported.
     *
     * @return list<string>
     */
    public function structureMismatches(string|Model $model): array
    {
        try {
            if ($model instanceof Model) {
                $instance = $model;
                $collection = $this->resolveSearchableCollectionName($model);
            } elseif (class_exists($model)) {
                $instance = new $model;
                $collection = $instance->searchableAs();
            } else {
                return [];
            }

            if ($collection === null || ! method_exists($instance, 'getSearchMapping')) {
                return [];
            }

            if (! $this->indexManager->exists($collection)) {
                return [];
            }

            $expected = $instance->getSearchMapping();
            $expected_properties = is_array($expected['mappings']['properties'] ?? null)
                ? $expected['mappings']['properties']
                : [];

            $live_properties = ElasticsearchService::getInstance()->getMapping($collection);

            if ($expected_properties === [] || $live_properties === []) {
                return [];
            }

            return MappingStructureComparator::diff($expected_properties, $live_properties);
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Additively patch an existing index mapping with the model's current schema.
     *
     * Elasticsearch mapping updates are additive: new fields (for example a
     * `title.<locale>` sub-field introduced when a new language is added) are
     * created with their analyzer instead of being inferred by dynamic mapping,
     * while unchanged fields are a no-op. A field whose type actually changed
     * cannot be patched and is rejected by Elasticsearch, so this returns false
     * to signal that the index must be recreated instead. It never drops data;
     * a missing index is left to createIndex().
     */
    public function syncMapping(string|Model $model): bool
    {
        try {
            if ($model instanceof Model) {
                $instance = $model;
                $collection = $this->resolveSearchableCollectionName($model);
            } elseif (class_exists($model)) {
                $instance = new $model;
                $collection = $instance->searchableAs();
            } else {
                return false;
            }

            if ($collection === null || ! method_exists($instance, 'getSearchMapping')) {
                return false;
            }

            if (! $this->indexManager->exists($collection)) {
                return false;
            }

            $schema = $instance->getSearchMapping();
            $properties = is_array($schema['mappings']['properties'] ?? null)
                ? $schema['mappings']['properties']
                : [];

            if ($properties === []) {
                return true;
            }

            $mapped = [];

            foreach ($properties as $name => $definition) {
                $mapped[$name] = is_array($definition) ? self::sanitizeMappingProperty($definition) : $definition;
            }

            // On an existing index ElasticsearchService::createIndex performs an
            // additive putMapping rather than a recreate, so no data is dropped.
            ElasticsearchService::getInstance()->createIndex($collection, [], ['properties' => $mapped]);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    //    /**
    //     * @throws ClientResponseException
    //     * @throws ServerResponseException
    //     */
    //    public function setFilters(?array $filters, array $query, Client $client): Promise|Elasticsearch
    //    {
    //        if ($filters !== [] && $filters !== null) {
    //            $esFilters = $this->buildSearchFilters($filters);
    //
    //            if ($esFilters !== []) {
    //                $query['body']['query']['bool']['must'] = array_merge(
    //                    $query['body']['query']['bool']['must'],
    //                    $esFilters,
    //                );
    //            }
    //        }
    //
    //        return $client->search($query);
    //    }

    //    #[Override]
    //    public function ensureIndexExists(Model $model): bool
    //    {
    //        // Integrazione con Scout per verificare l'esistenza dell'indice
    //        if (method_exists(parent::class, 'indexExists')) {
    //            if (!parent::indexExists($model->searchableAs())) {
    //                // Utilizzare il metodo nativo di Scout se disponibile
    //                if (method_exists(parent::class, 'createIndex')) {
    //                    parent::createIndex($model->searchableAs(), $this->getSearchMapping($model));
    //                } else {
    //                    $this->createIndex($model);
    //                }
    //                return true;
    //            }
    //            return false;
    //        }
    //
    //        // Fallback al comportamento esistente
    //        if (!$this->checkIndex($model)) {
    //            $this->createIndex($model);
    //            return true;
    //        }
    //        return false;
    //    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function health(): array
    {
        $client = ElasticsearchService::getInstance()->client;
        $health = $this->elasticsearchResponseToArray($client->cluster()->health());
        $metrics = $this->elasticsearchResponseToArray($client->cluster()->stats());

        return [
            'status' => $health['status'] ?? 'danger',
            'metrics' => $metrics,
        ];
    }

    #[Override]
    public function ping(): bool
    {
        $response = ElasticsearchService::getInstance()->client->ping();

        return $response instanceof Elasticsearch && $response->asBool();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function stats(): array
    {
        return $this->elasticsearchResponseToArray(
            ElasticsearchService::getInstance()->client->cluster()->health(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTextMatchQuery(string $query, TextMatchOptions $options): array
    {
        $has_parsed_syntax = $options->requiredTerms !== [] || $options->requiredPhrases !== [];
        $free_query = ($options->query !== '' || $has_parsed_syntax) ? $options->query : $query;
        $must = [];

        if ($free_query !== '') {
            $must[] = $this->buildFreeTextMatchQuery($free_query, $options);
        }

        foreach ($options->requiredTerms as $term) {
            $must[] = [
                'multi_match' => [
                    'query' => $term,
                    'fields' => ['*'],
                    'type' => 'best_fields',
                    'operator' => 'and',
                ],
            ];
        }

        foreach ($options->requiredPhrases as $phrase) {
            $must[] = [
                'multi_match' => [
                    'query' => $phrase,
                    'fields' => ['*'],
                    'type' => 'phrase',
                ],
            ];
        }

        if (count($must) === 1) {
            return $must[0];
        }

        return ['bool' => ['must' => $must]];
    }

    /**
     * The request of a vector (or hybrid) search, or null when the builder is not a usable one.
     *
     * The vectors carry no language and are all searched. The language the results are requested in is a
     * document-level restriction (`locales`) that has to be part of this request: applied after the top
     * results are fetched it leaves the documents of another language to fill the first places, and the
     * list comes back short. The text half of a hybrid search is added to the nearest vectors, so it is
     * restricted the same way: by the same filters, and by the fields of the requested language.
     *
     * @param  Builder<covariant Model>  $builder
     * @return array{index: string, body: array<string, mixed>, size: int}|null
     */
    public function buildVectorSearchParams(Builder $builder): ?array
    {
        $vector = $this->extractVectorFromBuilder($builder);

        if ($vector === []) {
            return null;
        }

        $model = $builder->model;

        if (! $model instanceof Model) {
            return null;
        }

        $index = $this->resolveSearchableCollectionName($model);

        if ($index === null) {
            return null;
        }

        // Build the vector search query using knn (more efficient than script_score)
        $knn = [
            'field' => $this->resolveVectorField($model),
            'query_vector' => $vector,
            'k' => $builder->limit ?: 10,
            'num_candidates' => min(($builder->limit ?: 10) * 10, 100),
        ];

        $requested_locales = $this->extractLocaleFilter($builder);
        $filters = $this->filtersWithLocale($this->filtersFromBuilder($builder, $model), $requested_locales);

        if ($filters !== []) {
            // `locales` lives on the root document, not inside the `embeddings` nested path, so this
            // restricts which documents are considered without touching per-vector scoring: a document
            // with a matching vector in any language still scores, it is just excluded when none of its
            // available languages is the requested one.
            $knn['filter'] = ['bool' => ['must' => $filters]];
        }

        $body = ['knn' => $knn];

        if ($builder->query && $builder->query !== '*') {
            $text = $this->buildTextMatchQuery($builder->query, $this->textOptionsFor($builder, $model, $index, $requested_locales));
            $body['query'] = $filters === []
                ? ['bool' => ['should' => [$text]]]
                : ['bool' => ['must' => [$text], 'filter' => $filters]];
        }

        return [
            'index' => $index,
            'body' => $body,
            'size' => $builder->limit ?: 10,
        ];
    }

    /**
     * The request of a keyword search, or null when the model has no index.
     *
     * When a language is requested the documents are restricted to the ones available in it and the text is
     * matched on that language's fields only, so an Italian request never matches words in the English fields.
     *
     * @param  Builder<covariant Model>  $builder
     * @return array{index: string, body: array<string, mixed>, from: int, size: int}|null
     */
    public function buildKeywordSearchParams(Builder $builder, int $perPage, int $page): ?array
    {
        $model = $builder->model;
        $index = $this->resolveSearchableCollectionName($model);

        if ($index === null) {
            return null;
        }

        $requested_locales = $this->extractLocaleFilter($builder);
        $filters = $this->filtersWithLocale($this->filtersFromBuilder($builder, $model), $requested_locales);
        $bool = [
            'must' => [],
        ];

        if ($builder->query && $builder->query !== '*') {
            $bool['must'][] = $this->buildTextMatchQuery(
                $builder->query,
                $this->textOptionsFor($builder, $model, $index, $requested_locales),
            );
        } else {
            $bool['must'][] = ['match_all' => new stdClass()];
        }

        if ($filters !== []) {
            $bool['filter'] = $filters;
        }

        $params = [
            'index' => $index,
            'body' => [
                'query' => [
                    'bool' => $bool,
                ],
            ],
            'from' => max(0, ($page - 1) * $perPage),
            'size' => $perPage,
        ];

        if ($builder->orders !== []) {
            $params['body']['sort'] = array_map(
                static fn (array $order): array => [$order['column'] => $order['direction']],
                $builder->orders,
            );
        }

        return $params;
    }

    /**
     * Whether the model's index records the languages each document is available in, which is what lets a
     * search be restricted to the language the results are requested in.
     */
    #[Override]
    public function filtersByLocale(Model $model): bool
    {
        $mapping = method_exists($model, 'getSearchMapping') ? $model->getSearchMapping() : [];

        return is_array(data_get($mapping, 'mappings.properties.locales'));
    }

    /**
     * Make a translated field definition valid for Elasticsearch before the
     * mapping reaches the cluster. Applied recursively to a property and its
     * `properties`/`fields` subtrees.
     *
     * Two rules:
     *  - `object` and `nested` field types accept neither `meta` nor `index`
     *    (nor `doc_values`); ES rejects the whole mapping with a 400 otherwise
     *    (`mapper_parsing_exception ... unsupported parameters`). The schema
     *    translator still emits those keys on relation fields
     *    (tags/contributors/categories/locations) because the in-app constraint
     *    layer reads them back from `getSearchMapping()`
     *    (see ScoutSearchConstraintApplier); they are therefore stripped here,
     *    at index-creation time, rather than at the translator.
     *  - leaf field `meta` accepts only string values, while the translator
     *    keeps app-facing flags (e.g. `filterable`) as booleans; cast them.
     *    Leaf `index`/`doc_values` (including a `dense_vector`'s `index: true`)
     *    are valid and kept.
     *
     * The mapping comes from JSON, where a key can be numeric: narrowing a value with
     * is_array() yields a plain array, not one with guaranteed string keys, and every
     * call site was reported for a guarantee the data does not carry.
     *
     * @param  array<array-key, mixed>  $field
     * @return array<string, mixed>
     */
    private static function sanitizeMappingProperty(array $field): array
    {
        $type = $field['type'] ?? null;

        if ($type === 'object' || $type === 'nested') {
            unset($field['meta'], $field['index'], $field['doc_values']);
        } elseif (isset($field['meta']) && is_array($field['meta'])) {
            $field['meta'] = array_map(
                static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                $field['meta'],
            );
        }

        if (isset($field['properties']) && is_array($field['properties'])) {
            foreach ($field['properties'] as $name => $sub) {
                if (is_array($sub)) {
                    $field['properties'][$name] = self::sanitizeMappingProperty($sub);
                }
            }
        }

        if (isset($field['fields']) && is_array($field['fields'])) {
            foreach ($field['fields'] as $name => $sub) {
                if (is_array($sub)) {
                    $field['fields'][$name] = self::sanitizeMappingProperty($sub);
                }
            }
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    private function buildAdvancedSearchFilter(array $filter): array
    {
        if (isset($filter['filters']) && is_array($filter['filters'])) {
            $clauses = array_values(array_map(
                fn (array $item): array => $this->buildAdvancedSearchFilter($item),
                array_filter($filter['filters'], is_array(...)),
            ));

            if (($filter['operator'] ?? 'and') === 'or') {
                return [
                    'bool' => [
                        'should' => $clauses,
                        'minimum_should_match' => 1,
                    ],
                ];
            }

            return [
                'bool' => [
                    'must' => $clauses,
                ],
            ];
        }

        $field = (string) ($filter['field'] ?? '');
        $operator = (string) ($filter['operator'] ?? '=');
        $value = $filter['value'] ?? null;
        $relation = $filter['relation'] ?? null;

        if (is_string($relation) && $relation !== '') {
            $positive = match ($operator) {
                '=' => ['term' => [$field => $value]],
                'in' => ['terms' => [$field => is_array($value) ? $value : [$value]]],
                '!=' => is_array($value) ? ['terms' => [$field => $value]] : ['term' => [$field => $value]],
                '>' => ['range' => [$field => ['gt' => $value]]],
                '>=' => ['range' => [$field => ['gte' => $value]]],
                '<' => ['range' => [$field => ['lt' => $value]]],
                '<=' => ['range' => [$field => ['lte' => $value]]],
                'between' => ['range' => [$field => ['gte' => is_array($value) ? array_values($value)[0] ?? null : null, 'lte' => is_array($value) ? array_values($value)[1] ?? null : null]]],
                default => ['term' => [$field => $value]],
            };
            $nested = [
                'nested' => [
                    'path' => $relation,
                    'query' => $positive,
                ],
            ];

            if ($operator === '!=') {
                return [
                    'bool' => [
                        'must_not' => [$nested],
                    ],
                ];
            }

            return $nested;
        }

        return match ($operator) {
            '=' => ['term' => [$field => $value]],
            'in' => ['terms' => [$field => is_array($value) ? $value : [$value]]],
            '!=' => ['bool' => ['must_not' => [is_array($value) ? ['terms' => [$field => $value]] : ['term' => [$field => $value]]]]],
            '>' => ['range' => [$field => ['gt' => $value]]],
            '>=' => ['range' => [$field => ['gte' => $value]]],
            '<' => ['range' => [$field => ['lt' => $value]]],
            '<=' => ['range' => [$field => ['lte' => $value]]],
            'between' => ['range' => [$field => ['gte' => is_array($value) ? array_values($value)[0] ?? null : null, 'lte' => is_array($value) ? array_values($value)[1] ?? null : null]]],
            default => ['term' => [$field => $value]],
        };
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private function performVectorSearch(Builder $builder): SearchResult
    {
        $params = $this->buildVectorSearchParams($builder);

        if ($params === null) {
            return parent::search($builder);
        }

        $response = ElasticsearchService::getInstance()->client->search($params);

        return new SearchResult($this->elasticsearchResponseToArray($response));
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private function performKeywordSearch(Builder $builder, int $perPage, int $page): SearchResult
    {
        $params = $this->buildKeywordSearchParams($builder, $perPage, $page);

        if ($params === null) {
            return parent::search($builder);
        }

        $response = ElasticsearchService::getInstance()->client->search($params);

        return new SearchResult($this->elasticsearchResponseToArray($response));
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function filtersWithLocale(array $filters, array $locales): array
    {
        if ($locales !== []) {
            $filters[] = ['terms' => ['locales' => $locales]];
        }

        return $filters;
    }

    /**
     * The text options of a builder, searching the fields of the requested language when there is one and
     * the caller did not name fields of its own. With no mapping known the default fields stay.
     *
     * @param  Builder<covariant Model>  $builder
     * @param  list<string>  $locales
     */
    private function textOptionsFor(Builder $builder, Model $model, string $index, array $locales): TextMatchOptions
    {
        $options = app(TextMatchOptionsResolver::class)->forBuilder($builder);

        if ($locales === [] || $options->fields !== []) {
            return $options;
        }

        $fields = app(ElasticsearchLocaleTextFields::class)->forModel($model, $index, $locales);

        return $fields === [] ? $options : $options->withFields($fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFreeTextMatchQuery(string $query, TextMatchOptions $options): array
    {
        $analysis = app(SearchQueryAnalyzer::class)->analyze($query, $options->minimumTermLength);
        // Target the model's locale sub-fields (e.g. `title.it`, `title.en`) via the
        // `title.*` field-name wildcard, boosted, instead of the single
        // locale-current `title`; falls back to a boosted `title.*` plus a
        // catch-all when the resolver had no explicit schema-derived field list
        // (e.g. no ES connection available to resolve the schema).
        $fields = $options->fields !== [] ? $options->fields : ['title.*^2', '*'];
        $match = [
            'query' => $query,
            'fields' => $fields,
            'type' => $options->prefix ? 'bool_prefix' : 'best_fields',
            'operator' => $options->operator,
        ];

        if ($options->operator === 'or' && $options->minimumShouldMatch < 100) {
            $match['minimum_should_match'] = $options->minimumShouldMatch . '%';
        }

        if ($options->typoTolerance
            && $options->maxEdits > 0
            && $options->fuzzyTokenLimit > 0
            && (! $analysis->protectedOnly() || $options->identifierTypos)
            && $options->minimumTermLength <= mb_strlen($query)) {
            $match['fuzziness'] = $options->maxEdits === 1
                ? 1
                : sprintf('AUTO:%d,%d', $options->minimumTermLength, $options->twoEditMinimumTermLength);
            $match['prefix_length'] = $options->prefixLength;
            $match['fuzzy_transpositions'] = $options->transpositions;
        }

        if ($options->exactMatchBoost <= 0.0) {
            return ['multi_match' => $match];
        }

        return [
            'bool' => [
                'should' => [
                    ['multi_match' => $match],
                    [
                        'multi_match' => [
                            'query' => $query,
                            'fields' => $fields,
                            'type' => 'phrase',
                            'boost' => $options->exactMatchBoost,
                        ],
                    ],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private function hasAdvancedFilters(Builder $builder): bool
    {
        return is_array($builder->options['advanced_filters'] ?? null);
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return list<array<string, mixed>>
     */
    private function filtersFromBuilder(Builder $builder, Model $model): array
    {
        // `locales` (or `locale`) is excluded here and handled by extractLocaleFilter()
        // instead: buildSearchFilters() would otherwise mishandle a multi-locale array
        // (a 2-element array is read as a `range`, anything else as a `term` with an
        // array value, which Elasticsearch rejects) instead of a `terms` filter.
        $filter_wheres = array_diff_key($builder->wheres, array_flip(['vector', 'embedding', 'locales', 'locale', $this->resolveVectorField($model)]));
        $filters = $filter_wheres !== [] ? $this->buildSearchFilters($filter_wheres) : [];
        $advanced_filters = $builder->options['advanced_filters'] ?? null;

        if (is_array($advanced_filters)) {
            $filters = array_merge($filters, $this->buildSearchFilters($advanced_filters));
        }

        return $filters;
    }

    /**
     * Extract a document-level locale filter from the builder, from either a
     * `locales` (array or single value) or `locale` where clause.
     *
     * @param  Builder<covariant Model>  $builder
     * @return list<string>
     */
    private function extractLocaleFilter(Builder $builder): array
    {
        $value = $builder->wheres['locales'] ?? $builder->wheres['locale'] ?? null;

        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
                $values,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function elasticsearchResponseToArray(mixed $response): array
    {
        if ($response instanceof Elasticsearch) {
            return $response->asArray();
        }

        throw new RuntimeException('Unexpected Elasticsearch client response type.');
    }
}
