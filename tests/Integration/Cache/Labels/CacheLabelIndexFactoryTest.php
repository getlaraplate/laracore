<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheLabelIndexFactory;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Labels\MeasuredLabelIndex;
use Modules\Core\Cache\Labels\NullLabelIndex;
use Modules\Core\Cache\Labels\VersionCounterLabelIndex;
use Modules\Core\Contracts\ICacheLabelIndex;

beforeEach(function (): void {
    config()->set('cache.stores.file_labels', ['driver' => 'file', 'path' => storage_path('framework/cache/data-labels')]);
    config()->set('cache.stores.database_labels', ['driver' => 'database', 'table' => 'cache', 'connection' => null]);
    config()->set('cache.stores.null_labels', ['driver' => 'null']);
});

it('picks the index by the underlying store', function (string $store, string $expected): void {
    expect(CacheLabelIndexFactory::forStore(Cache::store($store)))->toBeInstanceOf($expected);
})->with([
    'array' => ['array', ArrayLabelIndex::class],
    'file' => ['file_labels', VersionCounterLabelIndex::class],
    'database' => ['database_labels', VersionCounterLabelIndex::class],
    'null' => ['null_labels', NullLabelIndex::class],
]);

it('guards the redis index of a plain redis store as it does under failover', function (): void {
    expect(CacheLabelIndexFactory::forStore(Cache::store('redis')))->toBeInstanceOf(FailoverLabelIndex::class);
});

it('wraps the index of the first failover store once', function (): void {
    config()->set('cache.stores.failover_redis_first', ['driver' => 'failover', 'stores' => ['redis', 'array']]);

    expect(CacheLabelIndexFactory::forStore(Cache::store('failover')))->toBeInstanceOf(FailoverLabelIndex::class)
        ->and(CacheLabelIndexFactory::forStore(Cache::store('failover_redis_first')))->toBeInstanceOf(FailoverLabelIndex::class);
});

it('reads the stores of a failover store from its own configuration entry', function (): void {
    config()->set('cache.stores.failover_named', ['driver' => 'failover', 'stores' => ['file_labels', 'array']]);
    config()->set('cache.stores.failover.stores', ['array']);

    $index = CacheLabelIndexFactory::forStore(Cache::store('failover_named'));

    // A file store has no row labels, the array store of `failover` has: the index follows `failover_named`.
    expect($index)->toBeInstanceOf(FailoverLabelIndex::class)
        ->and($index->supportsRowLabels())->toBeFalse();
});

it('refuses a failover store whose stores it cannot read', function (): void {
    $cache = Cache::build(['driver' => 'failover', 'stores' => ['array']]);

    CacheLabelIndexFactory::forStore($cache);
})->throws(InvalidArgumentException::class);

it('lets writes succeed and caches nothing while a plain redis store is down', function (): void {
    config()->set('database.redis.labels_down', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('cache.stores.redis_down', ['driver' => 'redis', 'connection' => 'labels_down']);
    Cache::store('file')->forget(FailoverLabelIndex::MARKER_KEY);
    $down = Cache::store('redis_down');
    $index = new MeasuredLabelIndex(CacheLabelIndexFactory::forStore($down), CacheLabelIndexFactory::statsRepository($down));
    app()->instance(ICacheLabelIndex::class, $index);

    try {
        $role = Modules\Core\Models\Role::factory()->create(['name' => 'redis_down_' . uniqid(), 'guard_name' => 'web']);
        $role->forceFill(['description' => 'updated while redis is down'])->save();
        $lookup = $index->lookup('k', new Modules\Core\Cache\Labels\CacheDependencies(['table:core_roles']));

        expect($lookup->hit)->toBeFalse()
            ->and($index->store($lookup, 'v', 60))->toBeFalse()
            ->and($role->fresh()?->description)->toBe('updated while redis is down');
    } finally {
        Cache::store('file')->forget(FailoverLabelIndex::MARKER_KEY);
    }
});

it('binds the measured index of the default store', function (): void {
    expect(app(ICacheLabelIndex::class))->toBeInstanceOf(MeasuredLabelIndex::class)
        ->and(app(ICacheLabelIndex::class))->toBe(app(ICacheLabelIndex::class));
});

it('guards the version counter index of a plain memcached store, whose refused flush would otherwise fail the write', function (): void {
    $store = Mockery::mock(Illuminate\Cache\MemcachedStore::class);
    $index = CacheLabelIndexFactory::forStore(new Modules\Core\Cache\Repository($store));

    expect($index)->toBeInstanceOf(FailoverLabelIndex::class);
});
