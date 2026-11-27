<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\ScoutSearchConstraintApplier;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Search\Support\DefaultSearchFilters;
use Modules\Core\Search\Support\ScoutWheres;
use Modules\Core\Tests\Stubs\Search\DefaultFilteredSearchStub;
use Modules\Core\Tests\Stubs\Search\EngineBoundStubModel;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;
use Modules\Core\Tests\Support\DefaultFilteredSearchFixture;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * A model's default search filters (ProvidesDefaultSearchFilters) apply to every search path, not only the basic
 * one. The engine of these tests holds every row, the variants too, as an external index does: without the default
 * filter the variants come back from the engine, take places on the page, and are dropped when the hits are reloaded,
 * so the page holds fewer records than asked for.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    config()->set('permission.roles.superadmin', 'superadmin');
    DefaultFilteredSearchFixture::createTable();
});

afterEach(function (): void {
    DefaultFilteredSearchFixture::dropTable();
    Carbon::setTestNow();
});

/**
 * Three records the search may return, then three variants: the engine lists the newest rows first.
 *
 * @return array{eligible: list<int>, variants: list<int>}
 */
function default_filtered_rows(): array
{
    $eligible = array_map(static fn (int $i): int => DefaultFilteredSearchFixture::row('eligible ' . $i), range(1, 3));
    $variants = array_map(static fn (int $i): int => DefaultFilteredSearchFixture::row('variant ' . $i, 'product'), range(1, 3));

    return ['eligible' => $eligible, 'variants' => $variants];
}

/**
 * How many column filters on the field the tree holds, at any depth.
 */
function default_filter_nodes_on(?FiltersGroup $filters, string $field): int
{
    if (! $filters instanceof FiltersGroup) {
        return 0;
    }

    $count = 0;

    foreach ($filters->filters as $node) {
        $count += match (true) {
            $node instanceof FiltersGroup => default_filter_nodes_on($node, $field),
            $node instanceof Filter && $node->property === $field => 1,
            default => 0,
        };
    }

    return $count;
}

it('keeps the records the default search filters exclude out of the engine hits of the orchestrated search', function (): void {
    $rows = default_filtered_rows();

    $hits = app(AdvancedSearchService::class)->search(new DefaultFilteredSearchStub, DefaultFilteredSearchFixture::QUERY, 1, 3)->ids();

    expect(array_values(array_intersect($hits, array_map(strval(...), $rows['variants']))))->toBe([]);
});

it('fills the page when the engine holds records the default search filters exclude', function (SearchMode $mode): void {
    $rows = default_filtered_rows();

    $ids = DefaultFilteredSearchFixture::search($mode, 3);
    sort($ids);

    expect($ids)->toBe($rows['eligible']);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

it('hands the ensemble each default search filter once on the orchestrated path', function (): void {
    $received = [];
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturnUsing(static function (Model $model, string $query, array $plan, ?array $vector, int $page, int $perPage, ?FiltersGroup $filters = null) use (&$received): AdvancedSearchResult {
        $received[] = $filters;

        return AdvancedSearchResult::empty($page, $perPage);
    });
    $advanced = new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new FallbackSearchPlanner, intent_parser: new SimpleQueryIntentParser),
        $ensemble,
        app(),
    );
    app()->instance(AdvancedSearchService::class, $advanced);

    DefaultFilteredSearchFixture::search(SearchMode::Orchestrated, 3);

    expect($received)->not->toBeEmpty();

    foreach ($received as $filters) {
        expect(default_filter_nodes_on($filters, 'variant'))->toBe(1);
    }
});

it('hands the engine the default and the request filters as the basic path does', function (?FiltersGroup $request): void {
    $model = new DefaultFilteredSearchStub;
    $applier = app(ScoutSearchConstraintApplier::class);

    // The basic path: a where() per default filter on the builder, then the request filters.
    $basic = DefaultFilteredSearchStub::search(DefaultFilteredSearchFixture::QUERY);

    foreach ($model->defaultSearchFilters() as $field => $value) {
        $basic->where($field, $value);
    }

    $applier->apply($basic, $model, $request);

    $merged = DefaultFilteredSearchStub::search(DefaultFilteredSearchFixture::QUERY);
    $applier->apply($merged, $model, DefaultSearchFilters::merge($model, $request));

    expect($merged->wheres)->toBe($basic->wheres)
        ->and($merged->whereIns)->toBe($basic->whereIns)
        ->and($merged->whereNotIns)->toBe($basic->whereNotIns)
        ->and($merged->options['advanced_filters'] ?? null)->toBe($basic->options['advanced_filters'] ?? null);
})->with([
    'no request filters' => [null],
    'an equality on another field' => [new FiltersGroup([new Filter('id', 1)])],
    'an equality on the default field' => [new FiltersGroup([new Filter('variant', 'product')])],
    'an in on the default field' => [new FiltersGroup([new Filter('variant', ['product'], FilterOperator::In)])],
    'an OR group' => [new FiltersGroup([new Filter('id', 1), new Filter('id', 2)], WhereClause::Or)],
    'a nested AND group' => [new FiltersGroup([new FiltersGroup([new Filter('id', 1)]), new ScopeFilter('valid')])],
]);

it('lets an explicit equality filter on the default field replace the default on an engine that keeps one per field', function (): void {
    $model = new DefaultFilteredSearchStub;
    $builder = DefaultFilteredSearchStub::search(DefaultFilteredSearchFixture::QUERY);

    app(ScoutSearchConstraintApplier::class)->apply($builder, $model, DefaultSearchFilters::merge($model, new FiltersGroup([new Filter('variant', 'product')])));

    expect(ScoutWheres::map($builder))->toBe(['variant' => 'product']);
});

it('leaves the filters of a model without default search filters as they are', function (): void {
    $filters = new FiltersGroup([new Filter('id', 1)]);

    expect(DefaultSearchFilters::merge(new EngineBoundStubModel, $filters))->toBe($filters)
        ->and(DefaultSearchFilters::merge(new EngineBoundStubModel, null))->toBeNull();
});
