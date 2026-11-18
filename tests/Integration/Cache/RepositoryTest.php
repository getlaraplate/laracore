<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\HasCache;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Repository;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;
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

it('clearByEntity flushes the label of its table only and leaves tagged entries cached', function (): void {
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $repository = new Repository(new ArrayStore());

    $repository->tags($repository->getCacheTags('docs'))->forever('docs-key', 'docs');
    $repository->clearByEntity(new TaggedCacheStubModel());

    expect($index->flushedLabels())->toBe([CacheLabel::table('tagged_cache_stub_models')])
        ->and($repository->tags($repository->getCacheTags('docs'))->get('docs-key'))->toBe('docs');
});

it('clearByUser flushes the label of that user only', function (): void {
    $alice = User::factory()->create();
    // Bound after the writes above, whose own flushes are not under test.
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    (new Repository(new ArrayStore()))->clearByUser($alice);

    expect($index->flushedLabels())->toBe([CacheLabel::user($alice->getKey())]);
});

it('clearByGroup flushes the label of that role only', function (): void {
    $role = Role::factory()->create(['guard_name' => 'web']);
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    (new Repository(new ArrayStore()))->clearByGroup($role, new TaggedCacheStubModel());

    expect($index->flushedLabels())->toBe([CacheLabel::role($role->getKey())]);
});

it('clearByUser and clearByGroup flush nothing when none of the entities uses the cache', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['guard_name' => 'web']);
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $repository = new Repository(new ArrayStore());

    // Users do not use the response cache.
    $repository->clearByUser($user, User::class);
    $repository->clearByGroup($role, [new User()]);

    expect($index->flushes)->toBe([]);
});

it('resolves the threshold and the default ttl from the configuration', function (): void {
    config()->set('cache.duration', 120);
    config()->set('cache.threshold', 10);

    $repository = new Repository(new ArrayStore());

    $threshold_method = new ReflectionMethod(Repository::class, 'getThreshold');

    expect($threshold_method->invoke($repository))->toBe(10)
        ->and(Repository::resolveDefaultCacheSeconds())->toBe(120);

    config()->set('cache.threshold', 0);
    config()->set('cache.duration', ['short' => 30, 'medium' => 90]);

    expect($threshold_method->invoke($repository))->toBe(0)
        ->and(Repository::resolveDefaultCacheSeconds())->toBe(90);
});

it('remember supports flexible array ttl branch', function (): void {
    $repository = new Repository(new ArrayStore());

    $result = $repository->remember('repo-flexible-key', [1, 5], fn () => 'flexible');

    expect($result)->toBe('flexible');
});

it('clear methods flush the labels of class-string entities, users and roles', function (): void {
    config()->set('auth.providers.users.model', User::class);
    $role = Role::factory()->create(['guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $repository = new Repository(new ArrayStore());

    $repository->clearByEntity(TaggedCacheStubModel::class);
    $repository->clearByUser($user, TaggedCacheStubModel::class);
    $repository->clearByGroup($role, TaggedCacheStubModel::class);

    expect($index->flushedLabels())->toBe([
        CacheLabel::table('tagged_cache_stub_models'),
        CacheLabel::user($user->getKey()),
        CacheLabel::role($role->getKey()),
    ]);
});

it('clearByEntity does not throw BadMethodCallException when cache driver does not support tags (database driver simulation)', function (): void {
    // Simulate a cache driver that does not support tags (e.g. database, file)
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    Cache::shouldReceive('supportsTags')->andReturn(false);
    Cache::shouldReceive('forget')->andReturn(true);

    $repository = new Repository(new ArrayStore());

    $cacheable = new class extends Model
    {
        use HasCache;

        protected $table = 'cacheable_no_tags';
    };

    expect(fn () => $repository->clearByEntity($cacheable))->not->toThrow(BadMethodCallException::class);
    expect($index->flushedLabels())->toBe([CacheLabel::table('cacheable_no_tags')]);
});

it('clearByEntity does not throw BadMethodCallException when cache driver does not support tags (file driver simulation)', function (): void {
    // Simulate a file cache driver that does not support tags
    $index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    Cache::shouldReceive('supportsTags')->andReturn(false);
    Cache::shouldReceive('forget')->andReturn(true);

    $repository = new Repository(new ArrayStore());

    $cacheable = new class extends Model
    {
        use HasCache;

        protected $table = 'cacheable_file_no_tags';
    };

    expect(fn () => $repository->clearByEntity($cacheable))->not->toThrow(BadMethodCallException::class);
    expect($index->flushedLabels())->toBe([CacheLabel::table('cacheable_file_no_tags')]);
});

it('ping succeeds on a working store and fails when the store throws', function (): void {
    $failing_store = Mockery::mock(Store::class);
    $failing_store->shouldReceive('put')->andThrow(new RuntimeException('Connection refused'));

    expect((new Repository(new ArrayStore()))->ping())->toBeTrue()
        ->and((new Repository($failing_store))->ping())->toBeFalse();
});
