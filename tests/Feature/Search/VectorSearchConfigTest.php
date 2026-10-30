<?php

declare(strict_types=1);

use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\TypesenseEngine;
use Modules\Core\Tests\Stubs\Search\DefaultMappingStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

it('defines no vector dimension setting, the dimension comes from the vector profile', function (): void {
    $definition = collect(CoreDatabaseSeeder::runtimeSettingDefinitions())
        ->firstWhere('name', 'search.vector.dimensions');

    expect($definition)->toBeNull();
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
