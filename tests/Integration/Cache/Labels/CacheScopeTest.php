<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheScope;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Cache\GroupedCacheScopeUser;

function cacheScopeRecord(int $id): Role
{
    $role = new Role();
    $role->id = $id;

    return $role;
}

it('gives the keys of the user roles', function (): void {
    $role = Role::factory()->create(['guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect(CacheScope::roleKeys($user))->toBe([(string) $role->getKey()]);
});

it('adds the keys of a loaded group relation after the roles, unsorted', function (string $relation): void {
    $user = new GroupedCacheScopeUser();
    $user->id = 776;
    $user->setRelation($relation, collect([cacheScopeRecord(10), cacheScopeRecord(5)]));

    expect(CacheScope::roleKeys($user))->toBe(['10', '5']);
})->with(['groups', 'user_groups', 'user_roles']);

it('ignores a group relation that is not loaded', function (): void {
    $user = new GroupedCacheScopeUser();
    $user->id = 777;

    expect(CacheScope::roleKeys($user))->toBe([]);
});
