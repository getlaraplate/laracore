<?php

declare(strict_types=1);

use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\MappingStructureComparator;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Translators\ElasticsearchTranslator;

it('matches when every declared field type equals the live mapping', function (): void {
    $expected = [
        'title' => ['type' => 'object', 'properties' => ['it' => ['type' => 'text'], 'en' => ['type' => 'text']]],
        'embeddings' => ['type' => 'nested', 'properties' => ['vector' => ['type' => 'dense_vector', 'dims' => 384]]],
    ];

    $live = [
        'title' => ['properties' => [
            'it' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]]],
            'en' => ['type' => 'text'],
        ]],
        'embeddings' => ['type' => 'nested', 'properties' => ['vector' => ['type' => 'dense_vector', 'dims' => 384]]],
        'created_at' => ['type' => 'date'],
    ];

    expect(MappingStructureComparator::matches($expected, $live))->toBeTrue();
});

it('detects a field whose live type drifted from the declared type', function (): void {
    $expected = ['title' => ['type' => 'object', 'properties' => ['it' => ['type' => 'text']]]];
    $live = ['title' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]]];

    expect(MappingStructureComparator::matches($expected, $live))->toBeFalse();
});

it('detects a declared field missing from the live mapping', function (): void {
    $expected = ['title' => ['type' => 'text'], 'embeddings' => ['type' => 'nested']];
    $live = ['title' => ['type' => 'text']];

    expect(MappingStructureComparator::matches($expected, $live))->toBeFalse();
});

it('ignores live-only fields added by dynamic mapping', function (): void {
    $expected = ['title' => ['type' => 'text']];
    $live = ['title' => ['type' => 'text'], 'slug' => ['type' => 'keyword']];

    expect(MappingStructureComparator::matches($expected, $live))->toBeTrue();
});

it('detects a drifted object sub-field type', function (): void {
    $expected = ['title' => ['type' => 'object', 'properties' => ['it' => ['type' => 'text'], 'en' => ['type' => 'text']]]];
    $live = ['title' => ['properties' => ['it' => ['type' => 'text'], 'en' => ['type' => 'keyword']]]];

    expect(MappingStructureComparator::matches($expected, $live))->toBeFalse();
});

it('diff describes a drifted top-level field type', function (): void {
    $expected = ['title' => ['type' => 'object', 'properties' => ['it' => ['type' => 'text']]]];
    $live = ['title' => ['type' => 'text']];

    expect(MappingStructureComparator::diff($expected, $live))
        ->toBe(["field 'title' is 'text' in the live index but the model declares 'object'"]);
});

it('diff reports a missing field and a drifted object sub-field with dotted paths', function (): void {
    $expected = [
        'title' => ['type' => 'object', 'properties' => ['it' => ['type' => 'text'], 'en' => ['type' => 'text']]],
        'embeddings' => ['type' => 'nested'],
    ];
    $live = [
        'title' => ['properties' => ['it' => ['type' => 'text'], 'en' => ['type' => 'keyword']]],
    ];

    expect(MappingStructureComparator::diff($expected, $live))
        ->toContain("field 'title.en' is 'keyword' in the live index but the model declares 'text'")
        ->toContain("field 'embeddings' is declared by the model but missing from the live index");
});

it('diff is empty when the structure matches', function (): void {
    $expected = ['title' => ['type' => 'text']];
    $live = ['title' => ['type' => 'text'], 'extra' => ['type' => 'keyword']];

    expect(MappingStructureComparator::diff($expected, $live))->toBe([]);
});

it('reports the exact sub-field of a localised text field missing from the live index as a rebuild', function (): void {
    $schema = new SchemaDefinition('cms_contents');
    $schema->addField(new FieldDefinition('title', FieldType::Object, [IndexType::Searchable], [
        'locale_properties' => ['it' => ['analyzer' => 'italian'], 'en' => ['analyzer' => 'english']],
    ]));
    $expected = (new ElasticsearchTranslator)->translate($schema)['mappings']['properties'];

    // The index built before the sub-field existed.
    $live = ['title' => ['properties' => [
        'it' => ['type' => 'text', 'analyzer' => 'italian'],
        'en' => ['type' => 'text', 'analyzer' => 'english'],
    ]]];

    expect(MappingStructureComparator::diff($expected, $live))->toBe([
        "sub-field 'title.it.exact' is declared by the model but missing from the live index; rebuild the index to analyse the documents into it",
        "sub-field 'title.en.exact' is declared by the model but missing from the live index; rebuild the index to analyse the documents into it",
    ]);
});

it('matches once the live index has the declared sub-fields', function (): void {
    $schema = new SchemaDefinition('cms_contents');
    $schema->addField(new FieldDefinition('title', FieldType::Object, [IndexType::Searchable], [
        'locale_properties' => ['it' => ['analyzer' => 'italian']],
    ]));
    $schema->addField(new FieldDefinition('valid_from', FieldType::Date, [IndexType::Filterable]));
    $expected = (new ElasticsearchTranslator)->translate($schema)['mappings']['properties'];

    $live = [
        'title' => ['properties' => ['it' => [
            'type' => 'text',
            'analyzer' => 'italian',
            'fields' => ['exact' => ['type' => 'text', 'analyzer' => 'standard'], 'keyword' => ['type' => 'keyword']],
        ]]],
        'valid_from' => ['type' => 'date', 'fields' => ['keyword' => ['type' => 'keyword']]],
    ];

    expect(MappingStructureComparator::diff($expected, $live))->toBe([]);
});

it('detects a declared sub-field whose live type drifted', function (): void {
    $expected = ['title' => ['type' => 'text', 'fields' => ['exact' => ['type' => 'text']]]];
    $live = ['title' => ['type' => 'text', 'fields' => ['exact' => ['type' => 'keyword']]]];

    expect(MappingStructureComparator::diff($expected, $live))
        ->toBe(["field 'title.exact' is 'keyword' in the live index but the model declares 'text'"]);
});
