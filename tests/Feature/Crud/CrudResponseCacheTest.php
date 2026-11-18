<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheLabelIndexFactory;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Repository;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\Cache\CrudResponseCache;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Modules\Core\Tests\Stubs\Cache\ThrowingLabelIndex;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/*
 * A CRUD read looks its response up before computing it: a hit answers without querying the entity, after the
 * permission check of the operation has run again.
 */

beforeEach(function (): void {
    $this->index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $this->index);
});

/**
 * How many queries the call runs on the model's connection that name the model's table.
 */
function crudResponseCacheQueriesOn(Model $model, Closure $call): int
{
    $count = 0;
    $recording = true;
    $connection = $model->getConnection()->getName();
    $table = $model->getConnection()->getTablePrefix() . $model->getTable();

    DB::listen(static function (QueryExecuted $query) use (&$count, &$recording, $connection, $table): void {
        if ($recording && $query->connectionName === $connection && str_contains($query->sql, $table)) {
            $count++;
        }
    });

    try {
        $call();
    } finally {
        $recording = false;
    }

    return $count;
}

/**
 * A user holding the given permissions through one role of its own.
 *
 * @param  list<string>  $permissions
 * @return array{0: User, 1: Role}
 */
function crudResponseCacheViewer(array $permissions): array
{
    $role = Role::factory()->create(['name' => 'cache_viewer_' . uniqid(), 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $viewer = User::factory()->create();
    $viewer->assignRole($role);

    return [$viewer, $role];
}

function crudResponseCacheSuperadmin(): User
{
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate('superadmin', 'web'));

    return $actor;
}

/**
 * The anonymous account, loaded as the configured user class: its permissions are stored under that morph type.
 */
function crudResponseCacheGuest(): Model
{
    Cache::flush();

    $guest = User::factory()->create([
        'name' => config('permission.users.guest'),
        'username' => config('permission.users.guest'),
        'email' => 'cache_guest_' . uniqid() . '@example.com',
    ]);

    return user_class()::query()->findOrFail($guest->getKey());
}

/**
 * A request without a caller. The anonymous account a previous request set on the guard is forgotten first, as a new
 * request would start without it.
 */
function crudResponseCacheGuestGet(string $url, string $language): TestResponse
{
    Auth::forgetGuards();

    return test()->getJson($url, ['Accept-Language' => $language]);
}

function crudResponseCacheSettingsUrl(array $query = []): string
{
    return route('core.crud.list', ['module' => 'core', 'entity' => 'settings']) . ($query === [] ? '' : '?' . http_build_query($query));
}

it('serves a second identical list without querying its table', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->count(2)->create();
    $this->actingAs(crudResponseCacheSuperadmin());
    $url = crudResponseCacheSettingsUrl(['sort' => [['property' => 'id', 'direction' => 'asc']]]);

    $first = null;
    $first_queries = crudResponseCacheQueriesOn(new Setting(), function () use ($url, &$first): void {
        $first = $this->getJson($url)->assertOk();
    });
    $second = null;
    $second_queries = crudResponseCacheQueriesOn(new Setting(), function () use ($url, &$second): void {
        $second = $this->getJson($url)->assertOk();
    });

    expect($first_queries)->toBeGreaterThan(0)
        ->and($second_queries)->toBe(0)
        ->and($second->json('data'))->toBe($first->json('data'))
        ->and($this->index->stores)->toHaveCount(1)
        ->and($this->index->stores[0]['ttl'])->toBe(Repository::resolveDefaultCacheSeconds());
});

it('serves a second identical detail and history without querying the table', function (string $route, string $entity, Closure $record): void {
    $model = $record();
    $this->actingAs(crudResponseCacheSuperadmin());
    $url = route($route, ['module' => 'core', 'entity' => $entity]) . '?' . http_build_query(['id' => $model->getKey()]);

    $this->getJson($url)->assertOk();
    $second_queries = crudResponseCacheQueriesOn($model, fn () => $this->getJson($url)->assertOk());

    expect($second_queries)->toBe(0);
})->with([
    'detail of a setting' => ['core.crud.detail', 'settings', static fn (): Model => Setting::factory()->persistedWithoutApprovalCapture()->create()],
    // The only permission in the table: the history read resolves its record with sole() over the whole table.
    'history of a versioned permission' => ['core.crud.history', 'permissions', static fn (): Model => Permission::factory()->create()],
]);

