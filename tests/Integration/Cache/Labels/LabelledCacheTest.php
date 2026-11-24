<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Modules\Core\Tests\Stubs\Cache\ThrowingLabelIndex;

it('computes a missing value once and serves it from the index after', function (): void {
    $index = new SpyLabelIndex();
    $cache = new LabelledCache($index);
    $calls = 0;
    $compute = static function () use (&$calls): string {
        $calls++;

        return 'value';
    };

    $first = $cache->remember('k', [CacheLabel::docs()], 60, $compute);
    $second = $cache->remember('k', [CacheLabel::docs()], 60, $compute);

    expect($first)->toBe('value')
        ->and($second)->toBe('value')
        ->and($calls)->toBe(1)
        ->and($index->stores)->toHaveCount(1)
        ->and($index->stores[0]['ttl'])->toBe(60)
        ->and($index->stores[0]['lookup']->dependencies->labels)->toBe([CacheLabel::docs()]);
});

it('computes again a hit the cacheable check rejects and stores no value it rejects', function (): void {
    $index = new SpyLabelIndex();
    $cache = new LabelledCache($index);
    $index->store($index->lookup('k', new CacheDependencies(['t'])), 'not an array', 60);
    $index->stores = [];

    $value = $cache->remember('k', ['t'], 60, static fn (): array => [], static fn (mixed $value): bool => $value !== [] && is_array($value));

    expect($value)->toBe([])
        ->and($index->stores)->toBe([]);
});

it('stores nothing when a label is flushed while the value is computed', function (): void {
    $index = new ArrayLabelIndex();
    $cache = new LabelledCache($index);

    $value = $cache->remember('k', ['t'], 60, static function () use ($index): string {
        $index->flush('t');

        return 'stale';
    });

    expect($value)->toBe('stale')
        ->and($index->lookup('k', new CacheDependencies(['t']))->hit)->toBeFalse();
});

it('never stores a value whose dependencies are incomplete', function (): void {
    $index = new SpyLabelIndex();

    new LabelledCache($index)->remember('k', new CacheDependencies(['t'], complete: false), 60, static fn (): string => 'v');

    expect($index->stores)->toBe([]);
});

it('stores nothing while a failover index is degraded', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $marker = Illuminate\Support\Facades\Cache::build(['driver' => 'array']);

    $value = new LabelledCache(new FailoverLabelIndex($primary, $marker))->remember('k', ['t'], 60, static fn (): string => 'computed');

    expect($value)->toBe('computed')
        ->and($primary->calls)->toBe([]);
});

it('refreshes a cached value whatever the index holds', function (): void {
    $index = new ArrayLabelIndex();
    $cache = new LabelledCache($index);
    $cache->remember('k', ['t'], 60, static fn (): string => 'old');

    $value = $cache->refresh('k', ['t'], 60, static fn (): string => 'new');

    expect($value)->toBe('new')
        ->and($cache->remember('k', ['t'], 60, static fn (): string => 'computed'))->toBe('new');
});

it('forgets the value remembered under the key and dependencies', function (): void {
    $index = new ArrayLabelIndex();
    $cache = new LabelledCache($index);
    $cache->remember('k', ['t'], 60, static fn (): string => 'old');

    $cache->forget('k', ['t']);

    expect($cache->remember('k', ['t'], 60, static fn (): string => 'new'))->toBe('new');
});

it('is resolved from the container around the bound index', function (): void {
    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    resolve(LabelledCache::class)->remember('k', ['t'], 60, static fn (): string => 'v');

    expect($index->stores)->toHaveCount(1);
});

it('forgets without reading the value or counting a lookup when the index can tell the stored key', function (): void {
    $stats = Illuminate\Support\Facades\Cache::store('array');
    $stats->flush();
    $index = new Modules\Core\Cache\Labels\MeasuredLabelIndex(new ArrayLabelIndex(), $stats);
    $cache = new LabelledCache($index);
    $cache->remember('k', [CacheLabel::table('core_settings')], 60, static fn (): string => 'old');

    $cache->forget('k', [CacheLabel::table('core_settings')]);

    expect($index->report()['table:core_settings'] ?? null)->toBe(['hits' => 0, 'misses' => 1, 'flushes' => 0])
        ->and($cache->remember('k', [CacheLabel::table('core_settings')], 60, static fn (): string => 'new'))->toBe('new');
});

it('forgets the value of a version counter index under the key its counters give', function (): void {
    $store = new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore());
    $cache = new LabelledCache(new Modules\Core\Cache\Labels\VersionCounterLabelIndex($store));
    $cache->remember('k', ['t'], 60, static fn (): string => 'old');
    (new Modules\Core\Cache\Labels\VersionCounterLabelIndex($store))->flush('other');

    $cache->forget('k', ['t']);

    expect($cache->remember('k', ['t'], 60, static fn (): string => 'new'))->toBe('new');
});

it('computes but does not store a value inside an open transaction, and stores it outside', function (): void {
    $index = new SpyLabelIndex();
    $cache = new LabelledCache($index);
    $calls = 0;
    $compute = static function () use (&$calls): string {
        $calls++;

        return 'value';
    };

    // A read inside a transaction may see a snapshot older than the lookup: a flush in between would not refuse it.
    $inside = Modules\Core\Models\Setting::query()->getConnection()->transaction(
        static fn (): string => $cache->remember('k', [CacheLabel::docs()], 60, $compute),
    );
    $outside = $cache->remember('k', [CacheLabel::docs()], 60, $compute);
    $cache->remember('k', [CacheLabel::docs()], 60, $compute);

    expect($inside)->toBe('value')
        ->and($outside)->toBe('value')
        ->and($calls)->toBe(2)
        ->and($index->stores)->toHaveCount(1);
});
