<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheLabel;
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

    expect($this->index->flushedLabels())->toBe($expected);
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
