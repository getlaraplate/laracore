<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\License;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Tests\Stubs\Search\ScopedLicenseSearchStub;
use Modules\Core\Tests\Stubs\Search\ValidatedSearchRequestStub;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * The database engine is a search engine like the others (S8): it takes no scope node. Every license matches
 * the text query, so only the reload of the hits, where the scope runs, narrows them.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    FakeVectorProfile::none();
    config()->set('permission.roles.superadmin', 'superadmin');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{valid: License, scheduled: License, expired: License}
 */
function dbscope_licenses(): array
{
    return [
        'valid' => License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'valid_from' => now()->subDay(), 'valid_to' => null]),
        'scheduled' => License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'valid_from' => now()->addWeek(), 'valid_to' => null]),
        'expired' => License::factory()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000003', 'valid_from' => now()->subWeeks(2), 'valid_to' => now()->subDay()]),
    ];
}

/**
 * The ids a superadmin finds searching `aaaaaaaa` (the start of every uuid) with the given request filters.
 *
 * @return list<int>
 */
function dbscope_search(SearchMode $mode, ?FiltersGroup $filters): array
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('superadmin', 'web'));
    Auth::login($user);

    $model = new ScopedLicenseSearchStub;
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

it('finds every license when nothing narrows the search', function (SearchMode $mode): void {
    $licenses = dbscope_licenses();

    expect(dbscope_search($mode, null))->toBe([$licenses['valid']->id, $licenses['scheduled']->id, $licenses['expired']->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated]);

it('enforces a request scope dropped from an AND group with the database engine', function (SearchMode $mode): void {
    $licenses = dbscope_licenses();

    expect(dbscope_search($mode, new FiltersGroup([new ScopeFilter('valid')])))->toBe([$licenses['valid']->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated]);

it('enforces a request OR group holding a scope, left out of the database engine whole', function (SearchMode $mode): void {
    $licenses = dbscope_licenses();
    $filters = new FiltersGroup([
        new ScopeFilter('scheduled'),
        new Filter('uuid', $licenses['expired']->uuid, FilterOperator::Equals),
    ], WhereClause::Or);

    expect(dbscope_search($mode, $filters))->toBe([$licenses['scheduled']->id, $licenses['expired']->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated]);
