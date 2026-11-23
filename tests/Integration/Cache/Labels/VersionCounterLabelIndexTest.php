<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Labels\LabelIndexUnavailableException;
use Modules\Core\Cache\Labels\VersionCounterLabelIndex;

it('throws when the store can neither increment nor create a counter, as a memcached server that is down', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('increment')->twice()->andReturn(false);
    $cache->shouldReceive('add')->once()->andReturn(false);

    new VersionCounterLabelIndex($cache)->flush('table:core_users');
})->throws(LabelIndexUnavailableException::class);

it('increments a counter another flush created in between without failing', function (): void {
    $cache = Mockery::mock(Repository::class);
    $cache->shouldReceive('increment')->twice()->andReturn(false, 2);
    $cache->shouldReceive('add')->once()->andReturn(false);

    new VersionCounterLabelIndex($cache)->flush('table:core_users');
});

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
    $cache->shouldReceive('get')->andReturn(null);

    expect(new VersionCounterLabelIndex($cache)->lookup('k', new CacheDependencies(['t']))->hit)->toBeFalse();
});
