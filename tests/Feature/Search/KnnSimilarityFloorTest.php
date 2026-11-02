<?php

declare(strict_types=1);

use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\ElasticsearchLocaleTextFields;
use Modules\Core\Tests\Stubs\Search\DatabaseEngineSQLiteVectorSearchUser;
use Modules\Core\Tests\Stubs\Search\NestedVectorLocaleStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

/**
 * The similarity floor of the vector profile: the lowest similarity, on the scale of the similarity
 * function itself (the cosine for `cosine`), a vector hit needs to count. Absent, nothing changes.
 */
function knn_floor_params(): array
{
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();
    $builder = new ScoutBuilder(new NestedVectorLocaleStubModel, '*');
    $builder->where('vector', [0.1, 0.2]);

    return $engine->buildVectorSearchParams($builder);
}

/**
 * @param  list<float>  $queryVector
 * @return list<int>
 */
function knn_floor_sqlite_ids(array $queryVector): array
{
    $method = new ReflectionMethod(DatabaseEngine::class, 'performSQLiteVectorSearch');
    $builder = new ScoutBuilder(new ModelEmbedding, '*');
    $builder->limit = 10;

    return array_map(intval(...), array_column($method->invoke(new DatabaseEngine, $queryVector, new ModelEmbedding, $builder), 'model_id'));
}

beforeEach(function (): void {
    app()->instance(ElasticsearchLocaleTextFields::class, new ElasticsearchLocaleTextFields(static fn (): array => [
        'title' => ['type' => 'object', 'properties' => ['en' => ['type' => 'text']]],
        'locales' => ['type' => 'keyword'],
    ]));
});

it('no floor leaves the kNN clause unchanged', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 2, 'cosine'));

    expect(array_keys(knn_floor_params()['body']['knn']))->toBe(['field', 'query_vector', 'k', 'num_candidates']);
});

it('a floor is sent in the kNN clause', function (string $similarity): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 2, $similarity, 0.75));

    expect(knn_floor_params()['body']['knn']['similarity'])->toBe(0.75);
})->with(['cosine', 'dot_product']);

it('sends no floor for a distance similarity, where a minimum would be a maximum distance', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 2, 'l2_norm', 0.75));

    expect(knn_floor_params()['body']['knn'])->not->toHaveKey('similarity');
});

it('the database engine drops rows beyond the floor', function (?float $floor, array $kept): void {
    config()->set('database.default', 'sqlite');
    FakeVectorProfile::use(new VectorProfile('', 3, 'cosine', $floor));

    $now = now();
    $connection = (new ModelEmbedding)->getConnection();

    foreach ([1 => [1.0, 0.0, 0.0], 2 => [0.95, 0.312, 0.0], 3 => [0.8, 0.6, 0.0], 4 => [0.6, 0.8, 0.0]] as $model_id => $embedding) {
        $connection->table(CoreTables::ModelEmbeddings->value)->insert([
            'model_type' => ModelEmbedding::class,
            'model_id' => $model_id,
            'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    expect(knn_floor_sqlite_ids([1.0, 0.0, 0.0]))->toBe($kept);
})->with([
    'no floor keeps the built-in cut' => [null, [1, 2, 3]],
    'a floor above the built-in cut drops more' => [0.9, [1, 2]],
    'a floor below the built-in cut replaces it' => [0.5, [1, 2, 3, 4]],
]);

it('the database engine keeps the built-in cut for a similarity it does not compute', function (): void {
    config()->set('database.default', 'sqlite');
    FakeVectorProfile::use(new VectorProfile('', 3, 'dot_product', 0.9));

    $now = now();
    (new ModelEmbedding)->getConnection()->table(CoreTables::ModelEmbeddings->value)->insert([
        'model_type' => ModelEmbedding::class,
        'model_id' => 3,
        'embedding' => json_encode([0.8, 0.6, 0.0], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(knn_floor_sqlite_ids([1.0, 0.0, 0.0]))->toBe([3]);
});

it('the pgvector query keeps only the rows within the distance of the floor', function (): void {
    $method = new ReflectionMethod(DatabaseEngine::class, 'postgreSQLVectorSearchQuery');
    FakeVectorProfile::use(new VectorProfile('', 3, 'cosine', 0.8));

    $query = $method->invoke(new DatabaseEngine, [1.0, 0.5, 0.0], new DatabaseEngineSQLiteVectorSearchUser, [101]);

    expect($query->toSql())->toContain('"embedding"::vector(3) <=> ?::vector <= ?')
        ->and($query->getBindings())->toBe([
            '[1,0.5,0]',
            DatabaseEngineSQLiteVectorSearchUser::class,
            '[1,0.5,0]',
            1.0 - 0.8,
            101,
            '[1,0.5,0]',
        ]);
});

it('the mysql query uses the floor in place of the built-in cut', function (): void {
    $method = new ReflectionMethod(DatabaseEngine::class, 'mySQLVectorSearchQuery');
    FakeVectorProfile::use(new VectorProfile('', 3, 'cosine', 0.8));

    $query = $method->invoke(new DatabaseEngine, [1.0, 0.5, 0.0], new DatabaseEngineSQLiteVectorSearchUser, null);

    expect($query->toSql())->toContain('having "similarity_score" >= ?')
        ->and($query->getBindings())->toContain(0.8);
});

it('tells the similarity behind a vector score where the engine scale is known', function (object $engine, string $similarity, float $score, ?float $expected): void {
    config()->set('database.default', 'sqlite');

    $actual = $engine->vectorSimilarity($score, new VectorProfile('a:one', 3, $similarity));

    $expected === null ? expect($actual)->toBeNull() : expect($actual)->toEqualWithDelta($expected, 1e-9);
})->with([
    'search index, cosine' => [fn (): object => (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor(), 'cosine', 0.9, 0.8],
    'search index, dot product' => [fn (): object => (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor(), 'dot_product', 0.9, 0.8],
    'search index, distance' => [fn (): object => (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor(), 'l2_norm', 0.9, null],
    'database, cosine' => [fn (): object => new DatabaseEngine, 'cosine', 0.8, 0.8],
    'database, dot product' => [fn (): object => new DatabaseEngine, 'dot_product', 0.8, null],
]);

it('tells the cosine behind a pgvector score', function (): void {
    $default = config('database.default');
    config()->set('database.default', 'pgsql');

    try {
        $similarity = (new DatabaseEngine)->vectorSimilarity(1.0 / 1.2, new VectorProfile('a:one', 3, 'cosine'));
    } finally {
        // The test database stays the one the transaction was opened on.
        config()->set('database.default', $default);
    }

    expect($similarity)->toEqualWithDelta(0.8, 1e-9);
});
