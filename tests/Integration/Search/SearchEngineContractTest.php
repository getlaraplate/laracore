<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Engines\Engine as ScoutEngine;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\TypesenseEngine;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Schema\SchemaManager;
use Modules\Core\Tests\Stubs\Search\TypesenseDatedStub;

it('declares the scout search signature on the core search engine contract', function (): void {
    $method = new ReflectionMethod(ISearchEngine::class, 'search');
    $parameters = $method->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and((string) $parameters[0]->getType())->toBe(ScoutBuilder::class)
        ->and((string) $method->getReturnType())->toBe('mixed');
});

it('declares orchestrated search support on the core search engine contract', function (): void {
    $method = new ReflectionMethod(ISearchEngine::class, 'supportsOrchestratedSearch');

    expect($method->getNumberOfRequiredParameters())->toBe(0)
        ->and((string) $method->getReturnType())->toBe('bool');
});

it('declares orchestrated vector search support on the core search engine contract', function (): void {
    $method = new ReflectionMethod(ISearchEngine::class, 'supportsOrchestratedVectorSearch');

    expect($method->getNumberOfRequiredParameters())->toBe(0)
        ->and((string) $method->getReturnType())->toBe('bool');
});

it('keeps core search engine createIndex signatures compatible with Laravel Scout', function (string $engine): void {
    $base = new ReflectionMethod(ScoutEngine::class, 'createIndex');
    $method = new ReflectionMethod($engine, 'createIndex');
    $name_parameter = $method->getParameters()[0];

    expect($name_parameter->getType())->not->toBeNull()
        ->and((string) $name_parameter->getType())->toBe('mixed')
        ->and($method->getNumberOfRequiredParameters())->toBe($base->getNumberOfRequiredParameters());
})->with([
    ElasticsearchEngine::class,
    TypesenseEngine::class,
    DatabaseEngine::class,
]);

it('exposes orchestrated search support per engine', function (string $engine, bool $expected): void {
    $method = new ReflectionMethod($engine, 'supportsOrchestratedSearch');
    $instance = $method->isStatic() ? null : (new ReflectionClass($engine))->newInstanceWithoutConstructor();

    expect($method->invoke($instance))->toBe($expected);
})->with([
    [ElasticsearchEngine::class, true],
    [TypesenseEngine::class, true],
    [DatabaseEngine::class, true],
]);

it('exposes orchestrated vector support per engine', function (string $engine, bool $expected): void {
    $method = new ReflectionMethod($engine, 'supportsOrchestratedVectorSearch');
    $instance = $method->isStatic() ? null : (new ReflectionClass($engine))->newInstanceWithoutConstructor();

    expect($method->invoke($instance))->toBe($expected);
})->with([
    [ElasticsearchEngine::class, true],
    [TypesenseEngine::class, true],
    [DatabaseEngine::class, true],
]);