it('answers 403 on a cached url once the permission is removed straight from the pivot table', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $permission = PermissionName::forModel(new Setting(), 'select');
    [$viewer, $role] = crudResponseCacheViewer([$permission]);
    $url = crudResponseCacheSettingsUrl();

    $this->actingAs($viewer)->getJson($url)->assertOk();

    // No model event: nothing flushes the cached response, only the permission check of a hit refuses it.
    DB::table($role->permissions()->getTable())->where('role_id', $role->getKey())->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($viewer->fresh())->getJson($url)->assertForbidden();

    expect($this->index->stores)->toHaveCount(1);
});

it('answers 403 on a cached url once the permission of a requested relation is removed', function (): void {
    [$viewer, $role] = crudResponseCacheViewer([
        PermissionName::forModel(new Role(), 'select'),
        PermissionName::forModel(new Permission(), 'select'),
    ]);
    $url = route('core.crud.list', ['module' => 'core', 'entity' => 'roles']) . '?' . http_build_query(['relations' => ['permissions']]);

    $this->actingAs($viewer)->getJson($url)->assertOk();

    $relation_permission = Permission::findByName(PermissionName::forModel(new Permission(), 'select'), 'web');
    DB::table($role->permissions()->getTable())
        ->where('role_id', $role->getKey())
        ->where('permission_id', $relation_permission->getKey())
        ->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($viewer->fresh())->getJson($url)->assertForbidden();
});

it('keeps the responses of two guest locales apart', function (): void {
    crudResponseCacheGuest()->givePermissionTo(Permission::findOrCreate(PermissionName::forModel(new Setting(), 'select'), 'web'));
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $url = crudResponseCacheSettingsUrl();

    $english = crudResponseCacheGuestGet($url, 'en')->assertOk();
    $italian = crudResponseCacheGuestGet($url, 'it')->assertOk();
    $english_again = null;
    $english_queries = crudResponseCacheQueriesOn(new Setting(), function () use ($url, &$english_again): void {
        $english_again = crudResponseCacheGuestGet($url, 'en')->assertOk();
    });

    expect($english->json('meta.locale'))->toBe('en')
        ->and($italian->json('meta.locale'))->toBe('it')
        ->and($english_again->json('meta.locale'))->toBe('en')
        ->and($english_queries)->toBe(0)
        ->and($this->index->stores)->toHaveCount(2);
});

it('keeps the responses of a guest and of a user on one url apart', function (): void {
    $permission = Permission::findOrCreate(PermissionName::forModel(new Setting(), 'select'), 'web');
    crudResponseCacheGuest()->givePermissionTo($permission);
    [$viewer] = crudResponseCacheViewer([$permission->name]);
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $url = crudResponseCacheSettingsUrl();

    crudResponseCacheGuestGet($url, 'en')->assertOk();
    $user_queries = crudResponseCacheQueriesOn(new Setting(), fn () => $this->actingAs($viewer)->getJson($url, ['Accept-Language' => 'en'])->assertOk());

    expect($user_queries)->toBeGreaterThan(0)
        ->and($this->index->stores)->toHaveCount(2)
        ->and($this->index->stores[0]['lookup']->key)->not->toBe($this->index->stores[1]['lookup']->key);
});

it('refuses a caller without the permission before telling it a requested name is not a relation', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson(crudResponseCacheSettingsUrl(['relations' => ['not_a_relation']]))
        ->assertForbidden();

    expect($this->index->lookups)->toBe([]);
});

it('does not store a refused response', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $permission = PermissionName::forModel(new Setting(), 'select');
    $viewer = User::factory()->create();
    $url = crudResponseCacheSettingsUrl();

    $this->actingAs($viewer)->getJson($url)->assertForbidden();

    $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));

    $this->actingAs($viewer->fresh())->getJson($url)->assertOk();

    expect($this->index->stores)->toHaveCount(1);
});

