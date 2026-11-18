<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheLabelIndexFactory;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Labels\MeasuredLabelIndex;
use Modules\Core\Cache\Labels\NullLabelIndex;
use Modules\Core\Cache\Labels\RedisLabelIndex;
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

it('picks the redis index for the redis store', function (): void {
    expect(CacheLabelIndexFactory::forStore(Cache::store('redis')))->toBeInstanceOf(RedisLabelIndex::class);
});

it('wraps the index of the first failover store', function (): void {
    expect(CacheLabelIndexFactory::forStore(Cache::store('failover')))->toBeInstanceOf(FailoverLabelIndex::class);
});

it('binds the measured index of the default store', function (): void {
    expect(app(ICacheLabelIndex::class))->toBeInstanceOf(MeasuredLabelIndex::class)
        ->and(app(ICacheLabelIndex::class))->toBe(app(ICacheLabelIndex::class));
});
