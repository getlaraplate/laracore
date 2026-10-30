<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Tests\Stubs\Search\VectorEmbeddingFieldStubModel;
use Modules\Core\Tests\Stubs\Search\VectorEmbeddingsArrayStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

/**
 * Base Searchable::toSearchableArray() must emit an agnostic `embeddings`
 * array (one {vector} entry per ModelEmbedding row, no locale) rather than
 * the old singular `embedding` key — the shape Content's committed mapping
 * (nested vector field) already expects.
 */
beforeEach(function (): void {
    FakeVectorProfile::use(new VectorProfile('test-profile', 2, 'cosine'));

    Schema::create('core_test_vector_embeddings_stub', function ($table): void {
        $table->id();
    });
});

it('emits one {vector} entry per ModelEmbedding row, with no locale in the payload', function (): void {
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsVectorSearch')->andReturn(true);

    $model = new VectorEmbeddingsArrayStubModel();
    $model->withEngine($engine);
    $model->saveQuietly();

    $model->embeddings()->create(['embedding' => [0.1, 0.2], 'locale' => 'it', 'model_key' => 'test-profile']);
    $model->embeddings()->create(['embedding' => [0.3, 0.4], 'locale' => 'en', 'model_key' => 'test-profile']);

    $array = $model->toSearchableArray();

    expect($array['embeddings'])->toHaveCount(2)
        ->and($array['embeddings'])->toEqualCanonicalizing([
            ['vector' => [0.1, 0.2]],
            ['vector' => [0.3, 0.4]],
        ]);
});

it('omits the embeddings key when the model has no ModelEmbedding rows', function (): void {
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsVectorSearch')->andReturn(true);

    $model = new VectorEmbeddingsArrayStubModel();
    $model->withEngine($engine);
    $model->saveQuietly();

    $array = $model->toSearchableArray();

    expect($array)->not->toHaveKey('embeddings');
});

it('does not add embeddings when the engine does not support vector search', function (): void {
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsVectorSearch')->andReturn(false);

    $model = new VectorEmbeddingsArrayStubModel();
    $model->withEngine($engine);
    $model->saveQuietly();

    $model->embeddings()->create(['embedding' => [0.1, 0.2], 'locale' => 'it', 'model_key' => 'test-profile']);

    $array = $model->toSearchableArray();

    expect($array)->not->toHaveKey('embeddings');
});

it('does not add embeddings when there is no vector profile', function (): void {
    FakeVectorProfile::none();
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsVectorSearch')->andReturn(true);

    $model = new VectorEmbeddingsArrayStubModel();
    $model->withEngine($engine);
    $model->saveQuietly();
    $model->embeddings()->create(['embedding' => [0.1, 0.2], 'locale' => 'it', 'model_key' => 'test-profile']);

    expect($model->toSearchableArray())->not->toHaveKey('embeddings')
        ->and($model->isEmbeddable())->toBeFalse();
});

it('sizes the embedding schema field after the dimensions and similarity of the vector profile', function (): void {
    FakeVectorProfile::use(new VectorProfile('test-profile', 1024, 'dot_product'));
    $model = (new VectorEmbeddingFieldStubModel())->withEngine((new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor());

    $embedding = $model->getSearchMapping()['mappings']['properties']['embedding'];

    expect($embedding)->toMatchArray(['type' => 'dense_vector', 'dims' => 1024, 'similarity' => 'dot_product']);
});
