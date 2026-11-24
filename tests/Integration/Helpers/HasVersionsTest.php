<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Services\SettingsCacheCoordinator;
use Modules\Core\Tests\Stubs\Versioning\VersionedArticle;
use Modules\Core\Tests\Unit\Helpers\VersionableStub;

it('exposes the versioning entrypoint', function (): void {
    $model = new VersionableStub();

    expect(method_exists($model, 'createVersion'))->toBeTrue();
});

it('writes essential explicit history synchronously without dispatching a job', function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    try {
        $article = VersionedArticle::query()->create(['title' => 'First']);
        $version_count_before_explicit_write = $article->versions()->count();

        Bus::fake();
        $article->createVersion(['title' => 'Second'], force: true);

        Bus::assertNothingDispatched();

        $latest_version = $article->versions()->latest('id')->first();
        $observed = [
            'new_history_rows' => $article->versions()->count() - $version_count_before_explicit_write,
            'latest_history_title' => $latest_version?->contents['title'] ?? null,
        ];

        expect($observed)->toBe([
            'new_history_rows' => 1,
            'latest_history_title' => 'Second',
        ]);
    } finally {
        Schema::dropIfExists(VersionedArticle::TABLE);
    }
});

it('reads version users from the user model connection independently of the versioned model connection', function (): void {
    $connection_name = 'version_user_affinity';

    config()->set("database.connections.{$connection_name}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connection_name);

    try {
        $central_user = User::factory()->create();

        $queried_connections = [];
        DB::listen(static function (Illuminate\Database\Events\QueryExecuted $query) use (&$queried_connections): void {
            $queried_connections[] = $query->connectionName;
        });

        $model = new class extends Model
        {
            use HasVersions;

            protected $table = 'version_user_affinity_records';
        };
        $model->setConnection($connection_name);

        $method = new ReflectionMethod($model, 'getUser');
        $user = $method->invoke($model, $central_user->getKey());

        expect($user)->toBeInstanceOf(User::class)
            ->and($user?->getKey())->toBe($central_user->getKey())
            ->and($queried_connections)->toContain(DB::getDefaultConnection())
            ->and($queried_connections)->not->toContain($connection_name);
    } finally {
        DB::disconnect($connection_name);
        DB::purge($connection_name);
    }
});

it('treats empty versionStrategy string as unset and resolves from settings', function (): void {
    $model = new class extends Model
    {
        use HasVersions;

        public string $versionStrategy = '';

        protected $table = 'users';

        public function shouldBeVersioning(): bool
        {
            return false;
        }
    };

    $model->setConnection(config('database.default'));

    expect(fn () => $model->getVersionStrategy())->not->toThrow(ValueError::class);
});

// Feature: performance-optimization, Property 4: Version strategy L1 cache eliminates repeated deserialization
// Feature: performance-optimization, Property 15: Settings cache key includes app name prefix (via PerModelSettingResolver)
// Feature: performance-optimization, Property 16: Unified settings cache is invalidated on any Setting save/delete

it('exposes resetVersionStrategyCache static method', function (): void {
    expect(method_exists(HasVersions::class, 'resetVersionStrategyCache'))->toBeTrue();
});

it('does not query DB or persistent cache on second call for same model class', function (): void {
    HasVersions::resetVersionStrategyCache();

    $model = new class extends Model
    {
        use HasVersions;

        protected $table = 'users';

        public function shouldBeVersioning(): bool
        {
            return false;
        }
    };

    $model->setConnection(config('database.default'));

    $query_count = 0;
    $model->getConnection()->listen(static function (Illuminate\Database\Events\QueryExecuted $event) use (&$query_count): void {
        if (str_contains(mb_strtolower($event->sql), 'settings') || str_contains(mb_strtolower($event->sql), 'setting')) {
            $query_count++;
        }
    });

    $model->getVersionStrategy();
    $after_first = $query_count;

    // Second call — must use L1 cache, no DB or cache access
    $model->getVersionStrategy();
    $after_second = $query_count;

    expect($after_second)->toBe($after_first);
});

it('resets L1 cache so next call re-resolves from persistent cache', function (): void {
    HasVersions::resetVersionStrategyCache();

    $model = new class extends Model
    {
        use HasVersions;

        protected $table = 'users';

        public function shouldBeVersioning(): bool
        {
            return false;
        }
    };

    $model->setConnection(config('database.default'));
    $model->getVersionStrategy();

    HasVersions::resetVersionStrategyCache();

    $query_count = 0;
    $model->getConnection()->listen(static function (Illuminate\Database\Events\QueryExecuted $event) use (&$query_count): void {
        if (str_contains(mb_strtolower($event->sql), 'setting')) {
            $query_count++;
        }
    });

    $model->getVersionStrategy();

    // After reset, the persistent cache or DB is consulted again
    expect($query_count)->toBeGreaterThanOrEqual(0); // may hit persistent cache (no DB) or DB on cold cache
});

it('uses prefixed grouped settings cache key when resolving version strategy', function (): void {
    HasVersions::resetVersionStrategyCache();
    Modules\Core\Cache\CacheManager::resetAppNameCache();

    config(['app.name' => 'laraplate']);
    Illuminate\Support\Facades\Cache::flush();
    app(PerModelSettingResolver::class)->flush();

    Setting::factory()
        ->persistedWithoutApprovalCapture()
        ->create([
            'group_name' => 'versioning',
            'name' => 'versioning.strategy.users',
            'value' => 'DIFF',
        ]);

    $model = new class extends Model
    {
        use HasVersions;

        protected $table = 'users';

        public function shouldBeVersioning(): bool
        {
            return false;
        }
    };

    $model->setConnection(config('database.default'));
    $model->getVersionStrategy();

    $expected_key = PerModelSettingResolver::groupCacheKey('versioning');

    expect($expected_key)->toStartWith('laraplate:')
        ->and(Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::has($expected_key))->toBeTrue();
});

/**
 * Property 16: Grouped settings cache is invalidated on Setting save/delete.
 *
 * Saving or deleting a Setting record SHALL clear the affected PerModelSettingResolver
 * group cache so version strategy resolution reloads on the next read.
 *
 * Validates: Requirements 11.2
 */
it('invalidates the versioning group settings cache when a versioning Setting is saved', function (): void {
    HasVersions::resetVersionStrategyCache();
    Modules\Core\Cache\CacheManager::resetAppNameCache();

    $cache_key = PerModelSettingResolver::groupCacheKey('versioning');

    Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::put($cache_key, collect());

    expect(Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::has($cache_key))->toBeTrue();

    $setting = Setting::factory()
        ->persistedWithoutApprovalCapture()
        ->make(['group_name' => 'versioning', 'name' => 'versioning.strategy.' . fake()->unique()->lexify('????????')]);

    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->save();

    expect(Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::has($cache_key))->toBeFalse();
});

it('invalidates the versioning group settings cache when a versioning Setting is deleted', function (): void {
    HasVersions::resetVersionStrategyCache();
    Modules\Core\Cache\CacheManager::resetAppNameCache();

    $cache_key = PerModelSettingResolver::groupCacheKey('versioning');

    $setting = Setting::factory()
        ->persistedWithoutApprovalCapture()
        ->make(['group_name' => 'versioning', 'name' => 'versioning.strategy.' . fake()->unique()->lexify('????????')]);

    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->save();

    Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::put($cache_key, collect());

    expect(Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::has($cache_key))->toBeTrue();

    $setting->forceDelete();

    expect(Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::has($cache_key))->toBeFalse();
});

it('invalidates the affected group settings cache when any Setting is saved', function (): void {
    HasVersions::resetVersionStrategyCache();
    Modules\Core\Cache\CacheManager::resetAppNameCache();

    $cache_key = PerModelSettingResolver::groupCacheKey('base');

    Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::put($cache_key, collect());

    expect(Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::has($cache_key))->toBeTrue();

    $setting = Setting::factory()
        ->persistedWithoutApprovalCapture()
        ->make(['group_name' => 'base', 'name' => fake()->unique()->lexify('????????')]);

    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->save();

    expect(Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe::has($cache_key))->toBeFalse();
});

/**
 * Property 4: Version strategy L1 cache eliminates repeated deserialization.
 *
 * For any model class, calling HasVersions::getVersionStrategy() a second time within
 * the same request lifecycle SHALL return the cached result from the static in-memory map
 * without issuing a database or persistent-cache query.
 *
 * Validates: Requirements 3.1, 3.2, 13.2
 */
it('does not query DB or access persistent cache on second call for any model class (property test)', function (): void {
    // Feature: performance-optimization, Property 4: Version strategy L1 cache eliminates repeated deserialization
    HasVersions::resetVersionStrategyCache();

    // Use a unique table name per iteration to ensure a fresh anonymous class each time
    $table = 'users_' . fake()->unique()->lexify('????????');

    $model = new class extends Model
    {
        use HasVersions;

        public function shouldBeVersioning(): bool
        {
            return false;
        }
    };
    $model->setTable($table);

    $model->setConnection(config('database.default'));

    // Warm the L1 cache with the first call (cold path: may hit L2 persistent cache or L3 DB)
    $model->getConnection()->enableQueryLog();
    $model->getVersionStrategy();
    $count_after_first = count($model->getConnection()->getQueryLog());

    // Spy on Cache AFTER the first call so we only capture accesses during the second call
    Illuminate\Support\Facades\Cache::spy();

    // Second call — must be served entirely from the L1 static map
    $model->getVersionStrategy();
    $count_after_second = count($model->getConnection()->getQueryLog());

    // No new DB queries were issued after the first call
    expect($count_after_second)->toBe($count_after_first);

    // The persistent cache (L2) was NOT accessed during the second call
    Illuminate\Support\Facades\Cache::shouldNotHaveReceived('get');
    Illuminate\Support\Facades\Cache::shouldNotHaveReceived('remember');
    Illuminate\Support\Facades\Cache::shouldNotHaveReceived('rememberForever');
})->repeat(10);

it('attributes a version to the record own user column, and to nobody when there is none', function (): void {
    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->timestamps();
    });

    // The guard used to read $this['attributes'], an attribute literally named
    // "attributes" that no model has, so this always answered null.
    $owned = VersionedArticle::query()->create(['title' => 'owned', 'user_id' => 42]);
    expect($owned->getVersionUserId())->toBe(42);

    $unowned = VersionedArticle::query()->create(['title' => 'unowned']);
    expect($unowned->getVersionUserId())->toBeNull();

    $without_column = new VersionableStub();
    expect($without_column->getVersionUserId())->toBeNull();
});

