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
use Modules\Core\Models\License;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
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

it('ANDs a request filter on a defaulted field the same way on every path', function (SearchMode $mode): void {
    $licenses = eligibility_licenses();
    $filters = new FiltersGroup([new Filter('valid_to', $licenses['expired']->valid_to, FilterOperator::Equals)]);

    expect(eligibility_search($mode, $filters))->toBe([]);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);