it('does not store a response whose status is not 200', function (int $status): void {
    $this->actingAs(crudResponseCacheSuperadmin());
    $service = Mockery::mock(CrudService::class);
    $service->shouldReceive('list')->twice()->andReturn(new CrudResult(data: null, error: 'refused', statusCode: $status));
    $service->shouldReceive('authorizeCachedRead')->zeroOrMoreTimes();
    app()->instance(CrudService::class, $service);
    $url = crudResponseCacheSettingsUrl();

    $this->getJson($url)->assertStatus($status);
    $this->getJson($url)->assertStatus($status);

    expect($this->index->stores)->toBe([]);
})->with([
    'unprocessable' => Response::HTTP_UNPROCESSABLE_ENTITY,
    'forbidden' => Response::HTTP_FORBIDDEN,
    'not found' => Response::HTTP_NOT_FOUND,
]);

it('bypasses the cache with no_cache', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $this->actingAs(crudResponseCacheSuperadmin());
    $url = crudResponseCacheSettingsUrl(['no_cache' => 1]);

    $this->getJson($url)->assertOk();
    $second_queries = crudResponseCacheQueriesOn(new Setting(), fn () => $this->getJson($url)->assertOk());

    expect($second_queries)->toBeGreaterThan(0)
        ->and($this->index->lookups)->toBe([])
        ->and($this->index->stores)->toBe([]);
});

it('never stores the response of a model without HasCache', function (): void {
    $actor = crudResponseCacheSuperadmin();
    $this->actingAs($actor);
    $url = route('core.crud.list', ['module' => 'core', 'entity' => 'users']);

    $this->getJson($url)->assertOk();
    $second_queries = crudResponseCacheQueriesOn($actor, fn () => $this->getJson($url)->assertOk());

    expect($second_queries)->toBeGreaterThan(0)
        ->and($this->index->stores)->toBe([]);
});

it('never stores a response whose dependencies are incomplete', function (Closure $index): void {
    [$inner, $cleanup] = $index();
    $spy = new SpyLabelIndex($inner);
    app()->instance(ICacheLabelIndex::class, $spy);
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $this->actingAs(crudResponseCacheSuperadmin());
    // A morphTo segment names no table before the query.
    $url = crudResponseCacheSettingsUrl(['relations' => ['modifications.modifiable']]);

    try {
        $this->getJson($url)->assertOk();
        $second_queries = crudResponseCacheQueriesOn(new Setting(), fn () => $this->getJson($url)->assertOk());
    } finally {
        $cleanup();
    }

    expect($second_queries)->toBeGreaterThan(0)
        ->and($spy->stores)->toBe([]);
})->with([
    'membership index' => [static fn (): array => [new ArrayLabelIndex(), static fn (): null => null]],
    'counter index' => [static function (): array {
        $directory = storage_path('framework/testing/crud-response-cache-' . uniqid());

        return [
            CacheLabelIndexFactory::forStore(new Repository(new FileStore(new Filesystem(), $directory))),
            static fn (): bool => (new Filesystem())->deleteDirectory($directory),
        ];
    }],
]);

it('stores a complete response on a counter index', function (): void {
    $directory = storage_path('framework/testing/crud-response-cache-' . uniqid());
    $spy = new SpyLabelIndex(CacheLabelIndexFactory::forStore(new Repository(new FileStore(new Filesystem(), $directory))));
    app()->instance(ICacheLabelIndex::class, $spy);
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $this->actingAs(crudResponseCacheSuperadmin());
    $url = crudResponseCacheSettingsUrl();

    try {
        $this->getJson($url)->assertOk();
        $second_queries = crudResponseCacheQueriesOn(new Setting(), fn () => $this->getJson($url)->assertOk());
    } finally {
        (new Filesystem())->deleteDirectory($directory);
    }

    expect($second_queries)->toBe(0)
        ->and($spy->stores)->toHaveCount(1);
});

