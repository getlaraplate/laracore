<?php

declare(strict_types=1);

use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Translators\ElasticsearchTranslator;
use Modules\Core\Tests\Support\FakeVectorProfile;

it('maps a locale object field to per-language analyzers', function (): void {
    $schema = new SchemaDefinition('cms_contents');
    $schema->addField(new FieldDefinition('title', FieldType::Object, [IndexType::Searchable], [
        'locale_properties' => [
            'it' => ['analyzer' => 'italian'],
            'en' => ['analyzer' => 'english'],
        ],
    ]));

    $mapping = (new ElasticsearchTranslator)->translate($schema);
    $title = $mapping['mappings']['properties']['title'];

    expect($title['type'])->toBe('object')
        ->and($title['properties']['it'])->toMatchArray(['type' => 'text', 'analyzer' => 'italian'])
        ->and($title['properties']['en'])->toMatchArray(['type' => 'text', 'analyzer' => 'english']);
});

it('maps a geocode field to a bare geo_point without the obsolete lat_lon parameter', function (): void {
    $schema = new SchemaDefinition('cms_locations');
    $schema->addField(new FieldDefinition('geocode', FieldType::Geocode, [IndexType::Filterable]));

    $mapping = (new ElasticsearchTranslator)->translate($schema);
    $geocode = $mapping['mappings']['properties']['geocode'];

    // lat_lon was removed in Elasticsearch 5.0; emitting it makes index
    // creation fail with mapper_parsing_exception on any modern cluster.
    expect($geocode['type'])->toBe('geo_point')
        ->and($geocode)->not->toHaveKey('lat_lon');
});

it('maps a vector-carrying array field to a nested dense_vector', function (): void {
    $schema = new SchemaDefinition('cms_contents');
    $schema->addField(new FieldDefinition('embeddings', FieldType::Array, [IndexType::Searchable, IndexType::Vector], [
        'vector' => ['dimensions' => 384, 'similarity' => 'cosine'],
    ]));

    $mapping = (new ElasticsearchTranslator)->translate($schema);
    $field = $mapping['mappings']['properties']['embeddings'];

    expect($field['type'])->toBe('nested')
        ->and($field['properties']['vector'])->toMatchArray([
            'type' => 'dense_vector', 'dims' => 384, 'index' => true, 'similarity' => 'cosine',
        ]);
});

/**
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function esNestedVectorProperty(array $options): array
{
    $schema = new SchemaDefinition('cms_contents');
    $schema->addField(new FieldDefinition('embeddings', FieldType::Array, [IndexType::Searchable], ['vector' => $options]));

    return (new ElasticsearchTranslator)->translate($schema)['mappings']['properties']['embeddings'];
}

it('maps a nested vector field with the dimensions and similarity of its option', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));

    $vector = esNestedVectorProperty(['dimensions' => 1024, 'similarity' => 'dot_product'])['properties']['vector'];

    expect($vector)->toMatchArray(['type' => 'dense_vector', 'dims' => 1024, 'similarity' => 'dot_product']);
});

it('maps a nested vector field from the vector profile when the option names nothing', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 768, 'l2_norm'));

    $vector = esNestedVectorProperty([])['properties']['vector'];

    expect($vector)->toMatchArray(['type' => 'dense_vector', 'dims' => 768, 'similarity' => 'l2_norm']);
});

it('emits no dense_vector for a nested vector field without option and without profile', function (): void {
    FakeVectorProfile::none();

    $embeddings = esNestedVectorProperty([]);

    expect($embeddings)->not->toHaveKey('properties');
});