it('translates advanced portable filters to elasticsearch query clauses', function (): void {
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();

    $filters = $engine->buildSearchFilters([
        'operator' => 'and',
        'filters' => [
            ['field' => 'id', 'operator' => '>=', 'value' => 10],
            ['field' => 'id', 'operator' => '<', 'value' => 20],
            ['field' => 'created_at', 'operator' => 'between', 'value' => ['2024-01-01', '2024-01-31']],
            [
                'operator' => 'or',
                'filters' => [
                    ['field' => 'status', 'operator' => '=', 'value' => 'draft'],
                    ['field' => 'status', 'operator' => '=', 'value' => 'published'],
                ],
            ],
        ],
    ]);

    expect($filters)->toMatchArray([
        [
            'bool' => [
                'must' => [
                    ['range' => ['id' => ['gte' => 10]]],
                    ['range' => ['id' => ['lt' => 20]]],
                    ['range' => ['created_at' => ['gte' => '2024-01-01', 'lte' => '2024-01-31']]],
                    [
                        'bool' => [
                            'should' => [
                                ['term' => ['status' => 'draft']],
                                ['term' => ['status' => 'published']],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

it('translates indexed relation field filters to elasticsearch nested clauses', function (): void {
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();

    $filters = $engine->buildSearchFilters([
        'operator' => 'and',
        'filters' => [
            ['field' => 'tags.id', 'operator' => '=', 'value' => 10, 'relation' => 'tags', 'relation_field' => 'id'],
            ['field' => 'tags.slug', 'operator' => '!=', 'value' => ['draft', 'archived'], 'relation' => 'tags', 'relation_field' => 'slug'],
        ],
    ]);

    expect($filters)->toMatchArray([
        [
            'bool' => [
                'must' => [
                    [
                        'nested' => [
                            'path' => 'tags',
                            'query' => ['term' => ['tags.id' => 10]],
                        ],
                    ],
                    [
                        'bool' => [
                            'must_not' => [
                                [
                                    'nested' => [
                                        'path' => 'tags',
                                        'query' => ['terms' => ['tags.slug' => ['draft', 'archived']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
});

it('translates advanced portable filters to typesense filter syntax', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();

    $filters = $engine->buildSearchFilters([
        'operator' => 'and',
        'filters' => [
            ['field' => 'id', 'operator' => '>=', 'value' => 10],
            ['field' => 'id', 'operator' => '<', 'value' => 20],
            ['field' => 'created_at', 'operator' => 'between', 'value' => ['2024-01-01', '2024-01-31']],
            [
                'operator' => 'or',
                'filters' => [
                    ['field' => 'status', 'operator' => '=', 'value' => 'draft'],
                    ['field' => 'status', 'operator' => '=', 'value' => 'published'],
                ],
            ],
        ],
    ]);

    expect($filters)->toBe('id:>=10 && id:<20 && created_at:>="2024-01-01" && created_at:<="2024-01-31" && (status:="draft" || status:="published")');
});

it('translates indexed relation field filters to typesense nested filter syntax', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();

    $filters = $engine->buildSearchFilters([
        'operator' => 'and',
        'filters' => [
            ['field' => 'tags.id', 'operator' => '=', 'value' => 10, 'relation' => 'tags', 'relation_field' => 'id'],
            ['field' => 'tags.slug', 'operator' => '!=', 'value' => ['draft', 'archived'], 'relation' => 'tags', 'relation_field' => 'slug'],
        ],
    ]);

    expect($filters)->toBe('tags.id:=10 && tags.slug:!=["draft","archived"]');
});

it('adds advanced portable filters to typesense keyword search parameters', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    $model = new class extends Model
    {
        public function searchableAs(): string
        {
            return 'users';
        }
    };
    $builder = new ScoutBuilder($model, 'alpha +Milano "Mario Rossi"');
    $builder->options['advanced_filters'] = [
        'operator' => 'and',
        'filters' => [
            ['field' => 'id', 'operator' => '>=', 'value' => 10],
            [
                'operator' => 'or',
                'filters' => [
                    ['field' => 'status', 'operator' => '=', 'value' => 'draft'],
                    ['field' => 'status', 'operator' => '=', 'value' => 'published'],
                ],
            ],
        ],
    ];

    $parameters = $engine->buildSearchParameters($builder, 2, 15);

    expect($parameters['filter_by'])->toBe('id:>=10 && (status:="draft" || status:="published")')
        ->and($parameters['q'])->toBe('alpha Milano Mario Rossi')
        ->and($parameters['page'])->toBe(2)
        ->and($parameters['per_page'])->toBe(15);
});

it('preserves filterable field metadata across search schema translations', function (): void {
    $schema = new SchemaDefinition('users');
    $schema->addField(new FieldDefinition('status', FieldType::Keyword, [IndexType::Searchable, IndexType::Filterable]));
    $schema->addField(new FieldDefinition('title', FieldType::Text, [IndexType::Searchable]));
    $schema->addField(new FieldDefinition('name', FieldType::Text, [IndexType::Searchable, IndexType::Fuzzy]));
    $schema->addField(new FieldDefinition('slug', FieldType::Keyword, [IndexType::Searchable, IndexType::Prefix]));
    $schema->addField(new FieldDefinition('body', FieldType::Text, [IndexType::Searchable, IndexType::FullText]));
    $schema->addField(new FieldDefinition('tags', FieldType::Array, [IndexType::Searchable, IndexType::Filterable], [
        'relation' => 'tags',
        'properties' => [
            'id' => ['type' => FieldType::Integer, 'filterable' => true],
            'name' => FieldType::Text,
        ],
    ]));

    $manager = new SchemaManager();
    $typesense_fields = collect($manager->translateForEngine($schema, 'typesense')['fields'])->keyBy('name');

    $database_indexes = collect($manager->translateForEngine($schema, 'database')['indexes']);

    expect($typesense_fields->get('status')['facet'] ?? null)->toBeTrue()
        ->and($typesense_fields->get('title')['facet'] ?? null)->toBeNull()
        ->and($typesense_fields->get('tags')['type'] ?? null)->toBe('object[]')
        ->and($typesense_fields->get('tags.id')['type'] ?? null)->toBe('int32[]')
        ->and($typesense_fields->get('tags.id')['facet'] ?? null)->toBeTrue()
        ->and($manager->translateForEngine($schema, 'elasticsearch')['mappings']['properties']['status']['meta']['filterable'] ?? null)->toBeTrue()
        ->and($manager->translateForEngine($schema, 'elasticsearch')['mappings']['properties']['tags']['type'] ?? null)->toBe('nested')
        ->and($manager->translateForEngine($schema, 'elasticsearch')['mappings']['properties']['tags']['meta']['relation'] ?? null)->toBe('tags')
        ->and($manager->translateForEngine($schema, 'elasticsearch')['mappings']['properties']['tags']['properties']['id']['meta']['filterable'] ?? null)->toBeTrue()
        ->and($manager->translateForEngine($schema, 'database')['columns']['status']['filterable'] ?? null)->toBeTrue()
        ->and($manager->translateForEngine($schema, 'database')['columns']['tags']['relation'] ?? null)->toBe('tags')
        ->and($manager->translateForEngine($schema, 'database')['columns']['tags']['properties']['id']['filterable'] ?? null)->toBeTrue()
        ->and($database_indexes->where('type', 'fulltext')->pluck('columns')->all())->toBe([['body']])
        ->and($database_indexes->where('type', 'fuzzy')->pluck('columns')->all())->toBe([['name']])
        ->and($database_indexes->where('type', 'prefix')->pluck('columns')->all())->toBe([['slug']])
        ->and($database_indexes->where('type', 'btree')->pluck('columns')->contains(['title']))->toBeFalse()
        ->and($manager->translateForEngine($schema, 'database')['columns']['title']['filterable'] ?? null)->toBeFalse();
});

it('reads an equality on null as the absence of a value on elasticsearch, which takes no null term', function (): void {
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();
    $missing = ['bool' => ['must_not' => [['exists' => ['field' => 'extended_type']]]]];

    expect($engine->buildSearchFilters(['extended_type' => null]))->toBe([$missing])
        ->and($engine->buildSearchFilters([
            'operator' => 'or',
            'filters' => [
                ['field' => 'extended_type', 'operator' => '=', 'value' => null],
                ['field' => 'extended_type', 'operator' => '!=', 'value' => null],
            ],
        ]))->toBe([[
            'bool' => [
                'should' => [$missing, ['exists' => ['field' => 'extended_type']]],
                'minimum_should_match' => 1,
            ],
        ]]);
});

it('filters an equality on null by the placeholder typesense indexes a null string with', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    $placeholder = TypesenseEngine::NULL_PLACEHOLDER;

    expect($engine->buildSearchFilters(['extended_type' => null]))->toBe(sprintf('extended_type:="%s"', $placeholder))
        ->and($engine->buildSearchFilters([
            'operator' => 'or',
            'filters' => [
                ['field' => 'extended_type', 'operator' => '=', 'value' => null],
                ['field' => 'extended_type', 'operator' => '!=', 'value' => null],
            ],
        ]))->toBe(sprintf('(extended_type:="%s" || extended_type:!="%s")', $placeholder, $placeholder));
});

it('indexes a null in a top-level string field of typesense as the placeholder', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($engine, 'null_placeholder_fields'))->setValue($engine, ['extended_type']);

    $documents = Mockery::mock(Typesense\Documents::class);
    $documents->shouldReceive('import')
        ->once()
        ->with([
            ['id' => '1', 'extended_type' => TypesenseEngine::NULL_PLACEHOLDER, 'title' => null],
            ['id' => '2', 'extended_type' => 'shop.product', 'title' => 'x'],
        ], ['action' => 'upsert'])
        ->andReturn([['success' => true], ['success' => true]]);
    $collection = Mockery::mock(Typesense\Collection::class);
    $collection->shouldReceive('getDocuments')->andReturn($documents);

    (new ReflectionMethod($engine, 'importDocuments'))->invoke($engine, $collection, [
        ['id' => '1', 'extended_type' => null, 'title' => null],
        ['id' => '2', 'extended_type' => 'shop.product', 'title' => 'x'],
    ], 'upsert');
});

it('maps a top-level date to an int64 on typesense, and leaves a nested date a string', function (): void {
    $schema = new SchemaDefinition('contents');
    $schema->addField(new FieldDefinition('valid_from', FieldType::Date, [IndexType::Filterable, IndexType::Sortable]));
    $schema->addField(new FieldDefinition('extension', FieldType::Object, [IndexType::Filterable], [
        'properties' => ['release_date' => ['type' => FieldType::Date, 'filterable' => true]],
    ]));

    $fields = collect(resolve(SchemaManager::class)->translateForEngine($schema, 'typesense')['fields'])->keyBy('name');

    expect($fields->get('valid_from')['type'])->toBe('int64')
        ->and($fields->get('extension.release_date')['type'])->toBe('string');
});

it('filters a date of a typesense model as a timestamp, a null date as its sentinel, and keeps the sentinel out of a range', function (): void {
    Carbon\Carbon::setTestNow('2026-08-19 12:00:00');
    $now = Carbon\Carbon::now()->getTimestamp();
    $null = TypesenseEngine::NULL_TIMESTAMP;

    try {
        $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
        $builder = new ScoutBuilder(new TypesenseDatedStub, 'needle');
        $builder->where('extended_type', null);
        $builder->where('valid_to', null);
        $builder->options['advanced_filters'] = [
            'operator' => 'and',
            'filters' => [
                ['field' => 'valid_from', 'operator' => '<=', 'value' => Carbon\Carbon::now()],
                [
                    'operator' => 'or',
                    'filters' => [
                        ['field' => 'valid_to', 'operator' => '>=', 'value' => '2026-08-19 12:00:00'],
                        ['field' => 'valid_to', 'operator' => '=', 'value' => null],
                    ],
                ],
                ['field' => 'company_id', 'operator' => '=', 'value' => 7],
            ],
        ];

        $parameters = $engine->buildSearchParameters($builder, 1, 10);
    } finally {
        Carbon\Carbon::setTestNow();
    }

    expect($parameters['filter_by'])->toBe(sprintf(
        'extended_type:="%s" && valid_to:=%d && (valid_from:<=%d && valid_from:!=%d) && ((valid_to:>=%d && valid_to:!=%d) || valid_to:=%d) && company_id:=7',
        TypesenseEngine::NULL_PLACEHOLDER,
        $null,
        $now,
        $null,
        $now,
        $null,
        $null,
    ));
});

it('writes a boolean filter value as typesense reads it', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();

    expect($engine->buildSearchFilters(['extension.is_published_in_shop' => true, 'featured' => false]))
        ->toBe('extension.is_published_in_shop:=true && featured:=false');
});

it('indexes a date of a typesense model as a timestamp and a null or missing date as its sentinel', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($engine, 'date_fields'))->setValue($engine, ['valid_from', 'valid_to']);
    $from = Carbon\Carbon::parse('2026-08-01 00:00:00');

    $documents = Mockery::mock(Typesense\Documents::class);
    $documents->shouldReceive('import')
        ->once()
        ->with([
            ['id' => '1', 'valid_from' => $from->getTimestamp(), 'valid_to' => TypesenseEngine::NULL_TIMESTAMP],
            ['id' => '2', 'valid_from' => $from->getTimestamp(), 'valid_to' => TypesenseEngine::NULL_TIMESTAMP],
        ], ['action' => 'upsert'])
        ->andReturn([['success' => true], ['success' => true]]);
    $collection = Mockery::mock(Typesense\Collection::class);
    $collection->shouldReceive('getDocuments')->andReturn($documents);

    (new ReflectionMethod($engine, 'importDocuments'))->invoke($engine, $collection, [
        ['id' => '1', 'valid_from' => $from, 'valid_to' => null],
        ['id' => '2', 'valid_from' => '2026-08-01 00:00:00'],
    ], 'upsert');
});
