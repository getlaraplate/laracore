<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\Sort;
use Modules\Core\Casts\SortDirection;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Search\Exceptions\UnsupportedSearchEngineException;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\Search\Services\ScoutSearchConstraintApplier;
use Modules\Core\Search\Translators\ElasticsearchTranslator;

function scout_constraint_fake_builder(): object
{
    return new class
    {
        /**
         * @var list<array{method: string, field: string, value: mixed}>
         */
        public array $calls = [];

        /**
         * @var array<string, mixed>
         */
        public array $options = [];

        public function where(string $field, mixed $value): self
        {
            $this->calls[] = ['method' => 'where', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function whereIn(string $field, array $value): self
        {
            $this->calls[] = ['method' => 'whereIn', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function whereNotIn(string $field, array $value): self
        {
            $this->calls[] = ['method' => 'whereNotIn', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function orderBy(string $field, string $direction): self
        {
            $this->calls[] = ['method' => 'orderBy', 'field' => $field, 'value' => $direction];

            return $this;
        }
    };
}

function scout_constraint_queryable_fake_builder(): object
{
    return new class
    {
        /**
         * @var list<array{method: string, field: string, value: mixed}>
         */
        public array $calls = [];

        /**
         * @var array<string, mixed>
         */
        public array $options = [];

        public mixed $queryCallback = null;

        public function where(string $field, mixed $value): self
        {
            $this->calls[] = ['method' => 'where', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function whereIn(string $field, array $value): self
        {
            $this->calls[] = ['method' => 'whereIn', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function whereNotIn(string $field, array $value): self
        {
            $this->calls[] = ['method' => 'whereNotIn', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function orderBy(string $field, string $direction): self
        {
            $this->calls[] = ['method' => 'orderBy', 'field' => $field, 'value' => $direction];

            return $this;
        }

        public function query(callable $callback): self
        {
            $this->queryCallback = $callback;

            return $this;
        }
    };
}

function scout_constraint_fake_query(): object
{
    return new class
    {
        /**
         * @var list<array{method: string, relation?: string, field?: string, operator?: string, value?: mixed, nested?: list<array<string, mixed>>}>
         */
        public array $calls = [];

        public function where(callable|string $field, ?string $operator = null, mixed $value = null): self
        {
            if (is_callable($field)) {
                $nested = scout_constraint_fake_query();
                $field($nested);
                $this->calls[] = ['method' => 'whereNested', 'nested' => $nested->calls];

                return $this;
            }

            $this->calls[] = ['method' => 'where', 'field' => $field, 'operator' => (string) $operator, 'value' => $value];

            return $this;
        }

        public function whereIn(string $field, array $value): self
        {
            $this->calls[] = ['method' => 'whereIn', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function whereNotIn(string $field, array $value): self
        {
            $this->calls[] = ['method' => 'whereNotIn', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function whereBetween(string $field, array $value): self
        {
            $this->calls[] = ['method' => 'whereBetween', 'field' => $field, 'value' => $value];

            return $this;
        }

        public function whereHas(string $relation, callable $callback): self
        {
            $nested = scout_constraint_fake_query();
            $callback($nested);
            $this->calls[] = ['method' => 'whereHas', 'relation' => $relation, 'nested' => $nested->calls];

            return $this;
        }

        public function whereDoesntHave(string $relation, callable $callback): self
        {
            $nested = scout_constraint_fake_query();
            $callback($nested);
            $this->calls[] = ['method' => 'whereDoesntHave', 'relation' => $relation, 'nested' => $nested->calls];

            return $this;
        }
    };
}

it('applies the portable scout search constraint subset', function (): void {
    $builder = scout_constraint_fake_builder();
    $model = new class extends Model
    {
        protected $table = 'users';
    };

    (new ScoutSearchConstraintApplier())->apply(
        builder: $builder,
        model: $model,
        filters: new FiltersGroup([
            new Filter('users.id', 10, FilterOperator::Equals),
            new FiltersGroup([
                new Filter('status', ['draft', 'published'], FilterOperator::In),
                new Filter('lang', 'it', FilterOperator::NotEquals),
            ]),
        ]),
        sort: [new Sort('users.name', SortDirection::Desc)],
    );

    expect($builder->calls)->toBe([
        ['method' => 'where', 'field' => 'id', 'value' => 10],
        ['method' => 'whereIn', 'field' => 'status', 'value' => ['draft', 'published']],
        ['method' => 'whereNotIn', 'field' => 'lang', 'value' => ['it']],
        ['method' => 'orderBy', 'field' => 'name', 'value' => 'desc'],
    ]);
});

it('records advanced portable filters for engine-owned translation', function (): void {
    $builder = scout_constraint_fake_builder();
    $model = new class extends Model
    {
        protected $table = 'users';
    };

    (new ScoutSearchConstraintApplier())->apply(
        builder: $builder,
        model: $model,
        filters: new FiltersGroup([
            new Filter('id', 10, FilterOperator::GreatEquals),
            new Filter('id', 20, FilterOperator::Less),
            new Filter('created_at', ['2024-01-01', '2024-01-31'], FilterOperator::Between),
            new FiltersGroup([
                new Filter('status', 'draft', FilterOperator::Equals),
                new Filter('status', 'published', FilterOperator::Equals),
            ], WhereClause::Or),
        ]),
    );

    expect($builder->calls)->toBe([])
        ->and($builder->options['advanced_filters'])->toMatchArray([
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
});

it('limits filters to explicitly filterable searchable schema fields when declared', function (): void {
    $builder = scout_constraint_fake_builder();
    $model = new class extends Model
    {
        protected $table = 'users';

        /**
         * @return array<string, mixed>
         */
        public function getSearchMapping(): array
        {
            return [
                'fields' => [
                    ['name' => 'status', 'type' => 'string', 'facet' => true],
                    ['name' => 'author_name', 'type' => 'string', 'filterable' => true],
                    ['name' => 'title', 'type' => 'string'],
                ],
            ];
        }
    };

    (new ScoutSearchConstraintApplier())->apply(
        builder: $builder,
        model: $model,
        filters: new FiltersGroup([
            new Filter('status', 'published', FilterOperator::Equals),
            new Filter('author_name', 'Alice', FilterOperator::Equals),
        ]),
    );

    expect($builder->calls)->toBe([
        ['method' => 'where', 'field' => 'status', 'value' => 'published'],
        ['method' => 'where', 'field' => 'author_name', 'value' => 'Alice'],
    ]);

    expect(fn () => (new ScoutSearchConstraintApplier())->apply(
        builder: scout_constraint_fake_builder(),
        model: $model,
        filters: new FiltersGroup([new Filter('title', 'needle', FilterOperator::Equals)]),
    ))->toThrow(
        InvalidArgumentException::class,
        'Search filters must be applied by the search engine to keep pagination consistent.',
    );
});

it('accepts a filter on a typed sub-field of an object field in an Elasticsearch mapping', function (): void {
    $builder = scout_constraint_fake_builder();
    $model = new class extends Model
    {
        protected $table = 'contents';

        /**
         * @return array<string, mixed>
         */
        public function getSearchMapping(): array
        {
            $schema = new SchemaDefinition('contents');
            $schema->addField(new FieldDefinition('extension', FieldType::Object, [IndexType::Searchable, IndexType::Filterable], [
                'properties' => [
                    'kind' => ['type' => FieldType::Keyword, 'filterable' => true],
                    'label' => FieldType::Keyword,
                ],
            ]));

            return (new ElasticsearchTranslator)->translate($schema);
        }
    };

    (new ScoutSearchConstraintApplier())->apply(
        builder: $builder,
        model: $model,
        filters: new FiltersGroup([new Filter('extension.kind', 'physical', FilterOperator::Equals)]),
    );

    expect($builder->calls)->toBe([
        ['method' => 'where', 'field' => 'extension.kind', 'value' => 'physical'],
    ]);

    expect(fn () => (new ScoutSearchConstraintApplier())->apply(
        builder: scout_constraint_fake_builder(),
        model: $model,
        filters: new FiltersGroup([new Filter('extension.label', 'x', FilterOperator::Equals)]),
    ))->toThrow(InvalidArgumentException::class, ScoutSearchConstraintApplier::FILTER_ERROR);
});

it('keeps legacy scalar filter behavior when searchable mapping cannot be translated for the active scout engine', function (): void {
    $builder = scout_constraint_fake_builder();
    $model = new class extends Model
    {
        protected $table = 'users';

        /**
         * @return array<string, mixed>
         */
        public function getSearchMapping(): array
        {
            throw new UnsupportedSearchEngineException('Unsupported engine Laravel\Scout\Engines\CollectionEngine');
        }
    };

    (new ScoutSearchConstraintApplier())->apply(
        builder: $builder,
        model: $model,
        filters: new FiltersGroup([new Filter('status', 'published', FilterOperator::Equals)]),
    );

    expect($builder->calls)->toBe([
        ['method' => 'where', 'field' => 'status', 'value' => 'published'],
    ]);
});

it('accepts declared indexed relation field filters and applies database callbacks through relation constraints', function (): void {
    $builder = scout_constraint_queryable_fake_builder();
    $model = new class extends Model
    {
        protected $table = 'contents';

        /**
         * @return array<string, mixed>
         */
        public function getSearchMapping(): array
        {
            return [
                'fields' => [
                    [
                        'name' => 'tags',
                        'type' => 'object[]',
                        'facet' => true,
                        'relation' => 'tags',
                        'fields' => [
                            ['name' => 'id', 'type' => 'int32', 'filterable' => true],
                            ['name' => 'slug', 'type' => 'string', 'filterable' => true],
                            ['name' => 'name', 'type' => 'string'],
                        ],
                    ],
                ],
            ];
        }
    };

    (new ScoutSearchConstraintApplier())->apply(
        builder: $builder,
        model: $model,
        filters: new FiltersGroup([
            new Filter('tags.id', 10, FilterOperator::Equals),
            new Filter('tags.slug', ['draft', 'archived'], FilterOperator::NotEquals),
        ]),
    );

    expect($builder->calls)->toBe([])
        ->and($builder->options['advanced_filters'])->toMatchArray([
            'operator' => 'and',
            'filters' => [
                ['field' => 'tags.id', 'operator' => '=', 'value' => 10, 'relation' => 'tags', 'relation_field' => 'id'],
                ['field' => 'tags.slug', 'operator' => '!=', 'value' => ['draft', 'archived'], 'relation' => 'tags', 'relation_field' => 'slug'],
            ],
        ]);

    $query = scout_constraint_fake_query();
    ($builder->queryCallback)($query);

    expect($query->calls)->toMatchArray([
        [
            'method' => 'whereNested',
            'nested' => [
                [
                    'method' => 'whereHas',
                    'relation' => 'tags',
                    'nested' => [
                        ['method' => 'where', 'field' => 'id', 'operator' => '=', 'value' => 10],
                    ],
                ],
                [
                    'method' => 'whereDoesntHave',
                    'relation' => 'tags',
                    'nested' => [
                        ['method' => 'whereIn', 'field' => 'slug', 'value' => ['draft', 'archived']],
                    ],
                ],
            ],
        ],
    ]);

    expect(fn () => (new ScoutSearchConstraintApplier())->apply(
        builder: scout_constraint_queryable_fake_builder(),
        model: $model,
        filters: new FiltersGroup([new Filter('tags.name', 'news', FilterOperator::Equals)]),
    ))->toThrow(
        InvalidArgumentException::class,
        'Search filters must be applied by the search engine to keep pagination consistent.',
    );
});

it('rejects non portable search filters and relation path sorts', function (FiltersGroup $filters, array $sort, string $message): void {
    $builder = scout_constraint_fake_builder();
    $model = new class extends Model
    {
        protected $table = 'users';
    };

    expect(fn () => (new ScoutSearchConstraintApplier())->apply($builder, $model, $filters, $sort))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'like filter' => [
        new FiltersGroup([new Filter('title', 'needle', FilterOperator::Like)]),
        [],
        'Search filters must be applied by the search engine to keep pagination consistent.',
    ],
    'relation path filter' => [
        new FiltersGroup([new Filter('author.name', 'Alice')]),
        [],
        'Search filters must be applied by the search engine to keep pagination consistent.',
    ],
    'relation path sort' => [
        new FiltersGroup(),
        [new Sort('author.name')],
        'Search sort must be applied by the search engine to keep pagination consistent.',
    ],
]);
