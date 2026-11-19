<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheLabelIndexFactory;
use Modules\Core\Cache\Repository;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;

/*
 * A response that embeds a record through a belongsTo carries the label of that row, not of its whole table: writes to
 * other rows of the table leave it cached, writes to the embedded row (update, delete, soft delete, restore) drop it.
 * A counter index (file store) has no row labels and over-invalidates by design.
 *
 * Setup: the cached list is the ACL list with the role relation. The caller is a superadmin, whose own roles are not
 * touched. The ACL rows point at the embedded role; the "other" role has no ACL and no relation to the embedded role.
 */

beforeEach(function (): void {
    $this->directory = null;
});

afterEach(function (): void {
    if ($this->directory !== null) {
        (new Filesystem())->deleteDirectory($this->directory);
    }
});

function crudRowLabelBind(string $kind): SpyLabelIndex
{
    if ($kind === 'array') {
        $spy = new SpyLabelIndex(new ArrayLabelIndex());
    } else {
        $directory = storage_path('framework/testing/crud-row-label-' . uniqid());
        test()->directory = $directory;
        $spy = new SpyLabelIndex(CacheLabelIndexFactory::forStore(new Repository(new FileStore(new Filesystem(), $directory))));
    }

    app()->instance(ICacheLabelIndex::class, $spy);

    return $spy;
}

/**
 * @return array{hit: bool, queries: int}
 */
function crudRowLabelRead(SpyLabelIndex $spy, Model $model, string $url): array
{
    $count = 0;
    $recording = true;
    $connection = $model->getConnection()->getName();
    $table = $model->getConnection()->getTablePrefix() . $model->getTable();
    $crud_lookups = static fn (): array => array_values(array_filter($spy->lookups, static fn (Modules\Core\Cache\Labels\CacheLookup $lookup): bool => str_starts_with($lookup->key, 'crud:')));
    $lookups = count($crud_lookups());

    DB::listen(static function (QueryExecuted $query) use (&$count, &$recording, $connection, $table): void {
        if ($recording && $query->connectionName === $connection && str_contains($query->sql, $table)) {
            $count++;
        }
    });

    try {
        test()->getJson($url)->assertOk();
    } finally {
        $recording = false;
    }

    expect($crud_lookups())->toHaveCount($lookups + 1);

    return ['hit' => $crud_lookups()[$lookups]->hit, 'queries' => $count];
}

/**
 * @param  array{hit: bool, queries: int}  $read
 */
function crudRowLabelAssertHit(array $read): void
{
    expect($read['hit'])->toBeTrue()
        ->and($read['queries'])->toBe(0);
}

/**
 * @param  array{hit: bool, queries: int}  $read
 */
function crudRowLabelAssertMiss(array $read): void
{
    expect($read['hit'])->toBeFalse()
        ->and($read['queries'])->toBeGreaterThan(0);
}

/**
 * Binds the index, creates the embedded role with its ACL and an unrelated role, and warms the list.
 *
 * @return array{spy: SpyLabelIndex, embedded: Role, other: Role, url: string}
 */
function crudRowLabelArrange(string $kind): array
{
    $spy = crudRowLabelBind($kind);
    $embedded = Role::factory()->create(['name' => 'row_embedded_' . uniqid(), 'guard_name' => 'web']);
    $other = Role::factory()->create(['name' => 'row_other_' . uniqid(), 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'default.row_labels_' . uniqid() . '.select', 'guard_name' => 'web']);

    $acl = new ACL();
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $embedded->id,
        'filters' => new FiltersGroup([new Filter('status', 'published', FilterOperator::Equals)]),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('superadmin', 'web'));
    test()->actingAs($actor);

    $url = route('core.crud.list', ['module' => 'core', 'entity' => 'ACL']) . '?' . http_build_query(['relations' => ['role']]);

    crudRowLabelAssertMiss(crudRowLabelRead($spy, new ACL(), $url));
    crudRowLabelAssertHit(crudRowLabelRead($spy, new ACL(), $url));

    return ['spy' => $spy, 'embedded' => $embedded, 'other' => $other, 'url' => $url];
}

it('keeps the list with an embedded role cached when another role is updated', function (): void {
    ['spy' => $spy, 'other' => $other, 'url' => $url] = crudRowLabelArrange('array');

    $other->update(['description' => 'changed ' . uniqid()]);

    crudRowLabelAssertHit(crudRowLabelRead($spy, new ACL(), $url));
});

it('misses the list once the embedded role is updated', function (): void {
    ['spy' => $spy, 'embedded' => $embedded, 'url' => $url] = crudRowLabelArrange('array');

    $embedded->update(['description' => 'changed ' . uniqid()]);

    crudRowLabelAssertMiss(crudRowLabelRead($spy, new ACL(), $url));
});

it('misses the list once the embedded role is deleted, soft deleted or restored', function (Closure $write): void {
    ['spy' => $spy, 'embedded' => $embedded, 'url' => $url] = crudRowLabelArrange('array');

    $write($embedded);

    crudRowLabelAssertMiss(crudRowLabelRead($spy, new ACL(), $url));
})->with([
    'force delete' => [static fn (Role $role): mixed => $role->forceDelete()],
    'soft delete' => [static fn (Role $role): mixed => $role->delete()],
    'restore' => [static function (Role $role): void {
        $role->delete();
        // Warm the list again with the role in the bin, then bring the role back.
        $url = route('core.crud.list', ['module' => 'core', 'entity' => 'ACL']) . '?' . http_build_query(['relations' => ['role']]);
        $spy = app(ICacheLabelIndex::class);
        crudRowLabelRead($spy, new ACL(), $url);
        crudRowLabelAssertHit(crudRowLabelRead($spy, new ACL(), $url));
        $role->restore();
    }],
]);

it('misses the list when another role is updated on a counter index, which has no row labels', function (): void {
    ['spy' => $spy, 'other' => $other, 'url' => $url] = crudRowLabelArrange('file');

    $other->update(['description' => 'changed ' . uniqid()]);

    crudRowLabelAssertMiss(crudRowLabelRead($spy, new ACL(), $url));
});
