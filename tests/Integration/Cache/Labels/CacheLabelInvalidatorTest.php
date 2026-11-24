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

it('waits for the commit, and flushes after a rollback too', function (): void {
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

    // A read inside the rolled back transaction may have cached the rows it removed.
    expect($this->index->flushes)->toHaveCount(1)
        ->and($this->index->flushedLabels())->toContain(CacheLabel::row($role->getTable(), $role->getKey()));
});

it('flushes a value cached from uncommitted rows when the transaction rolls back', function (): void {
    $index = new Modules\Core\Cache\Labels\ArrayLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $role = makeLabelRole();
    $dependencies = new Modules\Core\Cache\Labels\CacheDependencies([CacheLabel::table($role->getTable())]);

    try {
        $role->getConnection()->transaction(function () use ($index, $role, $dependencies): void {
            $role->update(['name' => 'uncommitted_' . uniqid()]);
            $index->store($index->lookup('roles', $dependencies), 'built from uncommitted rows', 60);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($index->lookup('roles', $dependencies)->hit)->toBeFalse();
});

it('flushes a rolled back transaction and a committed one that follows it, each at its end', function (): void {
    [$rolled_back, $committed] = [makeLabelRole(), makeLabelRole()];
    $this->index->flushes = [];
    $connection = $committed->getConnection();

    try {
        $connection->transaction(function () use ($rolled_back): void {
            $rolled_back->update(['name' => 'rolled_back_' . uniqid()]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    $connection->transaction(static fn () => $committed->update(['name' => 'committed_' . uniqid()]));

    expect($this->index->flushes)->toHaveCount(2)
        ->and($this->index->flushes[0])->toContain(CacheLabel::row($rolled_back->getTable(), $rolled_back->getKey()))
        ->and($this->index->flushes[1])->toContain(CacheLabel::row($committed->getTable(), $committed->getKey()));
});

it('waits for the commit of its own connection when Laravel runs its callback at the commit of another one', function (): void {
    config(['database.connections.labels_second' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $second = Illuminate\Support\Facades\DB::connection('labels_second');
    [$before, $inside] = [makeLabelRole(), makeLabelRole()];
    $this->index->flushes = [];
    $connection = $before->getConnection();

    $connection->transaction(function () use ($second, $before, $inside): void {
        $before->update(['name' => 'before_' . uniqid()]);

        // The innermost open transaction is the other connection's: Laravel attaches this write's callback to it.
        $second->transaction(static fn () => $inside->update(['name' => 'inside_' . uniqid()]));

        expect($this->index->flushes)->toBe([]);
    });

    expect($this->index->flushes)->toHaveCount(1)
        ->and($this->index->flushedLabels())->toContain(
            CacheLabel::row($before->getTable(), $before->getKey()),
            CacheLabel::row($inside->getTable(), $inside->getKey()),
        );
});

it('keeps the labels of a write on the outer connection when the inner connection rolls back in full', function (): void {
    // Two connections of their own: on the RefreshDatabase connection the inner rollback would be a savepoint rollback.
    config([
        'database.connections.labels_outer' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.labels_inner' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
    $outer = Illuminate\Support\Facades\DB::connection('labels_outer');
    $inner = Illuminate\Support\Facades\DB::connection('labels_inner');
    $owner = new Role();
    $owner->setConnection('labels_outer');
    $label = CacheLabel::table('outer_audit_rows');

    $outer->transaction(function () use ($inner, $owner, $label): void {
        try {
            // A write on the outer connection while the inner one's transaction is innermost.
            $inner->transaction(static function () use ($owner, $label): void {
                resolve(CacheLabelInvalidator::class)->flushLabels($owner, $label);

                throw new RuntimeException('rollback the inner connection');
            });
        } catch (RuntimeException) {
        }

        expect($this->index->flushedLabels())->not->toContain($label);
    });

    expect($this->index->flushedLabels())->toContain($label);
});

it('flushes at once, through the index, a write with no transaction open', function (): void {
    config(['database.connections.labels_untransacted' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $owner = new Role();
    $owner->setConnection('labels_untransacted');

    expect($owner->getConnection()->transactionLevel())->toBe(0);

    resolve(CacheLabelInvalidator::class)->flushLabels($owner, CacheLabel::acl(), CacheLabel::acl(), CacheLabel::user(5));
    resolve(CacheLabelInvalidator::class)->flushLabels(null, CacheLabel::docs());

    expect($this->index->flushes)->toBe([[CacheLabel::acl(), CacheLabel::user(5)], [CacheLabel::docs()]]);
});

it('collects the labels of a new request or job apart from those its previous scope left', function (): void {
    $role = makeLabelRole();
    $permission = Permission::query()->create(['name' => 'label.perm.' . uniqid(), 'guard_name' => 'web']);
    $this->index->flushes = [];
    $connection = $role->getConnection();
    $first = resolve(Modules\Core\Cache\Labels\PendingLabelFlushes::class);

    expect(resolve(Modules\Core\Cache\Labels\PendingLabelFlushes::class))->toBe($first);

    $connection->beginTransaction();
    $role->update(['name' => 'left_by_the_scope_' . uniqid()]);

    app()->forgetScopedInstances();

    expect(resolve(Modules\Core\Cache\Labels\PendingLabelFlushes::class))->not->toBe($first);

    $connection->transaction(static function () use ($permission): void {
        $permission->update(['name' => 'label.scope.' . uniqid()]);
    });
    $connection->commit();

    $role_labels = [CacheLabel::table($role->getTable()), CacheLabel::row($role->getTable(), $role->getKey())];
    $permission_row = CacheLabel::row($permission->getTable(), $permission->getKey());
    $new_scope = array_values(array_filter($this->index->flushes, static fn (array $labels): bool => in_array($permission_row, $labels, true)));

    expect($new_scope)->toHaveCount(1)
        ->and(array_intersect($new_scope[0], $role_labels))->toBe([])
        ->and($this->index->flushedLabels())->toContain(...$role_labels);
});

it('flushes the labels of a whole transaction once after the commit, deduplicated', function (): void {
    $roles = [makeLabelRole(), makeLabelRole(), makeLabelRole()];
    $this->index->flushes = [];
    $connection = $roles[0]->getConnection();

    $connection->transaction(function () use ($roles): void {
        foreach ($roles as $role) {
            $role->update(['name' => 'batched_' . uniqid()]);
            $role->update(['name' => 'batched_again_' . uniqid()]);
        }

        expect($this->index->flushes)->toBe([]);
    });

    $table = $roles[0]->getTable();
    $expected = [CacheLabel::table($table), ...array_map(static fn (Role $role): string => CacheLabel::row($table, $role->getKey()), $roles)];

    expect($this->index->flushes)->toHaveCount(1)
        ->and($this->index->flushes[0])->toEqualCanonicalizing($expected);
});

it('still flushes the writes of a transaction when a savepoint inside it rolls back', function (): void {
    [$in_savepoint, $outside] = [makeLabelRole(), makeLabelRole()];
    $this->index->flushes = [];
    $connection = $outside->getConnection();

    $connection->transaction(function () use ($connection, $in_savepoint, $outside): void {
        // The first write of the transaction registers its callback inside the savepoint that is rolled back.
        try {
            $connection->transaction(function () use ($in_savepoint): void {
                $in_savepoint->update(['name' => 'savepoint_' . uniqid()]);

                throw new RuntimeException('rollback the savepoint');
            });
        } catch (RuntimeException) {
        }

        $outside->update(['name' => 'outside_' . uniqid()]);
    });

    expect($this->index->flushes)->toHaveCount(1)
        ->and($this->index->flushedLabels())->toContain(CacheLabel::row($outside->getTable(), $outside->getKey()));
});

it('flushes the writes of a committed savepoint with the transaction', function (): void {
    [$in_savepoint, $outside] = [makeLabelRole(), makeLabelRole()];
    $this->index->flushes = [];
    $connection = $outside->getConnection();

    $connection->transaction(function () use ($connection, $in_savepoint, $outside): void {
        $connection->transaction(static fn () => $in_savepoint->update(['name' => 'savepoint_' . uniqid()]));
        $outside->update(['name' => 'outside_' . uniqid()]);

        expect($this->index->flushes)->toBe([]);
    });

    expect($this->index->flushes)->toHaveCount(1)
        ->and($this->index->flushedLabels())->toContain(
            CacheLabel::row($in_savepoint->getTable(), $in_savepoint->getKey()),
            CacheLabel::row($outside->getTable(), $outside->getKey()),
        );
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
