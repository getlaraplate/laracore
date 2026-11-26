<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;

uses()->group('db2');

/**
 * Roles inherit from one another, and `Role::hasPermission()` and `Role::getAllPermissions()` both
 * walk that chain. The user-level check did not: Spatie's `hasPermissionTo` asks whether the user
 * holds one of the roles attached to the permission, so a permission granted to a parent role was
 * invisible to the gate even though the child role reported holding it. Inheritance stopped one
 * step short of the person it was supposed to reach.
 */
function inheritedPermissionUser(string $permission_name): User
{
    $permission = Permission::findOrCreate($permission_name, 'web');

    $ancestor = Role::findOrCreate('inherit_ancestor_' . uniqid(), 'web');
    $ancestor->givePermissionTo($permission);

    $child = Role::findOrCreate('inherit_child_' . uniqid(), 'web');
    $child->parent_id = $ancestor->id;
    $child->save();

    $user = User::factory()->create();
    $user->assignRole($child);

    return $user->refresh();
}

it('grants a permission a role inherits from its parent', function (): void {
    $user = inheritedPermissionUser('default.users.select');

    expect($user->hasPermission('default.users.select'))->toBeTrue()
        // The check the gate used before, kept here to record exactly what was missing.
        ->and($user->hasPermissionTo('default.users.select', 'web'))->toBeFalse();
});

it('lets the entity gate through on an inherited permission', function (): void {
    $user = inheritedPermissionUser('default.users.select');

    $this->actingAs($user);

    // The gate reads the user off the request, so the resolver has to be set as the middleware
    // would set it.
    $request = Request::create('/app/crud/list/core/users', 'GET');
    $request->setUserResolver(fn (): User => $user);

    $allowed = app(AuthorizationService::class)->checkPermission($request, 'users', 'select');

    expect($allowed)->toBeTrue();
});

it('still refuses a permission nobody in the chain holds', function (): void {
    $user = inheritedPermissionUser('default.users.select');

    Permission::findOrCreate('default.users.forceDelete', 'web');

    expect($user->hasPermission('default.users.forceDelete'))->toBeFalse();
});
