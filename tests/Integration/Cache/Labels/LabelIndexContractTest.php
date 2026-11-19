<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\NullLabelIndex;
use Modules\Core\Cache\Labels\VersionCounterLabelIndex;
use Modules\Core\Cache\Repository;

dataset('label indexes', [
    'array' => [fn () => new ArrayLabelIndex()],
    'counters' => [fn () => new VersionCounterLabelIndex(new Repository(new ArrayStore()))],
    // A store that does not increment a missing key: the first flush of a label must still move its counter.
    'counters on a database store' => [fn () => new VersionCounterLabelIndex(new Repository(new DatabaseStore(app('db')->connection(), 'cache')))],
]);

foreach (require __DIR__ . '/../../../Support/LabelIndexContractCases.php' as $name => $case) {
    it($name, $case)->with('label indexes');
}

it('never hits with the null index', function (): void {
    $index = new NullLabelIndex();
    $dependencies = new CacheDependencies(['table:core_users']);

    $index->store($index->lookup('k', $dependencies), 'v', 60);

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse()
        ->and($index->supportsRowLabels())->toBeFalse()
        ->and($index->pruneStale())->toBe(0);
});

it('moves a table counter once for its table, row and rows labels flushed together', function (): void {
    $cache = new Repository(new ArrayStore());
    $index = new VersionCounterLabelIndex($cache);

    $index->flush('table:core_users', 'row:core_users:5', 'rows:core_users');

    expect($cache->get('labelv:table:core_users'))->toBe(1)
        ->and($cache->get('labelv:rows:core_users'))->toBeNull();
});

it('creates a missing counter through the atomic add of the store, with a ttl longer than any entry', function (): void {
    $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $cache->shouldReceive('increment')->once()->with('labelv:table:core_users')->andReturn(false);
    $cache->shouldReceive('add')->once()->with('labelv:table:core_users', 1, Mockery::on(static fn (mixed $ttl): bool => is_int($ttl) && $ttl >= 86400 * 365))->andReturn(true);

    new VersionCounterLabelIndex($cache)->flush('table:core_users');
});
