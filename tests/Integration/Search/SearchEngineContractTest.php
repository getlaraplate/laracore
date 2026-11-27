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
use Modules\Core\Tests\Stubs\Search\DatabaseEngineSQLiteVectorSearchUser;

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

it('filters a null equality as a missing field on Elasticsearch', function (): void {
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();

    $filters = $engine->buildSearchFilters(['extended_type' => null, 'type' => 'news']);

    expect($filters)->toBe([
        ['bool' => ['must_not' => [['exists' => ['field' => 'extended_type']]]]],
        ['term' => ['type' => 'news']],
    ]);
});

it('skips a null equality in typesense keyword search parameters instead of failing', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    $builder = new ScoutBuilder(new DatabaseEngineSQLiteVectorSearchUser(), 'alpha');
    $builder->where('extended_type', null);
    $builder->where('type', 'news');

    $parameters = $engine->buildSearchParameters($builder, 1, 10);

    expect($parameters['filter_by'])->toBe('type:=news');
});

it('skips a null equality in typesense vector search filters instead of writing an empty one', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    $builder = new ScoutBuilder(new DatabaseEngineSQLiteVectorSearchUser(), '*');
    $builder->where('vector', [1.0, 0.0]);
    $builder->where('extended_type', null);
    $builder->where('type', 'news');

    $filters = (new ReflectionMethod(TypesenseEngine::class, 'buildFiltersFromBuilder'))->invoke($engine, $builder);

    expect($filters)->toBe('type:="news"')
        ->and($engine->buildSearchFilters(['extended_type' => null, 'type' => 'news']))->toBe('type:="news"');
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
