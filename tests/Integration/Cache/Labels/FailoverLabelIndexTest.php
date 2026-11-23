<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
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

it('flushes the label of every stored entry on the primary before the first call after recovery', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());
    $dependencies = new CacheDependencies(['table:core_users']);

    $index->lookup('k', $dependencies);
    $primary->failing = false;
    $this->travel(2)->seconds();

    $lookup = $index->lookup('k', $dependencies);

    expect($lookup->hit)->toBeTrue()
        ->and($primary->calls)->toBe(['flush:' . CacheLabel::stored(), 'lookup']);

    $index->lookup('k', $dependencies);

    expect($primary->calls)->toBe(['flush:' . CacheLabel::stored(), 'lookup', 'lookup']);
});

it('stores every entry under the label flushed on recovery', function (): void {
    $primary = new ThrowingLabelIndex();
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());

    $lookup = $index->lookup('k', new CacheDependencies(['acl', 'user:5']));

    expect($primary->dependencies[0]->labels)->toBe(['acl', 'user:5', CacheLabel::stored()])
        ->and($lookup->dependencies->labels)->toContain(CacheLabel::stored());
});

it('makes an ACL resolution stored before an outage miss after the recovery', function (): void {
    $primary = new ArrayLabelIndex();
    $marker = failoverMarkerStore();
    $index = new FailoverLabelIndex($primary, $marker);
    $dependencies = new CacheDependencies([CacheLabel::acl(), CacheLabel::user(5)]);
    $index->store($index->lookup('acl:5', $dependencies), 'resolution', 60);

    // An outage seen by this host: the flush of `acl` issued meanwhile never reached the primary.
    $marker->forever(FailoverLabelIndex::MARKER_KEY, 0);

    expect(new FailoverLabelIndex($primary, $marker)->lookup('acl:5', $dependencies)->hit)->toBeFalse();
});

it('does not store a value computed during the outage once the primary is back', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());
    $lookup = $index->lookup('k', new CacheDependencies(['table:core_users']));
    $primary->failing = false;
    $this->travel(2)->seconds();

    expect($index->store($lookup, 'v', 60))->toBeFalse()
        ->and($primary->calls)->toBe(['flush:' . CacheLabel::stored()]);
});

it('tries the primary at most once a second while it is down', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());
    $dependencies = new CacheDependencies(['t']);

    $lookup = $index->lookup('a', $dependencies);
    $index->lookup('b', $dependencies);
    $index->store($lookup, 'v', 60);
    $index->flush('t');
    $index->pruneStale();

    expect($primary->attempts)->toBe(1);

    $this->travel(2)->seconds();
    $index->lookup('c', $dependencies);

    expect($primary->attempts)->toBe(2);
});

it('waits for the interval after a failed attempt of another worker on the same host', function (): void {
    $marker = failoverMarkerStore();
    $failing = new ThrowingLabelIndex();
    $failing->failing = true;
    new FailoverLabelIndex($failing, $marker)->lookup('k', new CacheDependencies(['t']));

    $primary = new ThrowingLabelIndex();
    $primary->failing = true;
    new FailoverLabelIndex($primary, $marker)->lookup('k', new CacheDependencies(['t']));

    expect($primary->attempts)->toBe(0);
});

it('shares the outage marker between instances', function (): void {
    $marker = failoverMarkerStore();
    $failing = new ThrowingLabelIndex();
    $failing->failing = true;
    new FailoverLabelIndex($failing, $marker)->lookup('k', new CacheDependencies(['t']));
    $this->travel(2)->seconds();

    $healthy = new ThrowingLabelIndex();
    $fresh = new FailoverLabelIndex($healthy, $marker);
    $fresh->lookup('k', new CacheDependencies(['t']));

    expect($healthy->calls)->toBe(['flush:' . CacheLabel::stored(), 'lookup'])
        ->and($marker->get(FailoverLabelIndex::MARKER_KEY))->toBeNull();
});

it('keeps the marker when the recovery flush throws', function (): void {
    $marker = failoverMarkerStore();
    $marker->forever(FailoverLabelIndex::MARKER_KEY, true);
    $primary = new ThrowingLabelIndex();
    $primary->failing = true;

    $lookup = new FailoverLabelIndex($primary, $marker)->lookup('k', new CacheDependencies(['t']));

    expect($lookup->hit)->toBeFalse()
        ->and($primary->attempts)->toBe(1)
        ->and($marker->get(FailoverLabelIndex::MARKER_KEY))->not->toBeNull();
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
    $this->travel(2)->seconds();
    $index->lookup('b', new CacheDependencies(['t']));

    expect($primary->attempts)->toBe(2);
    Exceptions::assertReportedCount(1);
});

it('degrades when a database primary loses its connection', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failure = new Illuminate\Database\QueryException(
        'mysql',
        'select * from cache',
        [],
        new PDOException('SQLSTATE[HY000] [2002] Connection refused'),
    );
    $primary->failing = true;
    $index = new FailoverLabelIndex($primary, failoverMarkerStore());

    $lookup = $index->lookup('k', new CacheDependencies(['t']));
    $index->flush('t');

    expect($lookup->hit)->toBeFalse()
        ->and($index->isDegraded())->toBeTrue();
});

it('lets a database error that is not a lost connection propagate', function (): void {
    $primary = new ThrowingLabelIndex();
    $primary->failure = new Illuminate\Database\QueryException(
        'mysql',
        'select * from cache',
        [],
        new PDOException('SQLSTATE[42S02]: Base table or view not found: 1146 Table cache does not exist'),
    );
    $primary->failing = true;

    new FailoverLabelIndex($primary, failoverMarkerStore())->lookup('k', new CacheDependencies(['t']));
})->throws(Illuminate\Database\QueryException::class);
