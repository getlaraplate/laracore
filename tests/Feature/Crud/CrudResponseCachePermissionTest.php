<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Repository;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\AclResolverService;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Cache\FlushCountingArrayStore;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Modules\Core\Tests\Stubs\Cache\ThrowingLabelIndex;

/*
 * A permission change flushes the scope labels of the responses computed for the user or the role it touched, and an
 * ACL change flushes the cached ACL resolutions with the responses it narrows. None of them empties the whole cache.
 */

beforeEach(function (): void {
    $this->cache_store = new FlushCountingArrayStore();
    Cache::store()->setStore($this->cache_store);
});

afterEach(function (): void {
    expect($this->cache_store->flushes)->toBe(0);
});

function crudPermissionCacheRecord(): RecordingLabelIndex
{
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    return $index;
}

function crudPermissionCachePermission(): Permission
{
    return Permission::create([
        'name' => 'default.perm_labels_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);
}

function crudPermissionCacheRole(): Role
{
    return Role::factory()->create(['name' => 'perm_labels_' . uniqid(), 'guard_name' => 'web']);
}

function crudPermissionCacheAcl(Permission $permission, ?Role $role, string $status = 'published'): ACL
{
    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $role?->id,
        'filters' => new FiltersGroup([new Filter('status', $status, FilterOperator::Equals)]),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    return $acl;
}

it('flushes the role label and the ACL resolutions when a role gains or loses permissions', function (Closure $change): void {
    $role = crudPermissionCacheRole();
    $held = crudPermissionCachePermission();
    $other = crudPermissionCachePermission();
    $role->givePermissionTo($held);
    $index = crudPermissionCacheRecord();

    $change($role, $held, $other);

    expect($index->flushedLabels())->toContain(CacheLabel::role($role->id), CacheLabel::acl());
})->with([
    'givePermissionTo' => [static fn (Role $role, Permission $held, Permission $other): Role => $role->givePermissionTo($other)],
    'revokePermissionTo' => [static fn (Role $role, Permission $held, Permission $other): Role => $role->revokePermissionTo($held)],
    'syncPermissions' => [static fn (Role $role, Permission $held, Permission $other): Role => $role->syncPermissions([$other])],
]);

it('flushes the role labels when a permission is given to roles from its own side', function (): void {
    $role = crudPermissionCacheRole();
    $permission = crudPermissionCachePermission();
    $index = crudPermissionCacheRecord();

    $permission->assignRole($role);

    expect($index->flushedLabels())->toContain(CacheLabel::role($role->id), CacheLabel::acl());
});

it('flushes the user label when a user gains or loses direct permissions', function (Closure $change): void {
    $user = User::factory()->create();
    $held = crudPermissionCachePermission();
    $other = crudPermissionCachePermission();
    $user->givePermissionTo($held);
    $index = crudPermissionCacheRecord();

    $change($user, $held, $other);

    expect($index->flushedLabels())->toContain(CacheLabel::user($user->id));
})->with([
    'givePermissionTo' => [static fn (User $user, Permission $held, Permission $other): User => $user->givePermissionTo($other)],
    'revokePermissionTo' => [static fn (User $user, Permission $held, Permission $other): User => $user->revokePermissionTo($held)],
    'syncPermissions' => [static fn (User $user, Permission $held, Permission $other): User => $user->syncPermissions([$other])],
]);

it('flushes the user label when the roles of a user change', function (Closure $change): void {
    $user = User::factory()->create();
    $held = crudPermissionCacheRole();
    $other = crudPermissionCacheRole();
    $user->assignRole($held);
    $index = crudPermissionCacheRecord();

    $change($user, $held, $other);

    expect($index->flushedLabels())->toContain(CacheLabel::user($user->id));
})->with([
    'assignRole' => [static fn (User $user, Role $held, Role $other): User => $user->assignRole($other)],
    'removeRole' => [static fn (User $user, Role $held, Role $other): User => $user->removeRole($held)],
    'syncRoles' => [static fn (User $user, Role $held, Role $other): User => $user->syncRoles([$other])],
]);

it('flushes the role label and the ACL resolutions on a write of a role scoped ACL', function (Closure $write): void {
    $role = crudPermissionCacheRole();
    $permission = crudPermissionCachePermission();
    $acl = crudPermissionCacheAcl($permission, $role);
    $index = crudPermissionCacheRecord();

    $write($acl);

    expect($index->flushedLabels())->toContain(CacheLabel::role($role->id), CacheLabel::acl());
})->with([
    'created' => [static fn (ACL $acl): ACL => crudPermissionCacheAcl($acl->permission, $acl->role, 'draft')],
    'updated' => [static fn (ACL $acl): bool => $acl->forceFill(['priority' => 20])->save()],
    'deleted' => [static fn (ACL $acl): ?bool => $acl->delete()],
]);

it('flushes the table label CRUD responses of the permission entity carry on a write of an unscoped ACL', function (Closure $write): void {
    $permission = Permission::findOrCreate(PermissionName::forModel(new Setting(), 'select'), 'web');
    $acl = crudPermissionCacheAcl($permission, null);
    $index = crudPermissionCacheRecord();

    $write($acl);

    expect($index->flushedLabels())->toContain(CacheLabel::table((new Setting())->getTable()), CacheLabel::acl());
})->with([
    'created' => [static fn (ACL $acl): ACL => crudPermissionCacheAcl($acl->permission, null, 'draft')],
    'updated' => [static fn (ACL $acl): bool => $acl->forceFill(['priority' => 20])->save()],
    'deleted' => [static fn (ACL $acl): ?bool => $acl->delete()],
]);

it('flushes the roles below the role of an ACL, which inherit it', function (): void {
    $parent = crudPermissionCacheRole();
    $child = Role::factory()->create(['name' => 'perm_labels_child_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $parent->id]);
    $grandchild = Role::factory()->create(['name' => 'perm_labels_grandchild_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $child->id]);
    $acl = crudPermissionCacheAcl(crudPermissionCachePermission(), $parent);
    $index = crudPermissionCacheRecord();

    $acl->forceFill(['priority' => 20])->save();

    expect($index->flushedLabels())->toContain(
        CacheLabel::role($parent->id),
        CacheLabel::role($child->id),
        CacheLabel::role($grandchild->id),
        CacheLabel::acl(),
    );
});

it('flushes the roles below a role that gains a permission, which inherit it', function (): void {
    $parent = crudPermissionCacheRole();
    $child = Role::factory()->create(['name' => 'perm_labels_child_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $parent->id]);
    $index = crudPermissionCacheRecord();

    $parent->permissions()->attach(crudPermissionCachePermission()->id);

    expect($index->flushedLabels())->toContain(CacheLabel::role($parent->id), CacheLabel::role($child->id), CacheLabel::acl());
});

it('flushes the scope labels of a grant made through the plain relation', function (Closure $change, Closure $expected): void {
    $user = User::factory()->create();
    $role = crudPermissionCacheRole();
    $permission = crudPermissionCachePermission();
    $index = crudPermissionCacheRecord();

    $change($user, $role, $permission);

    expect($index->flushedLabels())->toContain(...$expected($user, $role));
})->with([
    'attach roles of a user' => [
        static fn (User $user, Role $role): mixed => $user->roles()->attach($role->id),
        static fn (User $user): array => [CacheLabel::user($user->id)],
    ],
    'sync roles of a user' => [
        static fn (User $user, Role $role): array => $user->roles()->sync([$role->id]),
        static fn (User $user): array => [CacheLabel::user($user->id)],
    ],
    'detach roles of a user' => [
        static fn (User $user, Role $role): int => $user->roles()->detach($role->id),
        static fn (User $user): array => [CacheLabel::user($user->id)],
    ],
    'sync permissions of a user' => [
        static fn (User $user, Role $role, Permission $permission): array => $user->permissions()->sync([$permission->id]),
        static fn (User $user): array => [CacheLabel::user($user->id)],
    ],
    'attach permissions of a role' => [
        static fn (User $user, Role $role, Permission $permission): mixed => $role->permissions()->attach($permission->id),
        static fn (User $user, Role $role): array => [CacheLabel::role($role->id), CacheLabel::acl()],
    ],
    'sync permissions of a role' => [
        static fn (User $user, Role $role, Permission $permission): array => $role->permissions()->sync([$permission->id]),
        static fn (User $user, Role $role): array => [CacheLabel::role($role->id), CacheLabel::acl()],
    ],
    'detach permissions of a role' => [
        static fn (User $user, Role $role, Permission $permission): int => $role->permissions()->detach($permission->id),
        static fn (User $user, Role $role): array => [CacheLabel::role($role->id), CacheLabel::acl()],
    ],
    'attach roles of a permission' => [
        static fn (User $user, Role $role, Permission $permission): mixed => $permission->roles()->attach($role->id),
        static fn (User $user, Role $role): array => [CacheLabel::role($role->id), CacheLabel::acl()],
    ],
    'sync users of a role' => [
        static fn (User $user, Role $role): array => $role->users()->sync([$user->id]),
        static fn (User $user): array => [CacheLabel::user($user->id)],
    ],
    'detach every user of a role' => [
        static fn (User $user, Role $role): int => $role->users()->detach(),
        static fn (): array => [CacheLabel::acl()],
    ],
]);

it('flushes a role, the roles below it and the ACL resolutions when the role moves or leaves the hierarchy', function (Closure $change): void {
    $root = crudPermissionCacheRole();
    $other_root = crudPermissionCacheRole();
    $role = Role::factory()->create(['name' => 'perm_labels_moved_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $root->id]);
    $below = Role::factory()->create(['name' => 'perm_labels_below_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $role->id]);
    $index = crudPermissionCacheRecord();

    $change($role, $other_root, $index);

    expect($index->flushedLabels())->toContain(CacheLabel::acl(), CacheLabel::role($role->id), CacheLabel::role($below->id));
})->with([
    'parent changed' => [static fn (Role $role, Role $other_root): bool => $role->forceFill(['parent_id' => $other_root->id])->save()],
    'moved to the bin' => [static fn (Role $role): ?bool => $role->delete()],
    'restored' => [static function (Role $role, Role $other_root, RecordingLabelIndex $index): void {
        $role->delete();
        $index->flushes = [];
        $role->restore();
    }],
    'force deleted' => [static fn (Role $role): ?bool => $role->forceDelete()],
]);

it('resolves the ACLs of a role again once it is moved under a role with an ACL', function (): void {
    $permission = crudPermissionCachePermission();
    $parent = crudPermissionCacheRole();
    $parent->givePermissionTo($permission);
    crudPermissionCacheAcl($permission, $parent);
    $role = crudPermissionCacheRole();
    $user = User::factory()->create();
    $user->assignRole($role);
    $service = new AclResolverService();

    expect($service->getEffectiveAcls($user, $permission))->toBeEmpty();

    $role->forceFill(['parent_id' => $parent->id])->save();

    expect($service->getEffectiveAcls($user->fresh(), $permission))->toHaveCount(1);
});

it('resolves the ACLs of a role again once the role above it is moved to the bin', function (): void {
    $permission = crudPermissionCachePermission();
    $parent = crudPermissionCacheRole();
    $parent->givePermissionTo($permission);
    crudPermissionCacheAcl($permission, $parent);
    $role = Role::factory()->create(['name' => 'perm_labels_child_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $parent->id]);
    $user = User::factory()->create();
    $user->assignRole($role);
    $service = new AclResolverService();

    expect($service->getEffectiveAcls($user, $permission))->toHaveCount(1);

    $parent->delete();

    expect($service->getEffectiveAcls($user->fresh(), $permission))->toBeEmpty();
});

it('flushes every cached CRUD response on a write of an unscoped ACL', function (Closure $write): void {
    $permission = Permission::findOrCreate(PermissionName::forModel(new Setting(), 'select'), 'web');
    $acl = crudPermissionCacheAcl($permission, null);
    $index = crudPermissionCacheRecord();

    $write($acl);

    expect($index->flushedLabels())->toContain(CacheLabel::all(), CacheLabel::acl());
})->with([
    'created' => [static fn (ACL $acl): ACL => crudPermissionCacheAcl($acl->permission, null, 'draft')],
    'updated' => [static fn (ACL $acl): bool => $acl->forceFill(['priority' => 20])->save()],
    'deleted' => [static fn (ACL $acl): ?bool => $acl->delete()],
    'moved to a role' => [static fn (ACL $acl): bool => $acl->forceFill(['role_id' => crudPermissionCacheRole()->id])->save()],
]);

it('keeps the CRUD responses of other roles on a write of a role scoped ACL', function (): void {
    $acl = crudPermissionCacheAcl(crudPermissionCachePermission(), crudPermissionCacheRole());
    $index = crudPermissionCacheRecord();

    $acl->forceFill(['priority' => 20])->save();

    expect($index->flushedLabels())->not->toContain(CacheLabel::all());
});

it('flushes the role an ACL is moved away from as well as the one it moves to', function (): void {
    $from = crudPermissionCacheRole();
    $to = crudPermissionCacheRole();
    $acl = crudPermissionCacheAcl(crudPermissionCachePermission(), $from);
    $index = crudPermissionCacheRecord();

    $acl->forceFill(['role_id' => $to->id])->save();

    expect($index->flushedLabels())->toContain(CacheLabel::role($from->id), CacheLabel::role($to->id), CacheLabel::acl());
});

it('stores an ACL resolution through the label index under the acl and user labels', function (): void {
    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $user = User::factory()->create();
    $permission = crudPermissionCachePermission();
    $service = new AclResolverService();

    $first = $service->getEffectiveAcls($user, $permission);
    $second = $service->getEffectiveAcls($user, $permission);

    expect($index->stores)->toHaveCount(1)
        ->and($index->stores[0]['stored'])->toBeTrue()
        ->and($index->stores[0]['ttl'])->toBe(3600)
        ->and($index->stores[0]['lookup']->dependencies->labels)->toContain(CacheLabel::acl(), CacheLabel::user($user->id))
        ->and($index->lookups)->toHaveCount(2)
        ->and($index->lookups[1]->hit)->toBeTrue()
        ->and($second)->toEqual($first);
});

it('resolves the ACLs again after the user is given another role', function (): void {
    $permission = crudPermissionCachePermission();
    $published = crudPermissionCacheRole();
    $published->givePermissionTo($permission);
    crudPermissionCacheAcl($permission, $published, 'published');
    $draft = crudPermissionCacheRole();
    $draft->givePermissionTo($permission);
    crudPermissionCacheAcl($permission, $draft, 'draft');
    $user = User::factory()->create();
    $user->assignRole($published);
    $service = new AclResolverService();

    expect($service->getEffectiveAcls($user, $permission))->toHaveCount(1);

    $user->assignRole($draft);

    expect($service->getEffectiveAcls($user->fresh(), $permission))->toHaveCount(2);
});

it('resolves the ACLs again after a role of the user is given the permission', function (): void {
    $permission = crudPermissionCachePermission();
    $role = crudPermissionCacheRole();
    crudPermissionCacheAcl($permission, $role);
    $user = User::factory()->create();
    $user->assignRole($role);
    $service = new AclResolverService();

    expect($service->getEffectiveAcls($user, $permission))->toBeEmpty();

    $role->givePermissionTo($permission);

    expect($service->getEffectiveAcls($user->fresh(), $permission))->toHaveCount(1);
});

it('computes every ACL resolution and stores none while the failover index is degraded', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    app()->instance(ICacheLabelIndex::class, new FailoverLabelIndex($primary, new Repository(new ArrayStore())));
    $permission = crudPermissionCachePermission();
    $role = crudPermissionCacheRole();
    $role->givePermissionTo($permission);
    $acl = crudPermissionCacheAcl($permission, $role);
    $user = User::factory()->create();
    $user->assignRole($role);
    $service = new AclResolverService();

    $first = $service->getEffectiveAcls($user, $permission);
    $second = $service->getEffectiveAcls($user, $permission);

    expect($first)->toBeInstanceOf(Collection::class)
        ->and($first->pluck('id')->all())->toBe([$acl->id])
        ->and($second->pluck('id')->all())->toBe([$acl->id])
        ->and($primary->calls)->not->toContain('store');
});

it('flushes the acl label when the ACL resolutions are flushed', function (): void {
    $index = crudPermissionCacheRecord();

    (new AclResolverService())->flushCache();

    expect($index->flushes)->toBe([[CacheLabel::acl()]]);
});

it('flushes the acl label when a permission reaches more users than the clear threshold', function (): void {
    // Below zero, so that a permission reaching nobody is already over it.
    config()->set('core.acl.clear_threshold', -1);
    $permission = crudPermissionCachePermission();
    $index = crudPermissionCacheRecord();

    (new AclResolverService())->clearCacheForPermission($permission);

    expect($index->flushes)->toBe([[CacheLabel::acl()]]);
});
