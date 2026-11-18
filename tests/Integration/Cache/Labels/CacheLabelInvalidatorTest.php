<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;

beforeEach(function (): void {
    $this->index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $this->index);
});

function makeLabelRole(): Role
{
    return Role::query()->create(['name' => 'label_role_' . uniqid(), 'guard_name' => 'web']);
}

it('flushes the table label when a model is created', function (): void {
    $role = makeLabelRole();

    expect($this->index->flushedLabels())->toBe([CacheLabel::table($role->getTable())]);
});

it('flushes table and row labels on update, delete', function (): void {
    $role = makeLabelRole();
    $this->index->flushes = [];

    $role->update(['name' => 'renamed_' . uniqid()]);

    $expected = [CacheLabel::table($role->getTable()), CacheLabel::row($role->getTable(), $role->getKey())];
    expect($this->index->flushedLabels())->toBe($expected);

    $this->index->flushes = [];
    $role->delete();

    // A role leaving the hierarchy flushes its scope labels too, see RoleObserver.
    expect($this->index->flushedLabels())->toContain(...$expected);
});

it('flushes table and row labels on restore and force delete', function (): void {
    $setting = Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'lbl_' . uniqid(),
        'value' => 'v',
        'type' => Modules\Core\Casts\SettingTypeEnum::String,
        'group_name' => 'g',
        'description' => 'd',
    ]);

    foreach (['restored', 'forceDeleted'] as $event) {
        $this->index->flushes = [];
        event("eloquent.{$event}: " . $setting::class, $setting);

        expect($this->index->flushedLabels())->toBe([
            CacheLabel::table($setting->getTable()),
            CacheLabel::row($setting->getTable(), $setting->getKey()),
        ]);
    }
});

it('waits for the commit and drops the flush on rollback', function (): void {
    $role = makeLabelRole();
    $this->index->flushes = [];
    $connection = $role->getConnection();

    $connection->transaction(function () use ($role): void {
        $role->update(['name' => 'in_tx_' . uniqid()]);
        expect($this->index->flushes)->toBe([]);
    });

    expect($this->index->flushedLabels())->toContain(CacheLabel::row($role->getTable(), $role->getKey()));

    $this->index->flushes = [];

    try {
        $connection->transaction(function () use ($role): void {
            $role->update(['name' => 'rolled_back_' . uniqid()]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($this->index->flushes)->toBe([]);
});

it('flushes pivot, parent and related tables on every pivot mutation', function (): void {
    $role = makeLabelRole();
    $permission = Permission::query()->create(['name' => 'label.perm.' . uniqid(), 'guard_name' => 'web']);
    $relation = $role->permissions();
    $expected = [
        CacheLabel::table($relation->getTable()),
        CacheLabel::table($role->getTable()),
        CacheLabel::table($permission->getTable()),
    ];

    $mutations = [
        'attach' => fn () => $role->permissions()->attach($permission->getKey()),
        'updateExistingPivot' => fn () => $role->permissions()->updateExistingPivot($permission->getKey(), ['permission_id' => $permission->getKey()]),
        'toggle' => fn () => $role->permissions()->toggle([$permission->getKey()]),
        'sync' => fn () => $role->permissions()->sync([$permission->getKey()]),
        'detach' => fn () => $role->permissions()->detach($permission->getKey()),
    ];

    foreach ($mutations as $name => $mutation) {
        $this->index->flushes = [];
        $mutation();

        expect($this->index->flushedLabels())->toContain(...$expected);
    }
});

it('flushes the table label and every row of the model table on invalidateCache', function (): void {
    $role = makeLabelRole();
    $this->index->flushes = [];

    $role->invalidateCache();

    expect($this->index->flushes)->toBe([[CacheLabel::table($role->getTable()), CacheLabel::rows($role->getTable())]]);
});

it('flushes several whole tables in one flush call', function (): void {
    app(CacheLabelInvalidator::class)->flushTables(['cms_contents', 'cms_tags', 'cms_contents']);

    expect($this->index->flushes)->toBe([[
        CacheLabel::table('cms_contents'),
        CacheLabel::rows('cms_contents'),
        CacheLabel::table('cms_tags'),
        CacheLabel::rows('cms_tags'),
    ]]);
});

it('flushes a pivot grant once for a sync that attaches and detaches several keys', function (): void {
    $role = makeLabelRole();
    $held = Permission::query()->create(['name' => 'label.held.' . uniqid(), 'guard_name' => 'web']);
    $role->permissions()->attach($held->getKey());
    $added = [
        Permission::query()->create(['name' => 'label.add1.' . uniqid(), 'guard_name' => 'web'])->getKey(),
        Permission::query()->create(['name' => 'label.add2.' . uniqid(), 'guard_name' => 'web'])->getKey(),
    ];
    $this->index->flushes = [];

    $role->permissions()->sync($added);

    expect($this->index->flushes)->toHaveCount(1)
        ->and($this->index->flushes[0])->toContain(CacheLabel::role($role->getKey()), CacheLabel::acl());
});

it('flushes a pivot grant once for a toggle that attaches and detaches', function (): void {
    $role = makeLabelRole();
    $held = Permission::query()->create(['name' => 'label.held.' . uniqid(), 'guard_name' => 'web']);
    $other = Permission::query()->create(['name' => 'label.other.' . uniqid(), 'guard_name' => 'web']);
    $role->permissions()->attach($held->getKey());
    $this->index->flushes = [];

    $role->permissions()->toggle([$held->getKey(), $other->getKey()]);

    expect($this->index->flushes)->toHaveCount(1);
});

it('finds the roles below a role through a role in the bin', function (): void {
    $parent = makeLabelRole();
    $child = Role::factory()->create(['name' => 'label_child_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $parent->getKey()]);
    $grandchild = Role::factory()->create(['name' => 'label_grandchild_' . uniqid(), 'guard_name' => 'web', 'parent_id' => $child->getKey()]);
    $child->delete();
    $this->index->flushes = [];

    app(CacheLabelInvalidator::class)->flushRoles(null, [$parent->getKey()]);

    expect($this->index->flushedLabels())->toContain(
        CacheLabel::acl(),
        CacheLabel::role($parent->getKey()),
        CacheLabel::role($child->getKey()),
        CacheLabel::role($grandchild->getKey()),
    );
});

it('flushes pivot labels through the morph relation of a user', function (): void {
    $user = Modules\Core\Models\User::factory()->create();
    $role = makeLabelRole();
    $relation = $user->roles();
    $this->index->flushes = [];

    $relation->attach($role->getKey());

    expect($relation)->toBeInstanceOf(Modules\Core\Overrides\Relations\MorphToMany::class)
        ->and($this->index->flushedLabels())->toContain(
            CacheLabel::table($relation->getTable()),
            CacheLabel::table($user->getTable()),
            CacheLabel::table($role->getTable()),
        );
});
