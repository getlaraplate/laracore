<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Core\Http\Resources\UserInfoResponse;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

it('transforms null resource to anonymous array', function (): void {
    $resource = new UserInfoResponse(null);
    $array = $resource->toArray(new Request);

    expect($array['id'])->toBe('anonymous')
        ->and($array['name'])->toBe('anonymous')
        ->and($array['permissions'])->toBe([])
        ->and($array['groups'])->toBe([])
        ->and($array['lang'])->toBeNull();
});

it('transforms user resource to array with permissions and groups', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'editor', 'guard_name' => 'web']);
    $user->roles()->attach($role);

    $resource = new UserInfoResponse($user);
    $array = $resource->toArray(new Request);

    expect($array['id'])->toBe($user->id)
        ->and($array['username'])->toBe($user->username)
        ->and($array['groups'])->toContain('editor')
        ->and($array)->toHaveKey('permissions');
});

it('includes canImpersonate in array', function (): void {
    $user = User::factory()->create();
    $resource = new UserInfoResponse($user);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKey('canImpersonate');
});

it('exposes the user language preference', function (): void {
    $user = User::factory()->create(['lang' => 'it']);
    $resource = new UserInfoResponse($user);
    $array = $resource->toArray(new Request);

    expect($array)->toHaveKey('lang')
        ->and($array['lang'])->toBe('it');
});

it('transforms superadmin user with all permissions grouped by guard', function (): void {
    config(['permission.roles.superadmin' => 'superadmin']);

    $role = Role::factory()->create(['name' => 'superadmin', 'guard_name' => 'web']);
    Permission::factory()->create(['name' => 'posts.articles.edit', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->roles()->attach($role);
    $user->load('roles');

    $resource = new UserInfoResponse($user);
    $array = $resource->toArray(new Request);

    expect($array['permissions'])->toBeArray()
        ->and($array['permissions'])->not->toBeEmpty()
        ->and($array['permissions']['web'])->toContain('posts.articles.edit');
});

it('groups multiple permissions under same guard', function (): void {
    $role = Role::factory()->create(['name' => 'editor', 'guard_name' => 'web']);
    $perm1 = Permission::factory()->create(['name' => 'posts.articles.edit', 'guard_name' => 'web']);
    $perm2 = Permission::factory()->create(['name' => 'posts.articles.view', 'guard_name' => 'web']);
    $role->givePermissionTo($perm1, $perm2);

    $user = User::factory()->create();
    $user->roles()->attach($role);
    $user->load('roles', 'permissions');

    $resource = new UserInfoResponse($user);
    $array = $resource->toArray(new Request);

    $all_perms = collect($array['permissions'])->flatten()->toArray();
    expect($all_perms)->toContain('posts.articles.edit')
        ->and($all_perms)->toContain('posts.articles.view');
});

it('groups permissions under separate keys per guard for superadmin', function (): void {
    config(['permission.roles.superadmin' => 'superadmin']);

    $role = Role::factory()->create(['name' => 'superadmin', 'guard_name' => 'web']);
    Permission::factory()->create(['name' => 'default.webperm.select', 'guard_name' => 'web']);
    Permission::factory()->create(['name' => 'default.apiperm.select', 'guard_name' => 'api']);

    $user = User::factory()->create();
    $user->roles()->attach($role);
    $user->load('roles');

    $resource = new UserInfoResponse($user);
    $array = $resource->toArray(new Request);

    expect($array['permissions'])->toHaveKey('web')
        ->and($array['permissions'])->toHaveKey('api')
        ->and($array['permissions']['web'])->toContain('default.webperm.select')
        ->and($array['permissions']['api'])->toContain('default.apiperm.select');
});

it('exposes the user timezone and none for an anonymous caller', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Rome']);

    $array = (new UserInfoResponse($user))->toArray(new Request);
    $anonymous = (new UserInfoResponse(null))->toArray(new Request);

    expect($array['timezone'])->toBe('Europe/Rome')
        ->and($anonymous['timezone'])->toBeNull();
});