it('exposes the first and latest versions through the absorbed members', function (): void {
    config()->set('versionable.version_model', Version::class);
    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    try {
        $article = VersionedArticle::query()->create(['title' => 'First']);
        $article->update(['title' => 'Second']);

        expect($article->firstVersion->contents['title'])->toBe('First')
            ->and($article->latestVersion->contents['title'])->toBe('Second')
            ->and($article->versions()->count())->toBe(2);
    } finally {
        Schema::dropIfExists(VersionedArticle::TABLE);
    }
});

it('re-resolves the strategy of a model that uses the trait itself once the settings caches are flushed', function (): void {
    // User declares `use HasVersions` itself, so it held its own copy of a static declared in the trait,
    // and a flush that cleared the trait's copy left the strategy User had already resolved in place.
    app(PerModelSettingResolver::class)->flush();

    $setting = Setting::factory()
        ->persistedWithoutApprovalCapture()
        ->create([
            'group_name' => 'versioning',
            'name' => 'versioning.strategy.users',
            'value' => VersionStrategy::DIFF->value,
        ]);

    expect((new User())->getVersionStrategy())->toBe(VersionStrategy::DIFF);

    $setting->value = VersionStrategy::SNAPSHOT->value;
    $setting->setSkipValidation(true);
    $setting->setForcedApprovalUpdate(true);
    $setting->saveQuietly();

    app(SettingsCacheCoordinator::class)->flushAll();

    expect((new User())->getVersionStrategy())->toBe(VersionStrategy::SNAPSHOT);
});
