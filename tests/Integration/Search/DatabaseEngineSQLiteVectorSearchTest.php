<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\HeuristicReranker;
use Modules\Core\Search\Services\ScoutSearchConstraintApplier;
use Modules\Core\Tests\Stubs\Search\DatabaseEngineSQLiteVectorSearchUser;
use Modules\Core\Tests\Support\FakeVectorProfile;

it('returns the top sqlite vector search matches without changing the result shape', function (): void {
    config()->set('database.default', 'sqlite');

    $now = now();
    $table = CoreTables::ModelEmbeddings->value;

    $insert_embedding = static fn (string $model_type, int $model_id, array $embedding): int => (int) (new ModelEmbedding)->getConnection()->table($table)->insertGetId([
        'model_type' => $model_type,
        'model_id' => $model_id,
        'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $best_match_id = $insert_embedding(ModelEmbedding::class, 10, [1.0, 0.0, 0.0]);
    $second_match_id = $insert_embedding(ModelEmbedding::class, 20, [0.9, 0.1, 0.0]);
    $insert_embedding(ModelEmbedding::class, 30, [0.6, 0.8, 0.0]);
    $insert_embedding(self::class, 40, [1.0, 0.0, 0.0]);

    $builder = new ScoutBuilder(new ModelEmbedding, 'vector:[1,0,0]');
    $builder->limit = 2;

    $method = new ReflectionMethod(DatabaseEngine::class, 'performSQLiteVectorSearch');
    $method->setAccessible(true);

    $results = $method->invoke(new DatabaseEngine, [1.0, 0.0, 0.0], new ModelEmbedding, $builder);

    expect($results)->toHaveCount(2)
        ->and(array_column($results, 'id'))->toBe([$best_match_id, $second_match_id])
        ->and($results[0])->toHaveKeys(['id', 'similarity_score', 'embedding'])
        ->and($results[0]['similarity_score'])->toBeGreaterThan($results[1]['similarity_score'])
        ->and($results[0]['embedding'])->toBe([1.0, 0.0, 0.0]);
});

it('keeps sqlite vector search off full collection map pipelines', function (): void {
    $source = file_get_contents(base_path('Modules/Core/app/Search/Engines/DatabaseEngine.php'));

    expect($source)->not->toContain("->get()\n            ->map(")
        ->and($source)->not->toContain('vectorModels($builder, PHP_INT_MAX)')
        ->and($source)->toContain('->lazy(100)')
        ->and($source)->toContain('filterVectorMatchesByModelConstraints');
});

it('builds the postgresql vector query with pgvector distance bindings without executing it', function (): void {
    $method = new ReflectionMethod(DatabaseEngine::class, 'postgreSQLVectorSearchQuery');
    $method->setAccessible(true);

    FakeVectorProfile::use(new VectorProfile('', 3, 'cosine'));

    $query = $method->invoke(new DatabaseEngine(), [1.0, 0.5, 0.0], new DatabaseEngineSQLiteVectorSearchUser(), [101, 202]);

    expect($query->toSql())->toContain('"embedding"::vector(3) <=> ?::vector AS distance')
        ->and($query->toSql())->toContain('"model_id" in (?, ?)')
        ->and($query->toSql())->toContain('order by "embedding"::vector(3) <=> ?::vector')
        ->and($query->getBindings())->toBe([
            '[1,0.5,0]',
            DatabaseEngineSQLiteVectorSearchUser::class,
            101,
            202,
            '[1,0.5,0]',
        ]);
});

it('builds the mysql vector query with json table cosine scoring without executing it', function (): void {
    $method = new ReflectionMethod(DatabaseEngine::class, 'mySQLVectorSearchQuery');
    $method->setAccessible(true);

    $query = $method->invoke(new DatabaseEngine(), [1.0, 0.5, 0.0], new DatabaseEngineSQLiteVectorSearchUser(), [101, 202]);

    expect($query->toSql())->toContain("JSON_TABLE(?, '$[*]' COLUMNS")
        ->and($query->toSql())->toContain('AS similarity_score')
        ->and($query->toSql())->toContain('"model_id" in (?, ?)')
        ->and($query->toSql())->toContain('having "similarity_score" > ?')
        ->and($query->toSql())->toContain('order by "similarity_score" desc')
        ->and($query->getBindings())->toBe([
            '[1,0.5,0]',
            DatabaseEngineSQLiteVectorSearchUser::class,
            101,
            202,
            0.7,
        ]);
});

it('prefilters sqlite vector candidates from keyword constraints before scoring', function (): void {
    config()->set('database.default', 'sqlite');

    $matched = User::factory()->create([
        'username' => 'prefilter_alpha_' . uniqid(),
        'email' => 'prefilter_alpha_' . uniqid() . '@example.test',
    ]);
    $vector_only = User::factory()->create([
        'username' => 'prefilter_vector_' . uniqid(),
        'email' => 'prefilter_vector_' . uniqid() . '@example.test',
    ]);
    $now = now();
    $table = CoreTables::ModelEmbeddings->value;

    $insert_embedding = static fn (int $model_id): int => (int) (new ModelEmbedding)->getConnection()->table($table)->insertGetId([
        'model_type' => DatabaseEngineSQLiteVectorSearchUser::class,
        'model_id' => $model_id,
        'embedding' => json_encode([1.0, 0.0, 0.0], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $insert_embedding((int) $matched->getKey());
    $insert_embedding((int) $vector_only->getKey());

    $builder = new ScoutBuilder(new DatabaseEngineSQLiteVectorSearchUser(), 'prefilter_alpha');
    $builder->where('vector', [1.0, 0.0, 0.0]);

    $method = new ReflectionMethod(DatabaseEngine::class, 'performSQLiteVectorSearch');
    $method->setAccessible(true);

    $results = $method->invoke(new DatabaseEngine(), [1.0, 0.0, 0.0], new DatabaseEngineSQLiteVectorSearchUser(), $builder);

    expect(array_column($results, 'model_id'))->toBe([$matched->getKey()]);
});

it('paginates sqlite vector search as matching models ordered by similarity', function (): void {
    config()->set('database.default', 'sqlite');

    $best = User::factory()->create(['username' => 'vector_best_' . uniqid()]);
    $second = User::factory()->create(['username' => 'vector_second_' . uniqid()]);
    $low = User::factory()->create(['username' => 'vector_low_' . uniqid()]);
    $other = User::factory()->create(['username' => 'vector_other_' . uniqid()]);
    $now = now();
    $table = CoreTables::ModelEmbeddings->value;

    $insert_embedding = static fn (string $model_type, int $model_id, array $embedding): int => (int) (new ModelEmbedding)->getConnection()->table($table)->insertGetId([
        'model_type' => $model_type,
        'model_id' => $model_id,
        'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $insert_embedding(User::class, (int) $best->getKey(), [1.0, 0.0, 0.0]);
    $insert_embedding(User::class, (int) $second->getKey(), [0.9, 0.1, 0.0]);
    $insert_embedding(User::class, (int) $low->getKey(), [0.6, 0.8, 0.0]);
    $insert_embedding(ModelEmbedding::class, (int) $other->getKey(), [1.0, 0.0, 0.0]);

    $builder = new ScoutBuilder(new User(), '*');
    $builder->where('vector', [1.0, 0.0, 0.0]);

    $paginator = (new DatabaseEngine())->paginateUsingDatabase($builder, 2, 'page', 1);
    $models = $paginator->getCollection();
    $second_page = (new DatabaseEngine())->paginateUsingDatabase($builder, 1, 'page', 2);

    expect($paginator->total())->toBe(2)
        ->and($models)->toHaveCount(2)
        ->and($models->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $best->getKey(), (int) $second->getKey()])
        ->and((float) $models->first()?->getAttribute('_score'))->toBeGreaterThan((float) $models->last()?->getAttribute('_score'))
        ->and($second_page->total())->toBe(2)
        ->and($second_page->getCollection())->toHaveCount(1)
        ->and((int) $second_page->getCollection()->first()?->getKey())->toBe((int) $second->getKey());
});

it('applies keyword constraints when sqlite vector search is used as a hybrid database search', function (): void {
    config()->set('database.default', 'sqlite');

    $matched = User::factory()->create([
        'username' => 'hybrid_alpha_' . uniqid(),
        'email' => 'alpha_' . uniqid() . '@example.test',
    ]);
    $vector_only = User::factory()->create([
        'username' => 'vector_only_' . uniqid(),
        'email' => 'vector_' . uniqid() . '@example.test',
    ]);
    $low_keyword_match = User::factory()->create([
        'username' => 'hybrid_alpha_low_' . uniqid(),
        'email' => 'low_' . uniqid() . '@example.test',
    ]);
    $now = now();
    $table = CoreTables::ModelEmbeddings->value;

    $insert_embedding = static fn (string $model_type, int $model_id, array $embedding): int => (int) (new ModelEmbedding)->getConnection()->table($table)->insertGetId([
        'model_type' => $model_type,
        'model_id' => $model_id,
        'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $insert_embedding(DatabaseEngineSQLiteVectorSearchUser::class, (int) $matched->getKey(), [1.0, 0.0, 0.0]);
    $insert_embedding(DatabaseEngineSQLiteVectorSearchUser::class, (int) $vector_only->getKey(), [1.0, 0.0, 0.0]);
    $insert_embedding(DatabaseEngineSQLiteVectorSearchUser::class, (int) $low_keyword_match->getKey(), [0.6, 0.8, 0.0]);

    $builder = new ScoutBuilder(new DatabaseEngineSQLiteVectorSearchUser(), 'hybrid_alpha');
    $builder->where('vector', [1.0, 0.0, 0.0]);

    $paginator = (new DatabaseEngine())->paginateUsingDatabase($builder, 10, 'page', 1);
    $models = $paginator->getCollection();

    expect($paginator->total())->toBe(1)
        ->and($models)->toHaveCount(1)
        ->and($models->first()?->getKey())->toBe($matched->getKey())
        ->and($models->first()?->getAttribute('_score'))->toBeGreaterThan(0.99);
});

it('applies advanced range and or filters before sqlite vector pagination', function (): void {
    config()->set('database.default', 'sqlite');

    $best = User::factory()->create([
        'username' => 'advanced_filter_best_' . uniqid(),
        'email' => 'advanced_best_' . uniqid() . '@example.test',
    ]);
    $second = User::factory()->create([
        'username' => 'advanced_filter_second_' . uniqid(),
        'email' => 'advanced_second_' . uniqid() . '@example.test',
    ]);
    $outside_or = User::factory()->create([
        'username' => 'advanced_filter_outside_' . uniqid(),
        'email' => 'advanced_outside_' . uniqid() . '@example.test',
    ]);
    $now = now();
    $table = CoreTables::ModelEmbeddings->value;

    $insert_embedding = static fn (int $model_id, array $embedding): int => (int) (new ModelEmbedding)->getConnection()->table($table)->insertGetId([
        'model_type' => DatabaseEngineSQLiteVectorSearchUser::class,
        'model_id' => $model_id,
        'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $insert_embedding((int) $best->getKey(), [1.0, 0.0, 0.0]);
    $insert_embedding((int) $second->getKey(), [0.95, 0.05, 0.0]);
    $insert_embedding((int) $outside_or->getKey(), [1.0, 0.0, 0.0]);

    $builder = new ScoutBuilder(new DatabaseEngineSQLiteVectorSearchUser(), '*');
    $builder->where('vector', [1.0, 0.0, 0.0]);

    (new ScoutSearchConstraintApplier())->apply(
        builder: $builder,
        model: new DatabaseEngineSQLiteVectorSearchUser(),
        filters: new FiltersGroup([
            new Filter('id', $second->getKey(), FilterOperator::GreatEquals),
            new FiltersGroup([
                new Filter('username', $best->username, FilterOperator::Equals),
                new Filter('username', $second->username, FilterOperator::Equals),
            ], WhereClause::Or),
        ]),
    );

    $paginator = (new DatabaseEngine())->paginateUsingDatabase($builder, 10, 'page', 1);

    expect($paginator->total())->toBe(1)
        ->and($paginator->getCollection()->pluck('id')->all())->toBe([$second->getKey()]);
});

it('executes keyword vector and hybrid strategies through the database engine ensemble path', function (): void {
    config()->set('database.default', 'sqlite');

    $matched = User::factory()->create([
        'username' => 'ensemble_alpha_' . uniqid(),
        'email' => 'ensemble_alpha_' . uniqid() . '@example.test',
    ]);
    $keyword_only = User::factory()->create([
        'username' => 'ensemble_alpha_keyword_' . uniqid(),
        'email' => 'ensemble_keyword_' . uniqid() . '@example.test',
    ]);
    $vector_only = User::factory()->create([
        'username' => 'ensemble_vector_' . uniqid(),
        'email' => 'ensemble_vector_' . uniqid() . '@example.test',
    ]);
    $now = now();
    $table = CoreTables::ModelEmbeddings->value;

    $insert_embedding = static fn (string $model_type, int $model_id, array $embedding): int => (int) (new ModelEmbedding)->getConnection()->table($table)->insertGetId([
        'model_type' => $model_type,
        'model_id' => $model_id,
        'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $insert_embedding(DatabaseEngineSQLiteVectorSearchUser::class, (int) $matched->getKey(), [1.0, 0.0, 0.0]);
    $insert_embedding(DatabaseEngineSQLiteVectorSearchUser::class, (int) $keyword_only->getKey(), [0.6, 0.8, 0.0]);
    $insert_embedding(DatabaseEngineSQLiteVectorSearchUser::class, (int) $vector_only->getKey(), [1.0, 0.0, 0.0]);

    $result = (new EnsembleSearchService(new HeuristicReranker()))->search(
        model: new DatabaseEngineSQLiteVectorSearchUser(),
        query: 'ensemble_alpha',
        plan: [
            'retrieval' => [
                'use_fulltext' => true,
                'use_vector' => true,
            ],
            'ensemble' => [],
            'ranking' => ['use_reranker' => false],
        ],
        vector: [1.0, 0.0, 0.0],
        page: 1,
        perPage: 10,
    );

    $hit_ids = array_column($result->hits, 'id');

    expect($result->meta['strategies'])->toBe(['keyword', 'vector', 'hybrid'])
        ->and($result->meta['strategies_executed'])->toBe(3)
        ->and($result->hits)->not->toBeEmpty()
        ->and($hit_ids)->toContain((string) $matched->getKey())
        ->and($hit_ids)->toContain((string) $keyword_only->getKey())
        ->and($hit_ids)->toContain((string) $vector_only->getKey())
        ->and($result->hits[0])->toHaveKeys(['id', 'score', 'raw_score', 'score_details', 'source'])
        ->and($result->hits[0]['score'])->toBeFloat()
        ->and($result->hits[0]['score_details'])->toHaveKeys(['normalized_score', 'strategies']);
});
