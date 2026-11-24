<?php

declare(strict_types=1);

use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexedRelation;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Tests\Stubs\Search\IndexedRelationStubModel;

/*
 * A relation indexed in a search document is declared once, and the declaration yields both the document entry and
 * the mapping field, so the two cannot disagree. Spec 2026-11-17-extended-entity-search-design.md, XS14.
 */

function indexed_relation_record(array $attributes): IndexedRelationStubModel
{
    return (new IndexedRelationStubModel)->forceFill($attributes);
}

it('maps a relation with the default shape', function (): void {
    $field = (new IndexedRelation('categories'))->field();

    expect($field->name)->toBe('categories')
        ->and($field->type)->toBe(FieldType::Array)
        ->and($field->indexTypes)->toBe([IndexType::Searchable, IndexType::Filterable, IndexType::Facetable])
        ->and($field->options)->toBe([
            'relation' => 'categories',
            'properties' => [
                'name' => FieldType::Text,
                'id' => ['type' => FieldType::Integer, 'filterable' => true],
                'slug' => ['type' => FieldType::Keyword, 'filterable' => true],
            ],
        ]);
});

it('adds extra properties after the default shape and overrides a default in place', function (): void {
    $relation = new IndexedRelation('locations', properties: [
        'slug' => FieldType::Keyword,
        'city' => ['type' => FieldType::Keyword, 'filterable' => true],
    ]);

    expect($relation->properties())->toBe([
        'name' => FieldType::Text,
        'id' => ['type' => FieldType::Integer, 'filterable' => true],
        'slug' => FieldType::Keyword,
        'city' => ['type' => FieldType::Keyword, 'filterable' => true],
    ]);
});

it('takes its own index types and field name', function (): void {
    $field = (new IndexedRelation('locations', indexTypes: [IndexType::Searchable, IndexType::Filterable], field: 'places'))->field();

    expect($field->name)->toBe('places')
        ->and($field->indexTypes)->toBe([IndexType::Searchable, IndexType::Filterable])
        ->and($field->options['relation'])->toBe('locations');
});

it('writes each related record with exactly the declared properties', function (): void {
    $relation = new IndexedRelation('tags', properties: ['name' => FieldType::Keyword]);

    $document = $relation->document([
        indexed_relation_record(['id' => 1, 'name' => 'News', 'slug' => 'news', 'path' => 'news', 'secret' => 'x']),
        indexed_relation_record(['id' => 2, 'name' => 'Sport', 'slug' => 'sport']),
    ]);

    expect($document)->toBe([
        ['name' => 'News', 'id' => 1, 'slug' => 'news'],
        ['name' => 'Sport', 'id' => 2, 'slug' => 'sport'],
    ])->and($relation->document([]))->toBe([]);
});

it('gives the document and the mapping the same keys', function (): void {
    $relation = new IndexedRelation('locations', properties: ['address' => FieldType::Text, 'zone' => ['type' => FieldType::Keyword, 'filterable' => true]]);
    $document = $relation->document([indexed_relation_record(['id' => 1])]);

    expect(array_keys($document[0]))->toBe(array_keys($relation->field()->options['properties']));
});
