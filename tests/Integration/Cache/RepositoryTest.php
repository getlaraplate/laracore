<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\HasCache;
use Modules\Core\Cache\Repository;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Cache\OtherTaggedCacheStubModel;
use Modules\Core\Tests\Stubs\Cache\TaggedCacheStubModel;

it('getCacheTags namespaces every tag with the app name, drops duplicates and sorts them', function (): void {
    $repository = new Repository(Mockery::mock(Store::class));
    $app_name = config('app.name');

    expect($repository->getCacheTags('users'))->toBe([$app_name . ':users'])
        ->and($repository->getCacheTags(['users', 'roles', 'users']))->toBe([$app_name . ':roles', $app_name . ':users'])
        ->and($repository->getCacheTags(['roles', 'users']))->toBe($repository->getCacheTags(['users', 'roles']))
        ->and($repository->getCacheTags())->toBe([$app_name]);
});

it('a value stored with tags composed in one order is read and forgotten with the same tags in another order', function (): void {
    $repository = new Repository(new ArrayStore());

    $repository->tags($repository->getCacheTags(['users', 'roles']))->put('ordered-key', 'value', 60);
    $read = $repository->tags($repository->getCacheTags(['roles', 'users']))->get('ordered-key');
    $repository->tags($repository->getCacheTags(['roles', 'users', 'roles']))->forget('ordered-key');

    expect($read)->toBe('value')
        ->and($repository->tags($repository->getCacheTags(['users', 'roles']))->get('ordered-key'))->toBeNull();
});

/**
 * @param  array<string, string>  $query
 */
