<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\License;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Support\DefaultSearchFilters;
use Modules\Core\Search\Support\ScoutWheres;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Tests\Stubs\Search\DefaultFilteredLicenseSearchStub;
use Modules\Core\Tests\Stubs\Search\ValidatedSearchRequestStub;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    config()->set('permission.roles.superadmin', 'superadmin');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{open: License, scheduled: License, expired: License}
 */
function eligibility_licenses(): array
{
    return [
        'open' => License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'valid_from' => now()->subDay(), 'valid_to' => null]),
        'scheduled' => License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'valid_from' => now()->addWeek(), 'valid_to' => null]),
        'expired' => License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000003', 'valid_from' => now()->subWeeks(2), 'valid_to' => now()->subDay()]),
    ];
}

/**
 * The ids a superadmin finds searching `aaaaaaaa` (the start of every uuid) with the given request filters.
 *
 * @return list<int>
 */
function eligibility_search(SearchMode $mode, ?FiltersGroup $filters): array
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('superadmin', 'web'));
    Auth::login($user);

    $model = new DefaultFilteredLicenseSearchStub;
    $request = new ValidatedSearchRequestStub(['qs' => 'aaaaaaaa']);
    $request->setUserResolver(static fn () => $user);

    $data = (new ReflectionClass(SearchRequestData::class))->newInstanceWithoutConstructor();

    foreach ([
        'request' => $request,
        'mainEntity' => $model->getTable(),
        'primaryKey' => $model->getKeyName(),
        'model' => $model,
        'connection' => $model->getConnectionName(),
        'columns' => [],
        'relations' => [],
        'sort' => [],
        'filters' => $filters,
        'group_by' => [],
        'qs' => 'aaaaaaaa',
        'mode' => $mode,
        'page' => null,
        'limit' => 10,
        'pagination' => 10,
        'from' => null,
        'to' => null,
        'count' => false,
    ] as $property => $value) {
        (new ReflectionProperty($data, $property))->setValue($data, $value);
    }

    $result = app(CrudService::class)->search($data);

    expect($result->error)->toBeNull();

    $ids = $result->data->map(static fn (Model $record): int => (int) $record->getKey())->all();
    sort($ids);

    return $ids;
}

it('applies the default search filters on every search path', function (SearchMode $mode): void {
    $licenses = eligibility_licenses();

    expect(eligibility_search($mode, null))->toBe([$licenses['open']->id, $licenses['scheduled']->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

it('never lets a request filter widen a default on any search path', function (SearchMode $mode): void {
    $licenses = eligibility_licenses();
    $filters = new FiltersGroup([new Filter('valid_to', $licenses['expired']->valid_to, FilterOperator::Equals)]);

    expect(eligibility_search($mode, $filters))->toBe([]);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

/**
 * Stores the same embedding for every license, so the vector strategy finds all of them unless a filter
 * narrows the candidates.
 *
 * @param  array<string, License>  $licenses
 */
function eligibility_embed(array $licenses): void
{
    $now = now();

    foreach ($licenses as $license) {
        (new ModelEmbedding)->getConnection()->table(CoreTables::ModelEmbeddings->value)->insert([
            'model_type' => DefaultFilteredLicenseSearchStub::class,
            'model_id' => $license->id,
            'embedding' => json_encode([1.0, 0.0, 0.0], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

/**
 * The ids each strategy of the ensemble (keyword, vector, hybrid) returns on the database engine.
 *
 * @return array<string, list<int>>
 */
function eligibility_strategy_ids(?FiltersGroup $filters): array
{
    $result = app(EnsembleSearchService::class)->search(
        model: new DefaultFilteredLicenseSearchStub,
        query: 'aaaaaaaa',
        plan: [
            'retrieval' => ['use_fulltext' => true, 'use_vector' => true],
            'ensemble' => [],
            'ranking' => ['use_reranker' => false],
        ],
        vector: [1.0, 0.0, 0.0],
        page: 1,
        perPage: 10,
        filters: $filters,
    );

    $per_strategy = [];

    foreach ($result->meta['per_strategy'] as $strategy => $hits) {
        $ids = array_map(intval(...), array_keys($hits));
        sort($ids);
        $per_strategy[$strategy] = $ids;
    }

    return $per_strategy;
}

it('carries the default search filters on the keyword, vector and hybrid strategies', function (): void {
    $licenses = eligibility_licenses();
    eligibility_embed($licenses);

    $eligible = [$licenses['open']->id, $licenses['scheduled']->id];

    expect(eligibility_strategy_ids(null))->toBe([
        'keyword' => $eligible,
        'vector' => $eligible,
        'hybrid' => $eligible,
    ]);
});

it('never lets a request filter widen a default on any strategy', function (): void {
    $licenses = eligibility_licenses();
    eligibility_embed($licenses);
    $filters = new FiltersGroup([new Filter('valid_to', $licenses['expired']->valid_to, FilterOperator::Equals)]);

    $per_strategy = eligibility_strategy_ids($filters);

    expect(array_keys($per_strategy))->toBe(['keyword', 'vector', 'hybrid']);

    foreach ($per_strategy as $ids) {
        expect($ids)->not->toContain($licenses['expired']->id);
    }
});

it('applies the default after the request constraints, so the default keeps the field on a field map', function (): void {
    $model = new DefaultFilteredLicenseSearchStub;
    $builder = DefaultFilteredLicenseSearchStub::search('aaaaaaaa');
    $builder->where('valid_to', '2026-08-18 12:00:00');

    DefaultSearchFilters::apply($builder, $model);

    expect(ScoutWheres::map($builder))->toBe(['valid_to' => null])
        ->and($builder->wheres)->toHaveCount(2);
});
