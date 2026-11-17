<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Search\DefaultFilteredSearchStub;
use Modules\Core\Tests\Support\DefaultFilteredSearchFixture;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * The search paths may differ in recall and ranking, never in what they let into the results. On the same rows,
 * the same request filters and the same user, Basic, Orchestrated and Auto return no record that another of them
 * excludes by an eligibility constraint: the model's default search filters, the ACL row filters of the user, the
 * validity those filters ask for.
 *
 * The limit holds every row, so recall plays no part: each path must return exactly the eligible records.
 *
 * Locale is not covered here: the database engine has no locale field to filter on (it is not an
 * ILocaleFilterableEngine), so no path filters by locale at this engine.
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
 * A user who may select the stub rows, but only the given keys and only while they are valid.
 *
 * @param  list<int>  $allowed_ids
 */
function eligibility_restricted_user(array $allowed_ids): User
{
    $permission = Permission::create(['name' => 'default.' . DefaultFilteredSearchStub::TABLE . '.select', 'guard_name' => 'web']);
    $role = Role::factory()->create(['name' => 'eligibility_role_' . uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => new FiltersGroup([
            new Filter('id', $allowed_ids, FilterOperator::In),
            new ScopeFilter('valid'),
        ], WhereClause::And),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @return array{eligible: list<int>, user: User}
 */
function eligibility_rows(): array
{
    $eligible = [
        DefaultFilteredSearchFixture::row('first'),
        DefaultFilteredSearchFixture::row('second'),
        DefaultFilteredSearchFixture::row('third'),
    ];
    // Excluded by the default search filters of the model.
    $variant = DefaultFilteredSearchFixture::row('variant', 'product');
    // Excluded by the ACL row filters: not among the keys the user may read.
    DefaultFilteredSearchFixture::row('denied');
    // Excluded by the validity the ACL asks for: it expired yesterday.
    $expired = DefaultFilteredSearchFixture::row('expired', null, now()->subDay()->toDateTimeString());

    return [
        'eligible' => $eligible,
        'user' => eligibility_restricted_user([...$eligible, $variant, $expired]),
    ];
}

/**
 * @return array<string, list<int>>
 */
function eligibility_results_by_mode(User $user, ?FiltersGroup $filters): array
{
    $results = [];

    foreach ([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto] as $mode) {
        $ids = DefaultFilteredSearchFixture::search($mode, 20, $filters, $user);
        sort($ids);
        $results[$mode->value] = $ids;
    }

    return $results;
}

it('lets no search path return a record another path excludes by an eligibility constraint', function (?FiltersGroup $filters): void {
    $rows = eligibility_rows();

    $results = eligibility_results_by_mode($rows['user'], $filters);

    expect($results)->toBe([
        'basic' => $rows['eligible'],
        'orchestrated' => $rows['eligible'],
        'auto' => $rows['eligible'],
    ]);
})->with([
    'no request filters' => [null],
    'a request scope' => [new FiltersGroup([new ScopeFilter('valid')])],
    'a request filter on another field' => [new FiltersGroup([new Filter('id', [0], FilterOperator::NotEquals)])],
]);

it('lets no search path return a record another path excludes, for a user the ACL does not restrict', function (): void {
    $eligible = [DefaultFilteredSearchFixture::row('first'), DefaultFilteredSearchFixture::row('second')];
    DefaultFilteredSearchFixture::row('variant', 'product');

    $results = eligibility_results_by_mode(DefaultFilteredSearchFixture::superadmin(), null);

    expect($results)->toBe([
        'basic' => $eligible,
        'orchestrated' => $eligible,
        'auto' => $eligible,
    ]);
});
