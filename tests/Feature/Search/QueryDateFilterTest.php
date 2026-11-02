<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Models\License;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\ScoutSearchConstraintApplier;
use Modules\Core\Search\Support\QueryDateFilter;
use Modules\Core\Tests\Stubs\Search\DatedEngineBoundStubModel;
use Modules\Core\Tests\Stubs\Search\DatedLicenseSearchStub;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;
use Modules\Core\Tests\Stubs\Search\ScopedLicenseSearchStub;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-02 12:00:00');
    FakeVectorProfile::none();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A search service whose ensemble records the query and filters of each call and returns, call after call,
 * the given numbers of hits (the last one repeats).
 *
 * @param  list<int>  $hit_counts
 * @param  list<array{query: string, filters: FiltersGroup|null}>  $calls
 */
function dated_service(array $hit_counts, array &$calls, ?Closure $fail = null): AdvancedSearchService
{
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturnUsing(
        function (Model $model, string $query, array $plan, ?array $vector, int $page, int $perPage, ?FiltersGroup $filters = null) use (&$calls, $hit_counts, $fail): AdvancedSearchResult {
            $calls[] = ['query' => $query, 'filters' => $filters];

            if ($fail instanceof Closure) {
                $fail($filters);
            }

            $count = $hit_counts[min(count($calls) - 1, count($hit_counts) - 1)];
            $hits = array_map(static fn (int $i): array => ['id' => 'doc-' . $i, 'score' => 1.0, 'source' => []], $count === 0 ? [] : range(1, $count));

            return new AdvancedSearchResult($hits, $count, $page, $perPage, 1, ['strategies_executed' => 1]);
        },
    );

    return new AdvancedSearchService(new FixedSearchStrategyResolver, $ensemble, app());
}

function dated_model(?string $date_field = 'published_at'): DatedEngineBoundStubModel
{
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();
    $engine->shouldReceive('supportsOrchestratedVectorSearch')->andReturnTrue();

    return new DatedEngineBoundStubModel($engine, $date_field);
}

/**
 * The date conditions a call carried, as `[operator, value]` pairs on the given field.
 *
 * @return list<array{0: string, 1: mixed}>
 */
function dated_conditions(?FiltersGroup $filters, string $field = 'published_at'): array
{
    if (! $filters instanceof FiltersGroup) {
        return [];
    }

    $conditions = [];

    foreach ($filters->filters as $node) {
        if ($node instanceof FiltersGroup) {
            $conditions = [...$conditions, ...dated_conditions($node, $field)];
        } elseif ($node instanceof Filter && $node->property === $field) {
            $conditions[] = [$node->operator->value, $node->value];
        }
    }

    return $conditions;
}

it('applies the date the query names as a range filter on the declared field', function (): void {
    $calls = [];
    $result = dated_service([2], $calls)->search(dated_model(), 'reports of 2024', 1, 10);

    expect($calls)->toHaveCount(1)
        ->and(dated_conditions($calls[0]['filters']))->toBe([['>=', '2024-01-01'], ['<', '2025-01-01']])
        ->and($calls[0]['query'])->toBe('reports')
        ->and($result->meta['date_filter'])->toBe([
            'field' => 'published_at',
            'source' => 'query',
            'from' => '2024-01-01',
            'to' => '2024-12-31',
            'widened' => 0,
            'applied' => true,
        ]);
});

it('keeps the filters of the request next to the date filter', function (): void {
    $calls = [];
    $status = new Filter('status', 'published', FilterOperator::Equals);
    dated_service([2], $calls)->search(dated_model(), 'reports of 2024', 1, 10, new FiltersGroup([$status]));

    expect($calls[0]['filters']->filters[0]->filters)->toBe([$status])
        ->and(dated_conditions($calls[0]['filters']))->toHaveCount(2);
});

it('use explicit request dates over text dates', function (): void {
    $calls = [];
    $request = new FiltersGroup([new Filter('published_at', ['2020-01-01', '2020-12-31'], FilterOperator::Between)]);
    $result = dated_service([0], $calls, null)->search(dated_model(), 'reports of 2024', 1, 10, $request);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['filters'])->toBe($request)
        ->and($calls[0]['query'])->toBe('reports of 2024')
        ->and($result->meta)->not->toHaveKey('date_filter');
});

it('null date field means no filter', function (): void {
    $calls = [];
    $result = dated_service([0], $calls)->search(dated_model(null), 'reports of 2024', 1, 10);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['filters'])->toBeNull()
        ->and($calls[0]['query'])->toBe('reports of 2024')
        ->and($result->meta)->not->toHaveKey('date_filter');
});

it('applies no filter to a query that names no date', function (): void {
    $calls = [];
    $result = dated_service([0], $calls)->search(dated_model(), 'climate reports', 1, 10);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['filters'])->toBeNull()
        ->and($result->meta)->not->toHaveKey('date_filter');
});