function cachedRequestFor(User $user, string $path = '/api/cached', array $query = []): Request
{
    $request = Request::create($path, 'GET', $query);
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

it('tryByRequest stores the response under the entity tag, so clearByEntity makes the next request recompute', function (): void {
    $repository = new Repository(new ArrayStore());
    $request = cachedRequestFor(User::factory()->create());
    $model = new TaggedCacheStubModel();

    $first = $repository->tryByRequest($model, $request, fn (): array => ['v' => 1], 60);
    $cached = $repository->tryByRequest($model, $request, fn (): array => ['v' => 2], 60);
    $repository->clearByEntity($model);
    $recomputed = $repository->tryByRequest($model, $request, fn (): array => ['v' => 3], 60);

    expect($first)->toBe(['v' => 1])
        ->and($cached)->toBe(['v' => 1])
        ->and($recomputed)->toBe(['v' => 3]);
});

it('saving a cacheable model invalidates the responses cached for its table', function (): void {
    /** @var Repository $repository */
    $repository = Cache::store();
    $request = cachedRequestFor(User::factory()->create());

    $repository->tryByRequest(new TaggedCacheStubModel(), $request, fn (): array => ['v' => 1], 60);
    (new TaggedCacheStubModel())->invalidateCache();

    expect($repository->tryByRequest(new TaggedCacheStubModel(), $request, fn (): array => ['v' => 2], 60))->toBe(['v' => 2]);
});

it('clearByEntity on one table leaves other tables and other tagged entries cached', function (): void {
    $repository = new Repository(new ArrayStore());
    $request = cachedRequestFor(User::factory()->create());

    $repository->tryByRequest(new OtherTaggedCacheStubModel(), $request, fn (): array => ['other' => 1], 60);
    $repository->tags($repository->getCacheTags('docs'))->forever('docs-key', 'docs');
    $repository->clearByEntity(new TaggedCacheStubModel());

    expect($repository->tryByRequest(new OtherTaggedCacheStubModel(), $request, fn (): array => ['other' => 2], 60))->toBe(['other' => 1])
        ->and($repository->tags($repository->getCacheTags('docs'))->get('docs-key'))->toBe('docs');
});

it('clearByUser invalidates the responses of that user only', function (): void {
    $repository = new Repository(new ArrayStore());
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $model = new TaggedCacheStubModel();

    $repository->tryByRequest($model, cachedRequestFor($alice), fn (): array => ['alice' => 1], 60);
    $repository->tryByRequest($model, cachedRequestFor($bob), fn (): array => ['bob' => 1], 60);
    $repository->clearByUser($alice);

    expect($repository->tryByRequest($model, cachedRequestFor($alice), fn (): array => ['alice' => 2], 60))->toBe(['alice' => 2])
        ->and($repository->tryByRequest($model, cachedRequestFor($bob), fn (): array => ['bob' => 2], 60))->toBe(['bob' => 1]);
});

it('clearByGroup invalidates the responses of the users holding that role only', function (): void {
    $repository = new Repository(new ArrayStore());
    $role = Role::factory()->create(['guard_name' => 'web']);
    $member = User::factory()->create();
    $member->assignRole($role);
    $outsider = User::factory()->create();
    $model = new TaggedCacheStubModel();

    $repository->tryByRequest($model, cachedRequestFor($member), fn (): array => ['member' => 1], 60);
    $repository->tryByRequest($model, cachedRequestFor($outsider), fn (): array => ['outsider' => 1], 60);
    $repository->clearByGroup($role, $model);

    expect($repository->tryByRequest($model, cachedRequestFor($member), fn (): array => ['member' => 2], 60))->toBe(['member' => 2])
        ->and($repository->tryByRequest($model, cachedRequestFor($outsider), fn (): array => ['outsider' => 2], 60))->toBe(['outsider' => 1]);
});

it('clearByRequest forgets exactly the response that tryByRequest stored for that request', function (): void {
    $repository = new Repository(new ArrayStore());
    $user = User::factory()->create();
    $models = [new TaggedCacheStubModel(), new OtherTaggedCacheStubModel()];

    $repository->tryByRequest($models, cachedRequestFor($user, '/api/a'), fn (): array => ['a' => 1], 60);
    $repository->tryByRequest($models, cachedRequestFor($user, '/api/b'), fn (): array => ['b' => 1], 60);
    $repository->tryByRequest(null, cachedRequestFor($user, '/api/plain'), fn (): array => ['plain' => 1], 60);
    $repository->clearByRequest(cachedRequestFor($user, '/api/a'), array_reverse($models));
    $repository->clearByRequest(cachedRequestFor($user, '/api/plain'));

    expect($repository->tryByRequest($models, cachedRequestFor($user, '/api/a'), fn (): array => ['a' => 2], 60))->toBe(['a' => 2])
        ->and($repository->tryByRequest($models, cachedRequestFor($user, '/api/b'), fn (): array => ['b' => 2], 60))->toBe(['b' => 1])
        ->and($repository->tryByRequest(null, cachedRequestFor($user, '/api/plain'), fn (): array => ['plain' => 2], 60))->toBe(['plain' => 2]);
});

it('tryByRequest serves the cached response under the default ttl', function (): void {
    config()->set('cache.duration', 120);
    config()->set('cache.threshold', 10);
    $repository = new Repository(new ArrayStore());
    $request = cachedRequestFor(User::factory()->create());

    $repository->tryByRequest(new TaggedCacheStubModel(), $request, fn (): array => ['v' => 1]);

    expect($repository->tryByRequest(new TaggedCacheStubModel(), $request, fn (): array => ['v' => 2]))->toBe(['v' => 1]);
});

it('tryByRequest still caches on a store without tag support', function (): void {
    $directory = storage_path('framework/testing/repository-untagged-' . uniqid());
    $repository = new Repository(new FileStore(new Filesystem(), $directory));
    $request = cachedRequestFor(User::factory()->create());

    try {
        $repository->tryByRequest(new TaggedCacheStubModel(), $request, fn (): array => ['v' => 1], 60);

        expect($repository->tryByRequest(new TaggedCacheStubModel(), $request, fn (): array => ['v' => 2], 60))->toBe(['v' => 1]);
    } finally {
        (new Filesystem())->deleteDirectory($directory);
    }
});

it('tryByRequest returns callback immediately when model does not use cache', function (): void {
    $repository = new Repository(new ArrayStore());

    $request = Request::create('/api/items', 'GET');
    $request->setUserResolver(fn (): User => User::factory()->create());

    $result = $repository->tryByRequest(
        new class extends Model
        {
            protected $table = 'plain_models';
        },
        $request,
        fn () => ['fresh' => true],
    );

    expect($result)->toBe(['fresh' => true]);
});

it('tryByRequest unwraps ResponseBuilder response when remember callback runs', function (): void {
    $repository = new Repository(new ArrayStore());

    $request = Request::create('/api/items', 'GET', ['z' => '2', 'a' => '1']);
    $request->setUserResolver(fn (): User => User::factory()->create());

    $model = new class extends Model
    {
        use HasCache;

        protected $table = 'cacheable_models';
    };

    $result = $repository->tryByRequest(
        $model,
        $request,
        fn () => (new ResponseBuilder($request))->setData(['wrapped' => true]),
        30,
    );

    expect($result)->toBeInstanceOf(JsonResponse::class);
});

it('clear methods flush and forget through tags for entity, request, user and group', function (): void {
    $repository = new Repository(new ArrayStore());
    config()->set('auth.providers.users.model', User::class);

    $cacheable = new class extends Model
    {
        use HasCache;

        protected $table = 'cacheable_entities';
    };

    $request = Request::create('/api/cache', 'GET', ['a' => '1']);
    $request->setUserResolver(fn (): User => User::factory()->create());

    $repository->clearByEntity($cacheable);
    $repository->clearByRequest($request, $cacheable);
    $repository->clearByRequest($request, null);

    $user = User::factory()->create();
    $role = Role::factory()->create(['guard_name' => 'web']);
    $user->assignRole($role);
    $repository->clearByUser($user, $cacheable);
    $repository->clearByUser($user, null);
    $repository->clearByGroup($role, $cacheable);
    $repository->clearByGroup($role, null);

    expect(true)->toBeTrue();
});

it('private helpers compute request key and duration variants', function (): void {
    config()->set('cache.duration', 120);
    config()->set('cache.threshold', 10);

    $repository = new Repository(new ArrayStore());
    config()->set('auth.providers.users.model', User::class);

    $request = Request::create('/api/key', 'GET', ['b' => '2', 'a' => ['z' => '3', 'c' => '1']]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['guard_name' => 'web']);
    $user->assignRole($role);
    $request->setUserResolver(fn (): User => $user);

    $key_method = new ReflectionMethod(Repository::class, 'getKeyFromRequest');
    $key_method->setAccessible(true);
    $key_parts_method = new ReflectionMethod(Repository::class, 'getKeyPartsFromUser');
    $key_parts_method->setAccessible(true);
    $user_tags = $key_parts_method->invoke($repository, $user);
    $key = $key_method->invoke($repository, $request, $user_tags);

    $threshold_method = new ReflectionMethod(Repository::class, 'getThreshold');
    $threshold_method->setAccessible(true);
    $resolve_default_method = new ReflectionMethod(Repository::class, 'resolveDefaultCacheSeconds');
    $resolve_default_method->setAccessible(true);

    expect($key)->toBeString()
        ->and($threshold_method->invoke($repository))->toBe(10)
        ->and($resolve_default_method->invoke($repository))->toBe(120);

    config()->set('cache.threshold', 0);

    expect($threshold_method->invoke($repository))->toBe(0)
        ->and($resolve_default_method->invoke($repository))->toBe(120);
});

it('remember supports flexible array ttl branch', function (): void {
    $repository = new Repository(new ArrayStore());

    $result = $repository->remember('repo-flexible-key', [1, 5], fn () => 'flexible');

    expect($result)->toBe('flexible');
});

it('tryByRequest supports entity arrays with class strings and plain return values', function (): void {
    $repository = new Repository(new ArrayStore());

    $request = Request::create('/api/multi', 'GET', ['x' => '1']);
    $request->setUserResolver(fn (): User => User::factory()->create());

    $model = new class extends Model
    {
        use HasCache;

        protected $table = 'multi_cache_models';
    };

    $result = $repository->tryByRequest(
        [$model::class, $model],
        $request,
        fn () => ['raw' => true],
        10,
    );

    expect($result)->toBe(['raw' => true]);
});

it('clear methods support class-string entities and role/user key helpers branches', function (): void {
    $repository = new Repository(new ArrayStore());
    config()->set('auth.providers.users.model', User::class);
    $request = Request::create('/api/clear-string', 'GET', ['a' => '1']);
    $request->setUserResolver(fn (): User => User::factory()->create());

    $cacheable = new class extends Model
    {
        use HasCache;

        protected $table = 'string_cacheable_entities';
    };
    $class = $cacheable::class;

    $repository->clearByEntity($class);
    $repository->clearByRequest($request, $class);

    $role = Role::factory()->create(['guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $repository->clearByUser($user, $class);
    $repository->clearByGroup($role, $class);

    $method = new ReflectionMethod(Repository::class, 'getKeyPartsFromUser');
    $method->setAccessible(true);
    $parts_from_roles = $method->invoke($repository, $user);
    expect($parts_from_roles)->toContain('U' . $user->id);

    $groups_user = new class extends User
    {
        public function groups()
        {
            return null;
        }
    };
    $groups_user->id = 776;
    $group_c = new class extends Model {};
    $group_c->id = 12;
    $groups_user->setRelation('groups', collect([$group_c]));
    $parts_from_groups = $method->invoke($repository, $groups_user);
    expect($parts_from_groups)->toContain('R12')->toContain('U776');

    $user_groups_user = new class extends User
    {
        public function user_groups()
        {
            return null;
        }
    };
    $user_groups_user->id = 777;
    $group_a = new class extends Model {};
    $group_a->id = 10;
    $group_b = new class extends Model {};
    $group_b->id = 5;
    $user_groups_user->setRelation('user_groups', collect([$group_a, $group_b]));
    $parts_from_user_groups = $method->invoke($repository, $user_groups_user);
    expect($parts_from_user_groups)->toContain('R5')->toContain('R10');

    $user_roles_user = new class extends User
    {
        public function user_roles()
        {
            return null;
        }
    };
    $user_roles_user->id = 778;
    $role_model = new class extends Model {};
    $role_model->id = 8;
    $user_roles_user->setRelation('user_roles', collect([$role_model]));
    $parts_from_user_roles = $method->invoke($repository, $user_roles_user);

    expect($parts_from_user_groups)->toContain('U777')
        ->and($parts_from_user_roles)->toContain('R8')
        ->and($parts_from_user_roles)->toContain('U778');
});

it('clearByEntity does not throw BadMethodCallException when cache driver does not support tags (database driver simulation)', function (): void {
    // Simulate a cache driver that does not support tags (e.g. database, file)
    $index = new Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);
    Cache::shouldReceive('supportsTags')->andReturn(false);
    Cache::shouldReceive('forget')->andReturn(true);

    $repository = new Repository(new ArrayStore());

    $cacheable = new class extends Model
    {
        use HasCache;

        protected $table = 'cacheable_no_tags';
    };

    expect(fn () => $repository->clearByEntity($cacheable))->not->toThrow(BadMethodCallException::class);
    expect($index->flushedLabels())->toBe([Modules\Core\Cache\Labels\CacheLabel::table('cacheable_no_tags')]);
});

it('clearByEntity does not throw BadMethodCallException when cache driver does not support tags (file driver simulation)', function (): void {
    // Simulate a file cache driver that does not support tags
    $index = new Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);
    Cache::shouldReceive('supportsTags')->andReturn(false);
    Cache::shouldReceive('forget')->andReturn(true);

    $repository = new Repository(new ArrayStore());

    $cacheable = new class extends Model
    {
        use HasCache;

        protected $table = 'cacheable_file_no_tags';
    };

    expect(fn () => $repository->clearByEntity($cacheable))->not->toThrow(BadMethodCallException::class);
    expect($index->flushedLabels())->toBe([Modules\Core\Cache\Labels\CacheLabel::table('cacheable_file_no_tags')]);
});

it('ping succeeds on a working store and fails when the store throws', function (): void {
    $failing_store = Mockery::mock(Store::class);
    $failing_store->shouldReceive('put')->andThrow(new RuntimeException('Connection refused'));

    expect((new Repository(new ArrayStore()))->ping())->toBeTrue()
        ->and((new Repository($failing_store))->ping())->toBeFalse();
});

it('tryByRequest keys a response by the token as well as by the user, so two tokens never share one', function (): void {
    $repository = new Repository(new ArrayStore());
    $user = User::factory()->create();
    $first = $user->createToken('cachekey-first', [])->accessToken;
    $second = $user->createToken('cachekey-second', [])->accessToken;

    $request_with = static function (Laravel\Sanctum\PersonalAccessToken $token) use ($user): Request {
        $token_user = User::query()->findOrFail($user->getKey())->withAccessToken($token);
        $request = Request::create('/api/v1/select/core/settings', 'GET');
        $request->setUserResolver(static fn (): User => $token_user);

        return $request;
    };

    $from_first = $repository->tryByRequest(new Modules\Core\Models\Setting, $request_with($first), fn (): array => ['token' => 'first']);
    $from_second = $repository->tryByRequest(new Modules\Core\Models\Setting, $request_with($second), fn (): array => ['token' => 'second']);
    $first_again = $repository->tryByRequest(new Modules\Core\Models\Setting, $request_with($first), fn (): array => ['token' => 'recomputed']);

    expect($from_first)->toBe(['token' => 'first'])
        ->and($from_second)->toBe(['token' => 'second'])
        ->and($first_again)->toBe(['token' => 'first']);
});
