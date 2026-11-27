<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Fixtures\CrudSyncRelChild;
use Modules\Core\Tests\Fixtures\CrudSyncRelParent;
use Modules\Core\Versioning\Exceptions\MembershipConflictException;

/*
 * A form that shows relations sends, with the new membership of a relation, the membership it loaded: the update is
 * refused, writing nothing, when the related records the writer can read are no longer those (D40). Per relation:
 * a change to another relation does not block it.
 */

/**
 * Logs in a writer who may update the parent and select the children, narrowed to the given ids when a list is given.
 *
 * @param  list<int>|null  $visible_child_ids
 */
function sync_conflict_writer(?array $visible_child_ids): User
{
    $role = Role::factory()->create(['name' => 'sync_conflict_' . uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => PermissionName::forClass(CrudSyncRelParent::class, 'update'), 'guard_name' => 'web']));
    $select = Permission::query()->firstOrCreate(['name' => PermissionName::forClass(CrudSyncRelChild::class, 'select'), 'guard_name' => 'web']);
    $role->givePermissionTo($select);

    if ($visible_child_ids !== null) {
        $acl = new ACL;
        $acl->setSkipValidation(true);
        $acl->forceFill([
            'permission_id' => $select->id,
            'role_id' => $role->id,
            'filters' => new FiltersGroup([new Filter('id', $visible_child_ids, FilterOperator::In)], WhereClause::And),
            'unrestricted' => false,
            'priority' => 10,
            'is_active' => true,
        ]);
        $acl->save();
    }

    $user = User::factory()->create(['username' => 'sync_conflict_' . uniqid(), 'email' => 'sync_conflict_' . uniqid() . '@example.com']);
    $user->assignRole($role);
    auth()->login($user->fresh());

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $changes
 * @param  array<string, list<int>>  $relations
 * @param  array<string, list<int>>  $expected
 */
function sync_conflict_data(object $model, User $user, array $changes, array $relations, array $expected): ModifyRequestData
{
    $request = Request::create('/modify', 'PATCH', ['id' => $model->getKey()]);
    $request->request->set('id', $model->getKey());
    $request->setUserResolver(static fn (): User => $user);

    $data = new ReflectionClass(ModifyRequestData::class)->newInstanceWithoutConstructor();

    foreach ([
        'request' => $request,
        'mainEntity' => $model->getTable(),
        'primaryKey' => 'id',
        'model' => $model,
        'connection' => $model->getConnectionName(),
        'changes' => $changes,
        'relations' => $relations,
        'expectedRelations' => $expected,
    ] as $property => $value) {
        new ReflectionProperty(ModifyRequestData::class, $property)->setValue($data, $value);
    }

    return $data;
}

/**
 * @return list<int>
 */
function sync_conflict_attached(CrudSyncRelParent $parent): array
{
    return $parent->children()->pluck('crud_sync_rel_child.id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
}

beforeEach(function (): void {
    Cache::flush();
    Schema::create('crud_sync_rel_parent', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('crud_sync_rel_child', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('crud_sync_rel_pivot', function (Blueprint $table): void {
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('child_id');
        $table->primary(['parent_id', 'child_id']);
    });

    $this->service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));
    $this->parent = CrudSyncRelParent::query()->create(['name' => 'root']);
    [$this->one, $this->two, $this->three] = collect(range(1, 3))->map(fn (): int => (int) CrudSyncRelChild::query()->create()->getKey())->all();
    $this->parent->children()->sync([$this->one, $this->two]);
});

afterEach(function (): void {
    Schema::dropIfExists('crud_sync_rel_pivot');
    Schema::dropIfExists('crud_sync_rel_child');
    Schema::dropIfExists('crud_sync_rel_parent');
});

it('syncs when the membership the form loaded is still the current one, in any order', function (): void {
    $writer = sync_conflict_writer(null);

    $this->service->update(sync_conflict_data($this->parent, $writer, ['name' => 'renamed'], ['children' => [$this->three]], ['children' => [$this->two, $this->one]]));

    expect(sync_conflict_attached($this->parent))->toBe([$this->three])
        ->and($this->parent->fresh()->name)->toBe('renamed');
});

it('refuses the update and writes nothing when the membership changed since the form loaded it', function (): void {
    $writer = sync_conflict_writer(null);
    // Somebody else attached a record after the form was loaded.
    $this->parent->children()->attach($this->three);

    expect(fn () => $this->service->update(sync_conflict_data($this->parent, $writer, ['name' => 'renamed'], ['children' => [$this->one]], ['children' => [$this->one, $this->two]])))
        ->toThrow(MembershipConflictException::class);

    expect(sync_conflict_attached($this->parent))->toBe(collect([$this->one, $this->two, $this->three])->sort()->values()->all())
        ->and($this->parent->fresh()->name)->toBe('root');
});

it('syncs as before a relation sent without the membership it loaded', function (): void {
    $writer = sync_conflict_writer(null);
    $this->parent->children()->attach($this->three);

    $this->service->update(sync_conflict_data($this->parent, $writer, [], ['children' => [$this->one]], []));

    expect(sync_conflict_attached($this->parent))->toBe([$this->one]);
});

it('ignores the expected membership of a relation the update does not sync', function (): void {
    $writer = sync_conflict_writer(null);
    $this->parent->children()->attach($this->three);

    $this->service->update(sync_conflict_data($this->parent, $writer, ['name' => 'renamed'], [], ['children' => [$this->one]]));

    expect($this->parent->fresh()->name)->toBe('renamed');
});

it('compares only the related records the writer can read', function (): void {
    // The writer sees one and three: two is attached but hidden, and was never on the form.
    $writer = sync_conflict_writer([$this->one, $this->three]);

    $this->service->update(sync_conflict_data($this->parent, $writer, [], ['children' => [$this->three]], ['children' => [$this->one]]));

    expect(sync_conflict_attached($this->parent))->toBe(collect([$this->two, $this->three])->sort()->values()->all());
});
