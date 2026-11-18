<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelIndexFactory;
use Modules\Core\Cache\Repository;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;

/*
 * A write invalidates the cached responses that depend on what it changed, on a membership index (array) and on a
 * counter index (a file store).
 */

beforeEach(function (): void {
    $this->directory = null;
    $this->counters = null;
});

afterEach(function (): void {
    if ($this->directory !== null) {
        (new Filesystem())->deleteDirectory($this->directory);
    }
});

/**
 * Binds the index of the given kind, behind a spy.
 */
function crudInvalidationBind(string $kind): SpyLabelIndex
{
    if ($kind === 'array') {
        $spy = new SpyLabelIndex(new ArrayLabelIndex());
    } else {
        $directory = storage_path('framework/testing/crud-invalidation-' . uniqid());
        $counters = new Repository(new FileStore(new Filesystem(), $directory));
        test()->directory = $directory;
        test()->counters = $counters;
        $spy = new SpyLabelIndex(CacheLabelIndexFactory::forStore($counters));
    }

    app()->instance(ICacheLabelIndex::class, $spy);

    return $spy;
}

/**
 * Reads a url and tells whether the index answered it and how many queries named the table of the model.
 *
 * @return array{hit: bool, queries: int}
 */
function crudInvalidationRead(SpyLabelIndex $spy, Model $model, string $url): array
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
 * @param  bool  $count_queries  False where the request itself reads the table besides the cached list (the roles of the caller, the settings reloaded after a write): the hit flag then says it.
 */
function crudInvalidationAssertHit(array $read, bool $count_queries = true): void
{
    expect($read['hit'])->toBeTrue();

    if ($count_queries) {
        expect($read['queries'])->toBe(0);
    }
}

/**
 * @param  array{hit: bool, queries: int}  $read
 */
function crudInvalidationAssertMiss(array $read, bool $count_queries = true): void
{
    expect($read['hit'])->toBeFalse();

    if ($count_queries) {
        expect($read['queries'])->toBeGreaterThan(0);
    }
}

function crudInvalidationSuperadmin(): User
{
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('superadmin', 'web'));

    return $actor;
}

function crudInvalidationSettingsUrl(array $query = []): string
{
    return route('core.crud.list', ['module' => 'core', 'entity' => 'settings']) . '?' . http_build_query($query + ['sort' => [['property' => 'id', 'direction' => 'asc']]]);
}

function crudInvalidationNewSetting(): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create();
}

it('misses every cached page of a list once a row is created', function (string $kind): void {
    $spy = crudInvalidationBind($kind);
    Setting::factory()->persistedWithoutApprovalCapture()->count(3)->create();
    $this->actingAs(crudInvalidationSuperadmin());
    $page_one = crudInvalidationSettingsUrl(['page' => 1, 'pagination' => 2]);
    $page_two = crudInvalidationSettingsUrl(['page' => 2, 'pagination' => 2]);

    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Setting(), $page_one));
    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Setting(), $page_two));
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $page_one));
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $page_two));

    crudInvalidationNewSetting();

    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Setting(), $page_one));
    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Setting(), $page_two));
})->with(['array', 'file']);

it('misses the list and the detail of a row once it is updated', function (string $kind): void {
    $spy = crudInvalidationBind($kind);
    $setting = crudInvalidationNewSetting();
    $this->actingAs(crudInvalidationSuperadmin());
    $list = crudInvalidationSettingsUrl();
    $detail = route('core.crud.detail', ['module' => 'core', 'entity' => 'settings']) . '?' . http_build_query(['id' => $setting->getKey()]);

    crudInvalidationRead($spy, new Setting(), $list);
    crudInvalidationRead($spy, new Setting(), $detail);
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $list));
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $detail));

    $setting->update(['description' => 'changed ' . uniqid()]);

    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Setting(), $list));
    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Setting(), $detail));
})->with(['array', 'file']);

it('still hits after a write to the table of another entity', function (string $kind): void {
    $spy = crudInvalidationBind($kind);
    crudInvalidationNewSetting();
    $this->actingAs(crudInvalidationSuperadmin());
    $url = crudInvalidationSettingsUrl();

    crudInvalidationRead($spy, new Setting(), $url);
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $url));

    Role::factory()->create(['name' => 'other_entity_' . uniqid(), 'guard_name' => 'web']);

    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $url));
})->with(['array', 'file']);

it('flushes once, after the commit, and leaves the hit after a rollback', function (string $kind): void {
    $spy = crudInvalidationBind($kind);
    $setting = crudInvalidationNewSetting();
    $this->actingAs(crudInvalidationSuperadmin());
    $url = crudInvalidationSettingsUrl();
    $connection = $setting->getConnection();
    $table_label = CacheLabel::table($setting->getTable());
    $row_label = CacheLabel::row($setting->getTable(), $setting->getKey());
    $counter = fn (): int => (int) $this->counters?->get('labelv:' . $table_label);

    crudInvalidationRead($spy, new Setting(), $url);
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $url));

    // Rolled back: nothing is flushed and the cached response is still served.
    $spy->flushes = [];
    $counter_before = $counter();

    try {
        $connection->transaction(function () use ($setting, $spy): void {
            $setting->update(['description' => 'rolled back ' . uniqid()]);
            expect($spy->flushes)->toBe([]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($spy->flushes)->toBe([])
        ->and($counter())->toBe($counter_before);
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Setting(), $url), false);

    // Committed: nothing inside the transaction, one flush of the row and its table at the commit.
    $connection->transaction(function () use ($setting, $spy, $counter, $counter_before): void {
        $setting->update(['description' => 'committed ' . uniqid()]);

        expect($spy->flushes)->toBe([])
            ->and($counter())->toBe($counter_before);
    });

    expect($spy->flushes)->toHaveCount(1)
        ->and($spy->flushes[0])->toEqualCanonicalizing([$table_label, $row_label]);

    if ($kind === 'file') {
        // A counter index bumps the table counter once for each of the two labels, and never before the commit.
        expect($counter())->toBe($counter_before + 2);
    }

    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Setting(), $url));
})->with(['array', 'file']);

it('misses the roles list with its permissions once a permission is attached to a role', function (string $kind): void {
    $spy = crudInvalidationBind($kind);
    $role = Role::factory()->create(['name' => 'attach_target_' . uniqid(), 'guard_name' => 'web']);
    $permission = Permission::findOrCreate('invalidation.attach.' . uniqid(), 'web');
    $this->actingAs(crudInvalidationSuperadmin());
    $url = route('core.crud.list', ['module' => 'core', 'entity' => 'roles']) . '?' . http_build_query(['relations' => ['permissions']]);

    crudInvalidationRead($spy, new Role(), $url);
    crudInvalidationAssertHit(crudInvalidationRead($spy, new Role(), $url), false);

    $role->permissions()->attach($permission->getKey());

    crudInvalidationAssertMiss(crudInvalidationRead($spy, new Role(), $url), false);
})->with(['array', 'file']);
