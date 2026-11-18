<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Tests\Stubs\Cache\ThrowingLabelIndex;

it('misses and stores nothing while the primary throws', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary);
    $dependencies = new CacheDependencies(['table:core_users']);

    $lookup = $index->lookup('k', $dependencies);

    expect($lookup->hit)->toBeFalse()
        ->and($index->store($lookup, 'v', 60))->toBeFalse();

    $index->flush('table:core_users');
    $index->forget($lookup);

    expect($index->pruneStale())->toBe(0)
        ->and($primary->calls)->toBe([]);
});

it('flushes crud on the primary before the first call after recovery', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary);
    $dependencies = new CacheDependencies(['table:core_users']);

    $index->lookup('k', $dependencies);
    $primary->failing = false;

    $lookup = $index->lookup('k', $dependencies);

    expect($lookup->hit)->toBeTrue()
        ->and($primary->calls)->toBe(['flush:crud', 'lookup']);

    $index->lookup('k', $dependencies);

    expect($primary->calls)->toBe(['flush:crud', 'lookup', 'lookup']);
});

it('does not store a value computed during the outage once the primary is back', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary);
    $lookup = $index->lookup('k', new CacheDependencies(['table:core_users']));
    $primary->failing = false;

    expect($index->store($lookup, 'v', 60))->toBeFalse()
        ->and($primary->calls)->toBe(['flush:crud']);
});
