<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Tests\Stubs\Search\VectorEmbeddingsArrayStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Schema::create('core_test_vector_embeddings_stub', function ($table): void {
        $table->id();
    });

    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsVectorSearch')->andReturn(true);

    $this->model = new VectorEmbeddingsArrayStubModel();
    $this->model->withEngine($engine);
    $this->model->saveQuietly();

    $this->model->embeddings()->create(['embedding' => [0.1, 0.2], 'locale' => 'it', 'model_key' => 'a:one']);
    $this->model->embeddings()->create(['embedding' => [0.3, 0.4, 0.5], 'locale' => 'it', 'model_key' => 'b:two']);
    $this->model->embeddings()->create(['embedding' => [0.9], 'locale' => 'it', 'model_key' => null]);
});

it('serializes only the vectors of the profile model', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 2, 'cosine'));

    expect($this->model->toSearchableArray()['embeddings'])->toBe([['vector' => [0.1, 0.2]]]);
});

it('serializes only the vectors of the override inside using()', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 2, 'cosine'));

    $array = VectorProfileContext::using(new VectorProfile('b:two', 3, 'cosine'), fn (): array => $this->model->toSearchableArray());

    expect($array['embeddings'])->toBe([['vector' => [0.3, 0.4, 0.5]]]);
});

it('filters an eager-loaded relation the same way', function (): void {
    FakeVectorProfile::use(new VectorProfile('b:two', 3, 'cosine'));
    $this->model->load('embeddings');

    expect($this->model->toSearchableArray()['embeddings'])->toBe([['vector' => [0.3, 0.4, 0.5]]]);
});

it('serializes no embeddings when there is no profile, whatever rows the model has', function (): void {
    FakeVectorProfile::none();

    expect($this->model->toSearchableArray())->not->toHaveKey('embeddings');
});