it('computes every read while the index cannot keep anything', function (Closure $index): void {
    app()->instance(ICacheLabelIndex::class, $index());
    Setting::factory()->persistedWithoutApprovalCapture()->create();
    $this->actingAs(crudResponseCacheSuperadmin());
    $url = crudResponseCacheSettingsUrl();

    $this->getJson($url)->assertOk();
    $second_queries = crudResponseCacheQueriesOn(new Setting(), fn () => $this->getJson($url)->assertOk());

    expect($second_queries)->toBeGreaterThan(0);
})->with([
    'failover while Redis is down' => [static function (): ICacheLabelIndex {
        $primary = new ThrowingLabelIndex();
        $primary->failing = true;

        return new FailoverLabelIndex($primary, new Repository(new ArrayStore()));
    }],
    'null store' => [static fn (): ICacheLabelIndex => CacheLabelIndexFactory::forStore(new Repository(new NullStore()))],
]);

it('does not route a search through the response cache', function (): void {
    $this->actingAs(crudResponseCacheSuperadmin());
    $service = Mockery::mock(CrudService::class);
    $service->shouldReceive('search')->twice()->andReturn(new CrudResult(data: []));
    app()->instance(CrudService::class, $service);
    $url = route('core.crud.search', ['module' => 'core', 'entity' => 'settings']) . '?' . http_build_query(['qs' => 'anything']);

    $this->getJson($url)->assertOk();
    $this->getJson($url)->assertOk();

    expect($this->index->lookups)->toBe([]);
});

/**
 * @param  array<string, mixed>  $query
 */
function crudResponseCacheKeyOf(string $path, array $query = [], ?User $user = null): string
{
    $request = Request::create($path, 'GET', $query);
    $request->setUserResolver(static fn (): ?User => $user);

    return app(CrudResponseCache::class)->keyFor($request);
}

it('keys a request by its input whatever the order of its keys', function (): void {
    $user = User::factory()->create();

    expect(crudResponseCacheKeyOf('/app/crud/select/core/settings', ['b' => '2', 'a' => ['z' => '3', 'c' => '1']], $user))
        ->toBe(crudResponseCacheKeyOf('/app/crud/select/core/settings', ['a' => ['c' => '1', 'z' => '3'], 'b' => '2'], $user))
        ->not->toBe(crudResponseCacheKeyOf('/app/crud/select/core/settings', ['a' => ['c' => '1', 'z' => '4'], 'b' => '2'], $user))
        ->not->toBe(crudResponseCacheKeyOf('/app/crud/select/core/roles', ['a' => ['c' => '1', 'z' => '3'], 'b' => '2'], $user));
});

it('keys a request by its user, the user roles and the locale', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $path = '/app/crud/select/core/settings';
    $alice_key = crudResponseCacheKeyOf($path, [], $alice);

    $bob_key = crudResponseCacheKeyOf($path, [], $bob);
    $alice->assignRole(Role::factory()->create(['guard_name' => 'web']));
    $alice_with_role_key = crudResponseCacheKeyOf($path, [], $alice);
    App::setLocale('it');
    $alice_in_italian_key = crudResponseCacheKeyOf($path, [], $alice);

    expect(array_unique([$alice_key, $bob_key, $alice_with_role_key, $alice_in_italian_key]))->toHaveCount(4);
});

it('keys a request by the token as well as by the user, so two tokens never share one', function (): void {
    $user = User::factory()->create();
    $first = $user->createToken('cachekey-first', [])->accessToken;
    $second = $user->createToken('cachekey-second', [])->accessToken;
    $path = '/api/v1/select/core/settings';

    $first_key = crudResponseCacheKeyOf($path, [], User::query()->findOrFail($user->getKey())->withAccessToken($first));
    $second_key = crudResponseCacheKeyOf($path, [], User::query()->findOrFail($user->getKey())->withAccessToken($second));
    $first_again = crudResponseCacheKeyOf($path, [], User::query()->findOrFail($user->getKey())->withAccessToken($first));

    expect($first_key)->not->toBe($second_key)
        ->and($first_again)->toBe($first_key)
        ->and($first_key)->not->toBe(crudResponseCacheKeyOf($path, [], $user));
});
