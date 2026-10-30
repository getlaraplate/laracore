<?php

declare(strict_types=1);

use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\TypesenseEngine;
use Modules\Core\Tests\Stubs\Search\DefaultMappingStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

it('seeds the vector dimension at the length of the default embedding profile (384)', function (): void {
    // The embeddings provider (openai_compatible, all-MiniLM-L6-v2) emits
    // 384-dim vectors; the seeded setting is the authoritative source overlaid
    // onto config, so the engine index mapping is created at 384. A mismatch
    // (e.g. the former 768) produces an unusable dense_vector index.
    $definition = collect(CoreDatabaseSeeder::runtimeSettingDefinitions())
        ->firstWhere('name', 'search.vector.dimensions');

    expect($definition)->not->toBeNull()
        ->and($definition['value'])->toBe(384);
});

it('maps the Elasticsearch embedding field with the dimensions and similarity of the vector profile', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 1024, 'dot_product'));
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();

    $embedding = $engine->getSearchMapping(new DefaultMappingStubModel)['mappings']['properties']['embedding'];

    expect($embedding)->toMatchArray(['type' => 'dense_vector', 'dims' => 1024, 'similarity' => 'dot_product', 'index' => true]);
});

it('maps no Elasticsearch embedding field without a vector profile', function (): void {
    FakeVectorProfile::none();
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();

    expect($engine->getSearchMapping(new DefaultMappingStubModel)['mappings']['properties'])->not->toHaveKey('embedding');
});

it('maps the Typesense embedding field as float[] when a vector profile exists', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 1024, 'cosine'));
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();

    $fields = collect($engine->getSearchMapping(new DefaultMappingStubModel)['fields'])->keyBy('name');

    expect($fields['embedding'])->toMatchArray(['type' => 'float[]', 'optional' => true]);
});

it('maps no Typesense embedding field without a vector profile', function (): void {
    FakeVectorProfile::none();
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();

    $fields = collect($engine->getSearchMapping(new DefaultMappingStubModel)['fields'])->keyBy('name');

    expect($fields)->not->toHaveKey('embedding');
});
