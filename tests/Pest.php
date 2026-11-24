<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| All Core Pest tests use LaravelTestCase (full app + RefreshDatabase). A single
| directory binding avoids duplicate Pest bindings when a file also calls uses().
|
| Speed: `composer run test:pest` (parallel) or `composer run test:pest:serial` in Core.
| `composer test` runs type coverage, unit coverage, PHPStan, Pint, etc., and is slower by design.
| ParaTest (`--parallel`) can crash workers with coverage; use `test:unit:parallel` only when stable.
|--------------------------------------------------------------------------
*/
uses(Modules\Core\Tests\TestCase::class)->in(__DIR__ . '/Unit');

uses(Modules\Core\Tests\LaravelTestCase::class)->in(
    __DIR__ . '/Integration',
    __DIR__ . '/Feature',
);

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a test user with admin role.
 */
function createAdminUser(): Modules\Core\Models\User
{
    $user = Modules\Core\Models\User::factory()->create();
    $adminRole = Modules\Core\Models\Role::factory()->create(['name' => 'admin']);
    $user->roles()->attach($adminRole);

    return $user;
}

/**
 * Create a test user with specific role.
 */
function createUserWithRole(string $roleName): Modules\Core\Models\User
{
    $user = Modules\Core\Models\User::factory()->create();
    $role = Modules\Core\Models\Role::factory()->create(['name' => $roleName]);
    $user->roles()->attach($role);

    return $user;
}

/**
 * Assert that a model has the expected attributes.
 */
function expectModelAttributes($model, array $attributes): void
{
    foreach ($attributes as $key => $value) {
        expect($model->{$key})->toBe($value);
    }
}

/**
 * Assert that a model exists in database with given attributes.
 */
function assertModelExists(string $modelClass, array $attributes): void
{
    expect($modelClass::where($attributes)->exists())->toBeTrue();
}

/**
 * Assert that a model does not exist in database with given attributes.
 */
function assertModelNotExists(string $modelClass, array $attributes): void
{
    expect($modelClass::where($attributes)->exists())->toBeFalse();
}

/**
 * Invoke Unique rule query callbacks that scope non-trashed rows (deleted_at null).
 */
function expect_unique_rules_apply_deleted_at_scope(iterable $rules): void
{
    foreach ($rules as $rule) {
        if (! $rule instanceof Illuminate\Validation\Rules\Unique) {
            continue;
        }

        foreach ($rule->queryCallbacks() as $callback) {
            $query = Mockery::mock(Illuminate\Database\Query\Builder::class);
            $query->shouldReceive('where')->once()->with('deleted_at', null);
            $callback($query);
        }
    }
}
