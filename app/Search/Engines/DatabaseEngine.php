<?php

declare(strict_types=1);

namespace Modules\Core\Search\Engines;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine as BaseDatabaseEngine;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Services\DatabaseTextMatchCompiler;
use Modules\Core\Search\Services\TextMatchOptionsResolver;
use Modules\Core\Search\Support\PgvectorProfileIndex;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Search\Traits\CommonEngineFunctions;

final class DatabaseEngine extends BaseDatabaseEngine implements ISearchEngine
{
    use CommonEngineFunctions;

    public function checkIndex(string|Model $model): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createIndex(mixed $name, array $options = [], bool $force = false): void
    {
        parent::createIndex($name, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [];
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return [];
    }

    public function sync(string $modelClass, ?int $id = null, ?string $from = null): int
    {
        return 0;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildSearchFilters(array $filters): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(Model $model): array
    {
        return [];
    }

    public function reindex(string $modelClass): void {}

    public function supportsVectorSearch(): bool
    {
        return true;
    }

    public function supportsOrchestratedSearch(): bool
    {
        return true;
    }

    public function supportsOrchestratedVectorSearch(): bool
    {
        return true;
    }

    public function textMatchCapabilities(): array
    {
        return [
            'portable' => [
                'typo_tolerance' => false,
                'prefix' => true,
                'exact_match_boost' => false,
                'operator' => false,
                'required_terms' => true,
                'required_phrases' => true,
            ],
            'pgsql_pg_trgm' => [
                'typo_tolerance' => true,
                'prefix' => true,
                'similarity_threshold' => true,
                'requires' => 'pg_trgm extension and SEARCH_DATABASE_PG_TRGM_ENABLED=true',
            ],
            'oracle' => [
                'typo_tolerance' => false,
                'prefix' => true,
                'degraded' => ['typo_tolerance', 'exact_match_boost', 'operator'],
                'future_adapter' => 'Oracle Text CONTEXT index',
            ],
        ];
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function search(Builder $builder): mixed
    {
        if ($this->isVectorSearch($builder)) {
            $models = $this->vectorModels($builder, $builder->limit);

            return [
                'results' => $models,
                'total' => $models->count(),
            ];
        }

        return parent::search($builder);
    }

    public function paginateUsingDatabase(Builder $builder, $perPage, $pageName, $page): LengthAwarePaginator
    {
        if (! $this->isVectorSearch($builder)) {
            return parent::paginateUsingDatabase($builder, $perPage, $pageName, $page);
        }

        $page = (int) ($page ?: Paginator::resolveCurrentPage($pageName));
        $perPage = (int) ($perPage ?: $builder->model->getPerPage());
        $matches = $this->filterVectorMatchesByModelConstraints(
            $builder,
            $this->vectorMatches($builder, PHP_INT_MAX),
        );
        $page_matches = array_slice($matches, max(0, ($page - 1) * $perPage), $perPage);

        return new LengthAwarePaginator(
            items: $this->vectorModelsFromMatches($builder, $page_matches),
            total: count($matches),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $prefixColumns
     * @param  list<string>  $fullTextColumns
     * @return EloquentBuilder<Model>
     */
    #[Override]
    protected function initializeSearchQuery(Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = []): EloquentBuilder
    {
        /** @var EloquentBuilder<Model> $query */
        $query = method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->newQuery();

        if (blank($builder->query)) {
            return $query;
        }

        $driver = $builder->modelConnectionType();
        $options = app(TextMatchOptionsResolver::class)->forBuilder($builder);
        $compiler = app(DatabaseTextMatchCompiler::class);
        $use_pg_trgm = $driver === 'pgsql' && (bool) config('search.text_matching.database.pgsql_trigram_enabled', false);

        return $query->where(function (EloquentBuilder $query) use ($builder, $columns, $fullTextColumns, $driver, $options, $compiler, $use_pg_trgm): void {
            $free_query = $options->query !== '' || $options->requiredTerms !== [] || $options->requiredPhrases !== []
                ? $options->query
                : $builder->query;
            $can_search_primary_key = ctype_digit($free_query)
                && in_array($builder->model->getKeyType(), ['int', 'integer'], true)
                && ($driver !== 'pgsql' || $free_query <= PHP_INT_MAX)
                && in_array($builder->model->getScoutKeyName(), $columns, true);

            if ($free_query !== '') {
                $query->where(function (EloquentBuilder $free) use ($builder, $columns, $fullTextColumns, $driver, $options, $compiler, $use_pg_trgm, $free_query, $can_search_primary_key): void {
                    if ($can_search_primary_key) {
                        $free->orWhere($builder->model->getQualifiedKeyName(), $free_query);
                    }

                    foreach ($columns as $column) {
                        if (in_array($column, $fullTextColumns, true)
                            || ($can_search_primary_key && $column === $builder->model->getScoutKeyName())) {
                            continue;
                        }

                        $qualified = $builder->model->qualifyColumn($column);
                        $wrapped = $free->getQuery()->getGrammar()->wrap($qualified);
                        $compiled = $compiler->compile($use_pg_trgm ? 'pgsql' : $driver, $wrapped, $free_query, $options);
                        $free->orWhereRaw($compiled['sql'], $compiled['bindings']);
                    }

                    if ($fullTextColumns !== []) {
                        $free->orWhereFullText(
                            array_map(fn (string $column): string => $builder->model->qualifyColumn($column), $fullTextColumns),
                            $free_query,
                            $this->getFullTextOptions($builder),
                        );
                    }
                });
            }

            foreach ([...$options->requiredTerms, ...$options->requiredPhrases] as $required) {
                $query->where(function (EloquentBuilder $required_query) use ($builder, $columns, $required): void {
                    foreach ($columns as $column) {
                        $qualified = $builder->model->qualifyColumn($column);
                        $wrapped = $required_query->getQuery()->getGrammar()->wrap($qualified);
                        $required_query->orWhereRaw(
                            sprintf('LOWER(%s) LIKE LOWER(?)', $wrapped),
                            ['%' . $required . '%'],
                        );
                    }
                });
            }
        });
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return list<array<string, mixed>>
     */
    private function performVectorSearch(Builder $builder, ?int $limit = null): array
    {
        $query_vector = $this->extractVectorFromBuilder($builder);
        $model = $builder->model;
        $driver = $this->getDatabaseDriver();
        $candidate_ids = $this->vectorCandidateIds($builder);

        return match ($driver) {
            'pgsql' => $this->performPostgreSQLVectorSearch($query_vector, $model, $builder, $limit, $candidate_ids),
            'mysql', 'mariadb' => $this->performMySQLVectorSearch($query_vector, $model, $builder, $limit, $candidate_ids),
            'sqlite' => $this->performSQLiteVectorSearch($query_vector, $model, $builder, $limit, $candidate_ids),
            default => throw new InvalidArgumentException('Vector search not supported for driver: ' . $driver),
        };
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return EloquentCollection<int, Model>
     */
    private function vectorModels(Builder $builder, ?int $limit = null): EloquentCollection
    {
        return $this->vectorModelsFromMatches($builder, $this->vectorMatches($builder, $limit));
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  list<array{model_id: mixed, score: float}>  $matches
     * @return EloquentCollection<int, Model>
     */
    private function vectorModelsFromMatches(Builder $builder, array $matches): EloquentCollection
    {
        $ids = array_values(array_unique(array_map(static fn (array $match): mixed => $match['model_id'], $matches)));

        if ($ids === []) {
            return $builder->model->newCollection();
        }

        $models = $this->constrainedVectorModelQuery($builder, $ids)
            ->get()
            ->keyBy(static fn (Model $model): string => (string) $model->getKey());
        $ordered = [];

        foreach ($matches as $match) {
            $model = $models->get((string) $match['model_id']);

            if (! $model instanceof Model) {
                continue;
            }

            $model->setAttribute('_score', $match['score']);
            $ordered[] = $model;
        }

        return $builder->model->newCollection($ordered);
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  list<array{model_id: mixed, score: float}>  $matches
     * @return list<array{model_id: mixed, score: float}>
     */
    private function filterVectorMatchesByModelConstraints(Builder $builder, array $matches): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $match): mixed => $match['model_id'], $matches)));

        if ($ids === []) {
            return [];
        }

        $valid_ids = array_flip(array_map(
            static fn (mixed $id): string => (string) $id,
            $this->constrainedVectorModelQuery($builder, $ids)
                ->pluck($builder->model->getKeyName())
                ->all(),
        ));

        if ($valid_ids === []) {
            return [];
        }

        return array_values(array_filter(
            $matches,
            static fn (array $match): bool => isset($valid_ids[(string) $match['model_id']]),
        ));
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return list<array{model_id: mixed, score: float}>
     */
    private function vectorMatches(Builder $builder, ?int $limit = null): array
    {
        $rows = $this->performVectorSearch($builder, $limit);
        $matches = [];

        foreach ($rows as $row) {
            $model_id = $row['model_id'] ?? null;

            if (! is_scalar($model_id)) {
                continue;
            }

            $score = match (true) {
                is_numeric($row['similarity_score'] ?? null) => (float) $row['similarity_score'],
                is_numeric($row['distance'] ?? null) => 1.0 / (1.0 + (float) $row['distance']),
                default => 1.0,
            };

            $matches[] = [
                'model_id' => $model_id,
                'score' => $score,
            ];
        }

        return $matches;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  list<mixed>  $ids
     * @return EloquentBuilder<Model>
     */
    private function constrainedVectorModelQuery(Builder $builder, array $ids): EloquentBuilder
    {
        return $this->constrainedVectorCandidateQuery($builder)->whereKey($ids);
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return EloquentBuilder<Model>
     */
    private function constrainedVectorCandidateQuery(Builder $builder): EloquentBuilder
    {
        $query = $this->baseVectorModelQuery($builder);
        $vector_fields = array_flip(['vector', 'embedding', $this->resolveVectorField($builder->model)]);

        if ($builder->callback !== null) {
            call_user_func($builder->callback, $query, $builder, $builder->query);
        } else {
            foreach (array_diff_key($builder->wheres, $vector_fields) as $key => $value) {
                if ($key === '__soft_deleted') {
                    continue;
                }

                $query->where($key, '=', $value);
            }

            foreach ($builder->whereIns as $key => $values) {
                $query->whereIn($key, $values);
            }

            foreach ($builder->whereNotIns as $key => $values) {
                $query->whereNotIn($key, $values);
            }
        }

        if ($builder->queryCallback !== null) {
            call_user_func($builder->queryCallback, $query);
        }

        return $this->constrainForSoftDeletes($builder, $query);
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return EloquentBuilder<Model>
     */
    private function baseVectorModelQuery(Builder $builder): EloquentBuilder
    {
        if ($builder->query === '*' || blank($builder->query) || ! method_exists($builder->model, 'toSearchableArray')) {
            /** @var EloquentBuilder<Model> */
            return $builder->model->newQuery();
        }

        /** @var array<string, mixed> $searchable */
        $searchable = $builder->model->toSearchableArray();

        // Scout declares both as a bare `array`; they are column names, and the
        // parent signature asks for list<string>.
        /** @var list<string> $prefix_columns */
        $prefix_columns = array_values($this->getPrefixColumns($builder));

        /** @var list<string> $full_text_columns */
        $full_text_columns = array_values($this->getFullTextColumns($builder));

        /** @var EloquentBuilder<Model> */
        return $this->initializeSearchQuery(
            $builder,
            array_keys($searchable),
            $prefix_columns,
            $full_text_columns,
        );
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return list<mixed>|null
     */
    private function vectorCandidateIds(Builder $builder): ?array
    {
        if (! $this->hasVectorCandidateConstraints($builder)) {
            return null;
        }

        return $this->constrainedVectorCandidateQuery($builder)
            ->pluck($builder->model->getKeyName())
            ->all();
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private function hasVectorCandidateConstraints(Builder $builder): bool
    {
        if ($builder->callback !== null || $builder->queryCallback !== null) {
            return true;
        }

        if ($builder->query !== '*' && ! blank($builder->query) && method_exists($builder->model, 'toSearchableArray')) {
            return true;
        }

        $vector_fields = array_flip(['vector', 'embedding', $this->resolveVectorField($builder->model)]);

        return array_diff_key($builder->wheres, $vector_fields) !== []
            || $builder->whereIns !== []
            || $builder->whereNotIns !== [];
    }

    /**
     * @param  list<float>  $queryVector
     * @param  Builder<covariant Model>  $builder
     * @return list<array<string, mixed>>
     */
    private function performPostgreSQLVectorSearch(array $queryVector, Model $model, Builder $builder, ?int $limit = null, ?array $candidateIds = null): array
    {
        $candidateIds ??= $this->vectorCandidateIds($builder);

        if ($candidateIds === []) {
            return [];
        }

        $query = $this->postgreSQLVectorSearchQuery($queryVector, $model, $candidateIds);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var list<array<string, mixed>> */
        return $query
            ->get()
            ->toArray();
    }

    /**
     * @param  list<float>  $queryVector
     * @return EloquentBuilder<ModelEmbedding>
     */
    private function postgreSQLVectorSearchQuery(array $queryVector, Model $model, ?array $candidateIds = null): EloquentBuilder
    {
        $vector_string = '[' . implode(',', $queryVector) . ']';

        // The cast, the model filter and the operator mirror the partial index of the profile
        // (PgvectorProfileIndex): any difference and the planner scans the table instead.
        $profile = VectorProfileContext::get();

        if (! $profile instanceof VectorProfile) {
            throw new InvalidArgumentException('A vector profile is required to search vectors on PostgreSQL, and none is available.');
        }

        if ($profile->dimensions < 1) {
            throw new InvalidArgumentException('The dimensions of the vector profile must be a positive number to search vectors on PostgreSQL.');
        }

        $dimensions = $profile->dimensions;
        $operator = PgvectorProfileIndex::distanceOperator($profile->similarity);
        $distance = "\"embedding\"::vector({$dimensions}) {$operator} ?::vector";

        $query = ModelEmbedding::query()
            ->where('model_type', $model::class)
            ->selectRaw("*, {$distance} AS distance", [$vector_string])
            ->orderByRaw($distance, [$vector_string]);

        $model_key = $profile->modelKey;

        if ($model_key !== '') {
            // A literal, not a binding: the partial index predicate is one, and a generic plan
            // cannot prove that a parameter implies it.
            $query->whereRaw('"model_key" = ' . PgvectorProfileIndex::quoteLiteral($model_key));
        } else {
            // Without a model filter, rows of other lengths would make the cast fail.
            $query->whereRaw("vector_dims(\"embedding\") = {$dimensions}");
        }

        if ($candidateIds !== null) {
            $query->whereIn('model_id', $candidateIds);
        }

        return $query;
    }

    /**
     * @param  list<float>  $queryVector
     * @param  Builder<covariant Model>  $builder
     * @return list<array<string, mixed>>
     */
    private function performMySQLVectorSearch(array $queryVector, Model $model, Builder $builder, ?int $limit = null, ?array $candidateIds = null): array
    {
        $candidateIds ??= $this->vectorCandidateIds($builder);

        if ($candidateIds === []) {
            return [];
        }

        $query = $this->mySQLVectorSearchQuery($queryVector, $model, $candidateIds);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var list<array<string, mixed>> */
        return $query
            ->get()
            ->toArray();
    }

    /**
     * @param  list<float>  $queryVector
     * @return EloquentBuilder<ModelEmbedding>
     */
    private function mySQLVectorSearchQuery(array $queryVector, Model $model, ?array $candidateIds = null): EloquentBuilder
    {
        $query_vector_json = json_encode($queryVector, JSON_THROW_ON_ERROR);

        $query = ModelEmbedding::query()
            ->where('model_type', $model::class)
            ->selectRaw(
                "*, 
                (
                    SELECT SUM(a.value * b.value) / 
                    (SQRT(SUM(a.value * a.value)) * SQRT(SUM(b.value * b.value)))
                    FROM JSON_TABLE(?, '$[*]' COLUMNS (value DOUBLE PATH '$')) a,
                         JSON_TABLE(embedding, '$[*]' COLUMNS (value DOUBLE PATH '$')) b
                ) AS similarity_score",
                [$query_vector_json],
            )
            ->having('similarity_score', '>', 0.7)
            ->orderBy('similarity_score', 'desc');

        if ($candidateIds !== null) {
            $query->whereIn('model_id', $candidateIds);
        }

        return $query;
    }

    /**
     * @param  list<float>  $queryVector
     * @param  Builder<covariant Model>  $builder
     * @return list<array<string, mixed>>
     */
    private function performSQLiteVectorSearch(array $queryVector, Model $model, Builder $builder, ?int $limit = null, ?array $candidateIds = null): array
    {
        $candidateIds ??= $this->vectorCandidateIds($builder);
        $limit ??= (int) ($builder->limit ?? 10);

        if ($limit <= 0 || $candidateIds === []) {
            return [];
        }

        /** @var list<array{id: int|null, similarity_score: float, embedding: list<float>}> $results */
        $results = [];
        $query = ModelEmbedding::query()->where('model_type', $model::class);

        if ($candidateIds !== null) {
            $query->whereIn('model_id', $candidateIds);
        }

        foreach ($query->lazy(100) as $embedding) {
            $stored_embedding = $embedding->embedding ?? [];

            if (! is_array($stored_embedding)) {
                $stored_embedding = [];
            }

            /** @var list<float> $normalized_embedding */
            $normalized_embedding = array_values(array_map(
                static fn (mixed $value): float => (float) $value,
                $stored_embedding,
            ));

            $similarity = $this->calculateCosineSimilarity($queryVector, $normalized_embedding);

            if ($similarity <= 0.7) {
                continue;
            }

            $results[] = [
                'id' => $embedding->id,
                'model_id' => $embedding->model_id,
                'similarity_score' => $similarity,
                'embedding' => $normalized_embedding,
            ];

            usort($results, static fn (array $left, array $right): int => $right['similarity_score'] <=> $left['similarity_score']);

            if ($limit < count($results)) {
                array_pop($results);
            }
        }

        return $results;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function calculateCosineSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot_product = 0.0;
        $norm_a = 0.0;
        $norm_b = 0.0;
        $counter = count($a);

        for ($i = 0; $i < $counter; $i++) {
            $dot_product += $a[$i] * $b[$i];
            $norm_a += $a[$i] * $a[$i];
            $norm_b += $b[$i] * $b[$i];
        }

        if ($norm_a === 0.0 || $norm_b === 0.0) {
            return 0.0;
        }

        return $dot_product / (sqrt($norm_a) * sqrt($norm_b));
    }

    private function getDatabaseDriver(): string
    {
        $default_connection = config('database.default');

        return is_string($default_connection) ? $default_connection : 'mysql';
    }
}
