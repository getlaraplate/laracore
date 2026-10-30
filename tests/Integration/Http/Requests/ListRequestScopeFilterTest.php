<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Models\ACL;
use Modules\Core\Models\CronJob;
use Modules\Core\Models\License;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    config()->set('permission.roles.superadmin', 'superadmin');
    FakeVectorProfile::none();
    CrudApiExposure::enable();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function reqscope_superadmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('superadmin', 'web'));

    return $user;
}

/**
 * A reader of licenses whose ACL lets it see only the given licenses.
 *
 * @param  list<int>  $license_ids
 */
function reqscope_reader(array $license_ids): App\Models\User
{
    $permission_name = PermissionName::forClass(License::class, 'select');
    Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'web']);
    $permission = Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'api']);

    $role = Role::factory()->create(['name' => 'reqscope_reader_' . uniqid(), 'guard_name' => 'api']);
    $role->givePermissionTo($permission);

    $acl = new ACL;
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $role->id,
        'filters' => new FiltersGroup([new Filter('id', $license_ids, FilterOperator::In)]),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $user = App\Models\User::query()->findOrFail(User::factory()->create(['name' => 'reqscope-reader'])->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Three licenses: valid now, not yet valid (from 2026-08-26), expired.
 *
 * @return array{valid: License, scheduled: License, expired: License}
 */
function reqscope_licenses(): array
{
    return [
        'valid' => License::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]),
        'scheduled' => License::factory()->create(['valid_from' => now()->addWeek(), 'valid_to' => null]),
        'expired' => License::factory()->create(['valid_from' => now()->subWeeks(2), 'valid_to' => now()->subDay()]),
    ];
}

/**
 * @param  array<mixed>  $filters
 */
function reqscope_url(string $base, array $filters): string
{
    return $base . '?filters=' . rawurlencode(json_encode($filters, JSON_THROW_ON_ERROR));
}

/**
 * The parameter values of the scope nodes of a request tree, to show none of them reaches an error message.
 *
 * @param  array<mixed>  $filters
 * @return list<string>
 */
function reqscope_parameter_values(array $filters): array
{
    $values = [];

    array_walk_recursive($filters, static function (mixed $value, int|string $key) use (&$values): void {
        if (is_int($key) && is_string($value)) {
            $values[] = $value;
        }
    });

    return $values;
}

/**
 * The request data parsed from a tree that never went through the request validation, as a caller building
 * ListRequestData by hand (the AI CRUD tools) does.
 *
 * @param  array<mixed>  $filters
 */
function reqscope_unvalidated(string $entity, array $filters): ListRequestData
{
    return new ListRequestData(ListRequest::create('/app/crud/select/core/' . $entity, 'GET'), $entity, ['filters' => $filters], 'id', 'core');
}

/**
 * @return list<int>
 */
function reqscope_ids(TestResponse $response): array
{
    $ids = array_map(static fn (mixed $id): int => (int) $id, collect($response->json('data'))->pluck('id')->all());
    sort($ids);

    return $ids;
}

it('narrows a CRUD list with a client scope named by the request', function (string $base): void {
    $licenses = reqscope_licenses();

    $response = $this->actingAs(reqscope_superadmin())->getJson(reqscope_url($base, ['scope' => 'valid']));

    $response->assertOk();
    expect(reqscope_ids($response))->toBe([$licenses['valid']->id]);
})->with([
    'api' => '/api/v1/select/core/licenses',
    'app' => '/app/crud/select/core/licenses',
]);

it('passes the ISO 8601 date parameter of a request scope to the scope', function (): void {
    $licenses = reqscope_licenses();

    $response = $this->actingAs(reqscope_superadmin())->getJson(reqscope_url('/api/v1/select/core/licenses', [
        'filters' => [['scope' => 'validAt', 'parameters' => ['2026-08-27T00:00:00Z']]],
    ]));

    $response->assertOk();
    expect(reqscope_ids($response))->toBe([$licenses['valid']->id, $licenses['scheduled']->id]);
});

it('refuses with 422 a scope the model exposes only to ACLs, without naming the model class', function (): void {
    CronJob::factory()->create();

    $response = $this->actingAs(reqscope_superadmin())->getJson(reqscope_url('/api/v1/select/core/cron_jobs', ['scope' => 'active']));

    $response->assertUnprocessable()->assertJsonValidationErrors('filters');
    expect(json_encode($response->json('errors'), JSON_THROW_ON_ERROR))
        ->toContain('active')
        ->not->toContain('Models');
});

