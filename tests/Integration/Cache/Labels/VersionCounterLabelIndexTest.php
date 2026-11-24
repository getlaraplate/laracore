<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Labels\LabelIndexUnavailableException;
use Modules\Core\Cache\Labels\VersionCounterLabelIndex;

it('throws when the store can neither create nor increment a counter, as a memcached server that is down', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('add')->twice()->andReturn(false);
    $cache->shouldReceive('increment')->once()->andReturn(false);

    new VersionCounterLabelIndex($cache)->flush('table:core_users');
})->throws(LabelIndexUnavailableException::class);

it('increments an existing counter, and creates again one lost between the add and the increment', function (array $adds, int|false $increment): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('add')->times(count($adds))->andReturn(...$adds);
    $cache->shouldReceive('increment')->once()->andReturn($increment);

    new VersionCounterLabelIndex($cache)->flush('table:core_users');
})->with([
    'existing' => [[false], 2],
    'lost in between' => [[false, true], false],
]);

it('degrades the failover index when the counter store fails a flush', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('increment')->andReturn(false);
    $cache->shouldReceive('add')->andReturn(false);
    $index = new FailoverLabelIndex(
        new VersionCounterLabelIndex($cache),
        new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore()),
    );

    $index->flush('table:core_users');

    expect($index->isDegraded())->toBeTrue();
});

it('lets a lookup on a store that answers misses go on as a miss', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('many')->andReturn([]);
    $cache->shouldReceive('add')->andReturn(false);
    $cache->shouldReceive('get')->andReturn(null);

    expect(new VersionCounterLabelIndex($cache)->lookup('k', new CacheDependencies(['t']))->hit)->toBeFalse();
});

/**
 * A memcached store on a client double that answers every call with the result code given, as a pool with a node down.
 */
function versionCounterMemcachedStore(int $result_code): Illuminate\Cache\MemcachedStore
{
    $memcached = Mockery::mock(Memcached::class);
    $memcached->shouldReceive('getMulti')->andReturn(false);
    $memcached->shouldReceive('get')->andReturn(false);
    $memcached->shouldReceive('set')->andReturn(false);
    $memcached->shouldReceive('add')->andReturn(false);
    $memcached->shouldReceive('increment')->andReturn(false);
    $memcached->shouldReceive('getResultCode')->andReturn($result_code);
    $memcached->shouldReceive('getResultMessage')->andReturn('SOME ERRORS WERE REPORTED');

    return new Illuminate\Cache\MemcachedStore($memcached);
}

it('degrades the failover index on a memcached read that fails, instead of resolving every counter to one value', function (): void {
    $index = new FailoverLabelIndex(
        new VersionCounterLabelIndex(new Modules\Core\Cache\Repository(versionCounterMemcachedStore(Memcached::RES_SOME_ERRORS))),
        new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore()),
    );

    $lookup = $index->lookup('k', new CacheDependencies(['table:core_users']));

    expect($lookup->hit)->toBeFalse()
        ->and($index->isDegraded())->toBeTrue()
        ->and($index->store($lookup, 'v', 60))->toBeFalse();
});

it('throws on a memcached write that fails, whatever the call', function (Closure $call): void {
    $index = new VersionCounterLabelIndex(new Modules\Core\Cache\Repository(versionCounterMemcachedStore(Memcached::RES_SERVER_MARKED_DEAD)));

    $call($index);
})->with([
    'store' => [static fn (VersionCounterLabelIndex $index): bool => $index->store(new Modules\Core\Cache\Labels\CacheLookup('k', false, null, 1, new CacheDependencies(['t'])), 'v', 60)],
    'flush' => [static fn (VersionCounterLabelIndex $index): null => $index->flush('table:core_users')],
])->throws(LabelIndexUnavailableException::class);

it('never reads a lost counter as a version an entry was stored under', function (): void {
    $cache = new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore());
    $index = new VersionCounterLabelIndex($cache);
    $dependencies = new CacheDependencies(['table:core_users']);

    $index->store($index->lookup('k', $dependencies), 'A', 60);
    $index->flush('table:core_users');
    $index->store($index->lookup('k', $dependencies), 'B', 60);
    // Evicted, lost with the node that held it, or a deleted `database` row: the counter restarts at a fresh seed.
    $cache->forget('labelv:table:core_users');
    $this->travel(1)->seconds();

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
});

it('starts a missing counter at a seed taken from the clock, at the lookup and at the flush', function (): void {
    $this->freezeTime();
    $cache = new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore());
    $index = new VersionCounterLabelIndex($cache);
    $seed = Modules\Core\Cache\Labels\Clock::micros();

    $lookup = $index->lookup('k', new CacheDependencies(['table:core_users']));
    $index->flush('table:core_roles');

    expect($lookup->key)->toBe('k|table:core_users=' . $seed)
        ->and($cache->get('labelv:table:core_users'))->toBe($seed)
        ->and($cache->get('labelv:table:core_roles'))->toBe($seed);
});

it('accepts a write memcached buffers, with OPT_BUFFER_WRITES on', function (): void {
    $memcached = Mockery::mock(Memcached::class);
    $memcached->shouldReceive('set')->andReturn(true);
    $memcached->shouldReceive('add')->andReturn(true);
    $memcached->shouldReceive('getResultCode')->andReturn(Memcached::RES_BUFFERED);
    $index = new VersionCounterLabelIndex(new Modules\Core\Cache\Repository(new Illuminate\Cache\MemcachedStore($memcached)));

    $index->flush('table:core_users');

    expect($index->store(new Modules\Core\Cache\Labels\CacheLookup('k', false, null, 1, new CacheDependencies(['t'])), 'v', 60))->toBeTrue();
});