it('an interval with no hit widens through recency_windows_days and ends unfiltered', function (): void {
    config()->set('core.search.recency_windows_days', [90, 365]);
    $calls = [];
    $result = dated_service([0, 0, 0, 4], $calls)->search(dated_model(), 'reports of 2024', 1, 10);

    expect($calls)->toHaveCount(4)
        ->and(dated_conditions($calls[0]['filters']))->toBe([['>=', '2024-01-01'], ['<', '2025-01-01']])
        ->and(dated_conditions($calls[1]['filters']))->toBe([['>=', '2023-10-03'], ['<', '2025-04-01']])
        ->and(dated_conditions($calls[2]['filters']))->toBe([['>=', '2023-01-01'], ['<', '2026-01-01']])
        ->and($calls[3]['filters'])->toBeNull()
        ->and($calls[3]['query'])->toBe('reports of 2024')
        ->and($result->total)->toBe(4)
        ->and($result->meta['date_filter'])->toMatchArray(['applied' => false, 'widened' => 3, 'from' => null, 'to' => null]);
});

it('stops widening at the first window that finds something', function (): void {
    config()->set('core.search.recency_windows_days', [90, 365]);
    $calls = [];
    $result = dated_service([0, 1], $calls)->search(dated_model(), 'reports of 2024', 1, 10);

    expect($calls)->toHaveCount(2)
        ->and($result->meta['date_filter'])->toMatchArray(['applied' => true, 'widened' => 1, 'from' => '2023-10-03', 'to' => '2025-03-31']);
});

it('answers a recency request with the windows in order and stops at the first with enough sources', function (): void {
    config()->set('core.search.recency_windows_days', [90, 365]);
    config()->set('core.search.recency_min_results', 3);
    $calls = [];
    $result = dated_service([1, 5], $calls)->search(dated_model(), 'latest reports', 1, 10);

    expect($calls)->toHaveCount(2)
        ->and(dated_conditions($calls[0]['filters']))->toBe([['>=', '2026-08-04'], ['<', '2026-11-03']])
        ->and(dated_conditions($calls[1]['filters']))->toBe([['>=', '2025-11-02'], ['<', '2026-11-03']])
        ->and($calls[1]['query'])->toBe('reports')
        ->and($result->meta['date_filter'])->toMatchArray(['source' => 'recent', 'applied' => true, 'widened' => 1]);
});

it('ends a recency request unfiltered when no window has enough sources', function (): void {
    config()->set('core.search.recency_windows_days', [90]);
    $calls = [];
    dated_service([0, 0], $calls)->search(dated_model(), 'ultime notizie', 1, 10);

    expect($calls)->toHaveCount(2)
        ->and($calls[1]['filters'])->toBeNull()
        ->and($calls[1]['query'])->toBe('ultime notizie');
});

it('searches without the date when the engine cannot filter on the declared field', function (): void {
    $calls = [];
    $fail = static function (?FiltersGroup $filters): void {
        if ($filters instanceof FiltersGroup) {
            throw new InvalidArgumentException(ScoutSearchConstraintApplier::FILTER_ERROR);
        }
    };
    $result = dated_service([3], $calls, $fail)->search(dated_model(), 'reports of 2024', 1, 10);

    expect($calls)->toHaveCount(2)
        ->and($calls[1]['filters'])->toBeNull()
        ->and($result->total)->toBe(3)
        ->and($result->meta['date_filter'])->toMatchArray(['applied' => false, 'reason' => 'field_not_filterable']);
});

it('still fails a search whose own filters the engine cannot take', function (): void {
    $calls = [];
    $fail = static fn (): never => throw new InvalidArgumentException(ScoutSearchConstraintApplier::FILTER_ERROR);

    dated_service([3], $calls, $fail)->search(dated_model(), 'reports of 2024', 1, 10, new FiltersGroup([new Filter('secret', 1)]));
})->throws(InvalidArgumentException::class);

it('reads the date field a model declares and null for one that declares none', function (): void {
    expect((new DatedLicenseSearchStub)->searchDateField())->toBe('valid_from')
        ->and((new ScopedLicenseSearchStub)->searchDateField())->toBeNull();
});

it('filters the database engine hits by the date the query names', function (): void {
    $in_2024 = License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'valid_from' => '2024-06-15 10:00:00', 'valid_to' => null]);
    License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'valid_from' => '2025-02-01 10:00:00', 'valid_to' => null]);
    $last_day = License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000003', 'valid_from' => '2024-12-31 23:30:00', 'valid_to' => null]);

    $service = new AdvancedSearchService(new FixedSearchStrategyResolver, app(EnsembleSearchService::class), app());
    $result = $service->search(new DatedLicenseSearchStub, 'aaaaaaaa del 2024', 1, 10);
    $ids = $result->ids();
    sort($ids);

    expect($ids)->toBe([(string) $in_2024->id, (string) $last_day->id])
        ->and($result->meta['date_filter']['applied'])->toBeTrue();
});

it('hands the elasticsearch engine the date filter as a range clause', function (): void {
    $date_filter = QueryDateFilter::forInterval('valid_from', '2024-01-01', '2024-12-31', [90]);
    $builder = new ScoutBuilder(new DatedLicenseSearchStub, 'reports');

    app(ScoutSearchConstraintApplier::class)->apply($builder, new DatedLicenseSearchStub, $date_filter->filters(0, null));

    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();

    expect($engine->buildSearchFilters($builder->options['advanced_filters']))->toBe([
        [
            'bool' => [
                'must' => [
                    ['range' => ['valid_from' => ['gte' => '2024-01-01']]],
                    ['range' => ['valid_from' => ['lt' => '2025-01-01']]],
                ],
            ],
        ],
    ]);
});
