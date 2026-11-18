<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\NullLabelIndex;
use Modules\Core\Cache\Labels\VersionCounterLabelIndex;
use Modules\Core\Cache\Repository;

dataset('label indexes', [
    'array' => [fn () => new ArrayLabelIndex()],
    'counters' => [fn () => new VersionCounterLabelIndex(new Repository(new ArrayStore()))],
]);

it('misses, stores, then hits', function (Closure $make): void {
    $index = $make();
    $dependencies = new CacheDependencies(['table:core_users', 'crud']);

    $lookup = $index->lookup('k', $dependencies);
    expect($lookup->hit)->toBeFalse();

    expect($index->store($lookup, ['a' => 1], 60))->toBeTrue();

    $again = $index->lookup('k', $dependencies);
    expect($again->hit)->toBeTrue()->and($again->value)->toBe(['a' => 1]);
})->with('label indexes');

it('misses after one of its labels is flushed', function (Closure $make): void {
    $index = $make();
    $dependencies = new CacheDependencies(['table:core_users', 'crud']);
    $index->store($index->lookup('k', $dependencies), 'v', 60);

    $index->flush('table:core_users');

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
})->with('label indexes');

it('keeps the entry when an unrelated label is flushed', function (Closure $make): void {
    $index = $make();
    $dependencies = new CacheDependencies(['table:core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 60);

    $index->flush('table:core_roles');

    expect($index->lookup('k', $dependencies)->hit)->toBeTrue();
})->with('label indexes');

it('stores nothing usable when a label was flushed after the lookup', function (Closure $make): void {
    $index = $make();
    $dependencies = new CacheDependencies(['table:core_users']);

    $lookup = $index->lookup('k', $dependencies);
    $index->flush('table:core_users');
    $index->store($lookup, 'stale', 60);

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
})->with('label indexes');

it('misses after its row label is flushed', function (Closure $make): void {
    $index = $make();
    $dependencies = new CacheDependencies(['table:core_users'], ['core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

    $index->flush('row:core_users:5');

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
})->with('label indexes');

it('misses once the ttl passed', function (Closure $make): void {
    $index = $make();
    $dependencies = new CacheDependencies(['table:core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 60);

    $this->travel(61)->seconds();

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
})->with('label indexes');

it('keeps the entry when an unrelated row is flushed', function (Closure $make): void {
    $index = $make();

    if (! $index->supportsRowLabels()) {
        $this->markTestSkipped('Membership indexes only.');
    }
    $dependencies = new CacheDependencies(['table:core_users'], ['core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

    $index->flush('row:core_users:7');

    expect($index->lookup('k', $dependencies)->hit)->toBeTrue();
})->with('label indexes');

it('prunes expired memberships', function (Closure $make): void {
    $index = $make();

    if (! $index->supportsRowLabels()) {
        $this->markTestSkipped('Membership indexes only.');
    }
    $dependencies = new CacheDependencies(['table:core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

    $this->travel(61)->seconds();

    expect($index->pruneStale())->toBe(1);
})->with('label indexes');

it('never hits with the null index', function (): void {
    $index = new NullLabelIndex();
    $dependencies = new CacheDependencies(['table:core_users']);

    $index->store($index->lookup('k', $dependencies), 'v', 60);

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse()
        ->and($index->supportsRowLabels())->toBeFalse()
        ->and($index->pruneStale())->toBe(0);
});
