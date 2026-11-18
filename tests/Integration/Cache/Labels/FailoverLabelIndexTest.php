<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Tests\Stubs\Cache\ThrowingLabelIndex;

function failoverMarkerStore(): Repository
{
    static $dir = null;
    $dir ??= sys_get_temp_dir() . '/label-marker-' . bin2hex(random_bytes(4));
    $GLOBALS['label_marker_dir'] = $dir;

    return Cache::build(['driver' => 'file', 'path' => $dir]);
}

afterEach(function (): void {
    if (isset($GLOBALS['label_marker_dir']) && is_dir($GLOBALS['label_marker_dir'])) {
        Illuminate\Support\Facades\File::deleteDirectory($GLOBALS['label_marker_dir']);
    }
});

it('misses and stores nothing while the primary throws', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());
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
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());
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
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());
    $lookup = $index->lookup('k', new CacheDependencies(['table:core_users']));
    $primary->failing = false;

    expect($index->store($lookup, 'v', 60))->toBeFalse()
        ->and($primary->calls)->toBe(['flush:crud']);
});

it('shares the outage marker between instances', function (): void {
    $marker = failoverMarkerStore();
    $failing = new ThrowingLabelIndex();
    $failing->failing = true;
    new FailoverLabelIndex($failing, $marker)->lookup('k', new CacheDependencies(['t']));

    $healthy = new ThrowingLabelIndex();
    $fresh = new FailoverLabelIndex($healthy, $marker);
    $fresh->lookup('k', new CacheDependencies(['t']));

    expect($healthy->calls)->toBe(['flush:crud', 'lookup'])
        ->and($marker->get(FailoverLabelIndex::MARKER_KEY))->toBeNull();
});

it('keeps the marker when the recovery flush throws', function (): void {
    $marker = failoverMarkerStore();
    $marker->forever(FailoverLabelIndex::MARKER_KEY, true);
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;

    $lookup = new FailoverLabelIndex($primary, $marker)->lookup('k', new CacheDependencies(['t']));

    expect($lookup->hit)->toBeFalse()
        ->and($marker->get(FailoverLabelIndex::MARKER_KEY))->toBeTrue();
});

it('lets non-outage exceptions propagate', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failure = new TypeError('bug');
    $primary->failing = true;

    new FailoverLabelIndex($primary, failoverMarkerStore())->lookup('k', new CacheDependencies(['t']));
})->throws(TypeError::class);

it('reports only the first failure of an outage', function (): void {
    Exceptions::fake();
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());

    $index->lookup('a', new CacheDependencies(['t']));
    $index->lookup('b', new CacheDependencies(['t']));

    Exceptions::assertReportedCount(1);
});
