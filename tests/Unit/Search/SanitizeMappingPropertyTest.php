<?php

declare(strict_types=1);

use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Translators\ElasticsearchTranslator;

/**
 * Deterministic (no live ES) guard for the cutover blocker: ES `object`/`nested`
 * field types reject `meta` and `index`, but the translator emits both on the
 * relation fields (tags/contributors/categories/locations) for the in-app
 * constraint layer. ElasticsearchEngine::createIndex must strip them from the
 * mapping it applies to the cluster, while keeping the translator output (which
 * ScoutSearchConstraintApplier reads) untouched and keeping leaf `index`/`meta`.
 */

/**
 * @return array<string, array<string, mixed>>
 */
function esSanitizeTestProperties(): array
{
    $schema = new SchemaDefinition('sanitize_stub');

    // Nested relation field, exactly as Content declares `tags`.
    $schema->addField(new FieldDefinition('tags', FieldType::Array, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable], [
        'relation' => 'tags',
        'properties' => [
            'name' => FieldType::Keyword,
            'id' => ['type' => FieldType::Integer, 'filterable' => true],
        ],
    ]));

    // Per-locale text object, as Content declares `title`.
    $schema->addField(new FieldDefinition('title', FieldType::Object, [IndexType::Searchable], [
        'locale_properties' => [
            'it' => ['analyzer' => 'italian'],
            'en' => ['analyzer' => 'english'],
        ],
    ]));

    // Agnostic nested vector array, as Content declares `embeddings`.
    $schema->addField(new FieldDefinition('embeddings', FieldType::Array, [IndexType::Searchable], [
        'vector' => ['dimensions' => 384, 'similarity' => 'cosine'],
    ]));

    // A filterable leaf, as Content declares `type`.
    $schema->addField(new FieldDefinition('status', FieldType::Keyword, [IndexType::Searchable, IndexType::Filterable]));

    return (new ElasticsearchTranslator)->translate($schema)['mappings']['properties'];
}

/**
 * @param  array<string, mixed>  $field
 * @return array<string, mixed>
 */
function invokeEsSanitizeMappingProperty(array $field): array
{
    $method = new ReflectionMethod(ElasticsearchEngine::class, 'sanitizeMappingProperty');

    /** @var array<string, mixed> $result */
    return $method->invoke(null, $field);
}

it('confirms the translator itself still emits meta and index on relation nested fields', function (): void {
    // This is the source of the ES 400; the translator output is intentionally
    // left as-is because the constraint layer reads it back.
    $tags = esSanitizeTestProperties()['tags'];

    expect($tags['type'])->toBe('nested')
        ->and($tags['index'])->toBeTrue()
        ->and($tags['meta']['relation'])->toBe('tags')
        ->and($tags['meta']['filterable'])->toBeTrue();
});

it('strips meta and index from a nested relation field but keeps its filterable leaf sub-properties', function (): void {
    $tags = invokeEsSanitizeMappingProperty(esSanitizeTestProperties()['tags']);

    expect($tags['type'])->toBe('nested')
        ->and($tags)->not->toHaveKey('meta')
        ->and($tags)->not->toHaveKey('index')
        // Filterable leaf sub-property keeps its index and gets stringified meta.
        ->and($tags['properties']['id']['index'])->toBeTrue()
        ->and($tags['properties']['id']['meta']['filterable'])->toBe('true')
        // Non-filterable leaf sub-property carries neither.
        ->and($tags['properties']['name']['type'])->toBe('keyword')
        ->and($tags['properties']['name'])->not->toHaveKey('meta');
});

it('strips meta and index from an object field but keeps its locale analyzers', function (): void {
    $title = invokeEsSanitizeMappingProperty(esSanitizeTestProperties()['title']);

    expect($title['type'])->toBe('object')
        ->and($title)->not->toHaveKey('meta')
        ->and($title)->not->toHaveKey('index')
        ->and($title['properties']['it']['analyzer'])->toBe('italian')
        ->and($title['properties']['en']['analyzer'])->toBe('english');
});

it('keeps the dense_vector index on the nested embeddings sub-field', function (): void {
    $embeddings = invokeEsSanitizeMappingProperty(esSanitizeTestProperties()['embeddings']);

    expect($embeddings['type'])->toBe('nested')
        ->and($embeddings)->not->toHaveKey('meta')
        ->and($embeddings)->not->toHaveKey('index')
        ->and($embeddings['properties']['vector']['type'])->toBe('dense_vector')
        ->and($embeddings['properties']['vector']['index'])->toBeTrue();
});

it('keeps index on a filterable leaf and stringifies its boolean meta', function (): void {
    $status = invokeEsSanitizeMappingProperty(esSanitizeTestProperties()['status']);

    expect($status['type'])->toBe('keyword')
        ->and($status['index'])->toBeTrue()
        ->and($status['meta']['filterable'])->toBe('true');
});

it('hands the cluster an object field whose typed sub-fields are valid leaves', function (): void {
    $schema = new SchemaDefinition('sanitize_stub');
    $schema->addField(new FieldDefinition('extension', FieldType::Object, [IndexType::Searchable, IndexType::Filterable], [
        'properties' => [
            'kind' => ['type' => FieldType::Keyword, 'filterable' => true],
            'label' => FieldType::Keyword,
        ],
    ]));

    $extension = invokeEsSanitizeMappingProperty((new ElasticsearchTranslator)->translate($schema)['mappings']['properties']['extension']);

    expect($extension['type'])->toBe('object')
        ->and($extension)->not->toHaveKey('meta')
        ->and($extension)->not->toHaveKey('index')
        ->and($extension['properties']['kind'])->toBe(['type' => 'keyword', 'index' => true, 'meta' => ['filterable' => 'true']])
        ->and($extension['properties']['label'])->toBe(['type' => 'keyword']);
});