it('refuses with 422 an undeclared scope and a scope with bad parameters', function (array $filters): void {
    reqscope_licenses();

    $response = $this->actingAs(reqscope_superadmin())->getJson(reqscope_url('/api/v1/select/core/licenses', $filters));

    $response->assertUnprocessable()->assertJsonValidationErrors('filters');
    $errors = json_encode($response->json('errors'), JSON_THROW_ON_ERROR);
    expect($errors)->not->toContain('Models');

    foreach (reqscope_parameter_values($filters) as $value) {
        expect($errors)->not->toContain($value);
    }
})->with([
    'undeclared scope' => [['scope' => 'notAScope']],
    'scope method without the attribute' => [['scope' => 'validityOrdered']],
    'undeclared scope nested in a group' => [['operator' => 'or', 'filters' => [['property' => 'id', 'operator' => '=', 'value' => 1], ['scope' => 'notAScope']]]],
    'missing required parameter' => [['scope' => 'validAt']],
    'extra parameter' => [['scope' => 'valid', 'parameters' => ['2026-08-27']]],
    'parameter of the wrong type' => [['scope' => 'validAt', 'parameters' => ['tomorrow']]],
    'integer parameter given as text' => [['scope' => 'expiring', 'parameters' => ['many']]],
]);

it('does not resolve a placeholder given as a request scope parameter', function (string $placeholder): void {
    reqscope_licenses();

    $response = $this->actingAs(reqscope_superadmin())->getJson(reqscope_url('/api/v1/select/core/licenses', [
        'scope' => 'validAt',
        'parameters' => [$placeholder],
    ]));

    $response->assertUnprocessable()->assertJsonValidationErrors('filters');
})->with(['@now', '@today', '@user.created_at']);

it('combines a request scope with the caller ACL by AND and never widens it', function (array $filters, string $expected): void {
    $licenses = reqscope_licenses();
    $reader = reqscope_reader([$licenses['valid']->id, $licenses['expired']->id]);

    $response = $this->actingAs($reader)->getJson(reqscope_url('/api/v1/select/core/licenses', $filters));

    $response->assertOk();
    $expected_ids = $expected === '' ? [] : [$licenses[$expected]->id];
    expect(reqscope_ids($response))->toBe($expected_ids);
})->with([
    'scope within the ACL' => [['scope' => 'valid'], 'valid'],
    'scope outside the ACL' => [['scope' => 'scheduled'], ''],
    'scope in an OR group' => [['operator' => 'or', 'filters' => [['scope' => 'scheduled'], ['scope' => 'expiredAt', 'parameters' => ['2026-08-19T12:00:00Z']]]], 'expired'],
]);

it('refuses with 422 a request scope on the facets endpoint', function (): void {
    reqscope_licenses();

    $response = $this->actingAs(reqscope_superadmin())->getJson(reqscope_url('/api/v1/facets/core/licenses', ['scope' => 'notAScope']));

    $response->assertUnprocessable()->assertJsonValidationErrors('filters');
});

it('refuses an ACL-only scope in a tree parsed without the request validation, without naming the model class', function (): void {
    try {
        reqscope_unvalidated('cron_jobs', [['scope' => 'active']]);
        $this->fail('The ACL-only scope should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->getMessage())->toContain('"active"')
            ->and($exception->getMessage())->toContain('only in ACLs')
            ->and($exception->getMessage())->not->toContain('Models')
            ->and($exception->errors())->toHaveKey('filters');
    }
});

it('refuses a bad parameter in a tree parsed without the request validation, without echoing it', function (): void {
    try {
        reqscope_unvalidated('licenses', [['scope' => 'validAt', 'parameters' => ['@now']]]);
        $this->fail('The literal placeholder should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->getMessage())->toContain('ISO 8601')
            ->and($exception->getMessage())->not->toContain('@now');
    }
});

it('parses a client scope node into a scope filter', function (): void {
    $data = reqscope_unvalidated('licenses', [['scope' => 'validAt', 'parameters' => ['2026-08-27']]]);

    expect($data->filters?->filters)->toEqual([new ScopeFilter('validAt', ['2026-08-27'])]);
});
