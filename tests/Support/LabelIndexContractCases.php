<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\RedisLabelIndex;

/**
 * Cases every label index must pass, keyed by test name. Each closure receives a factory
 * and is bound to the Pest test case by the file that registers it.
 *
 * @return array<string, Closure(Closure): void>
 */
return [
    'misses, stores, then hits' => function (Closure $make): void {
        $index = $make();
        $dependencies = new CacheDependencies(['table:core_users', 'crud']);

        $lookup = $index->lookup('k', $dependencies);
        expect($lookup->hit)->toBeFalse();

        expect($index->store($lookup, ['a' => 1], 60))->toBeTrue();

        $again = $index->lookup('k', $dependencies);
        expect($again->hit)->toBeTrue()->and($again->value)->toBe(['a' => 1]);
    },
    'misses after one of its labels is flushed' => function (Closure $make): void {
        $index = $make();
        $dependencies = new CacheDependencies(['table:core_users', 'crud']);
        $index->store($index->lookup('k', $dependencies), 'v', 60);

        $index->flush('table:core_users');

        expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
    },
    'keeps the entry when an unrelated label is flushed' => function (Closure $make): void {
        $index = $make();
        $dependencies = new CacheDependencies(['table:core_users']);
        $index->store($index->lookup('k', $dependencies), 'v', 60);

        $index->flush('table:core_roles');

        expect($index->lookup('k', $dependencies)->hit)->toBeTrue();
    },
    'stores nothing usable when a label was flushed after the lookup' => function (Closure $make): void {
        $index = $make();
        $dependencies = new CacheDependencies(['table:core_users']);

        $lookup = $index->lookup('k', $dependencies);
        $index->flush('table:core_users');
        $index->store($lookup, 'stale', 60);

        expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
    },
    'misses after its row label is flushed' => function (Closure $make): void {
        $index = $make();
        $dependencies = new CacheDependencies(['table:core_users'], ['core_users']);
        $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

        $index->flush('row:core_users:5');

        expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
    },
    'misses once the ttl passed' => function (Closure $make): void {
        $index = $make();

        if ($index instanceof RedisLabelIndex) {
            $this->markTestSkipped('Redis expires on the server clock; see RedisLabelIndexTest.');
        }
        $dependencies = new CacheDependencies(['table:core_users']);
        $index->store($index->lookup('k', $dependencies), 'v', 60);

        $this->travel(61)->seconds();

        expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
    },
    'keeps the entry when an unrelated row is flushed' => function (Closure $make): void {
        $index = $make();

        if (! $index->supportsRowLabels()) {
            $this->markTestSkipped('Membership indexes only.');
        }
        $dependencies = new CacheDependencies(['table:core_users'], ['core_users']);
        $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

        $index->flush('row:core_users:7');

        expect($index->lookup('k', $dependencies)->hit)->toBeTrue();
    },
    'prunes expired memberships' => function (Closure $make): void {
        $index = $make();

        if ($index instanceof RedisLabelIndex) {
            $this->markTestSkipped('Redis expires on the server clock; see RedisLabelIndexTest.');
        }

        if (! $index->supportsRowLabels()) {
            $this->markTestSkipped('Membership indexes only.');
        }
        $dependencies = new CacheDependencies(['table:core_users']);
        $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

        $this->travel(61)->seconds();

        expect($index->pruneStale())->toBe(1);
    },
    'hits after a flush, lookup and store under a frozen clock' => function (Closure $make): void {
        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::now());

        try {
            $index = $make();
            $dependencies = new CacheDependencies(['table:core_users']);

            $index->flush('table:core_users');
            $index->store($index->lookup('k', $dependencies), 'v', 60);

            expect($index->lookup('k', $dependencies)->hit)->toBeTrue();
        } finally {
            Illuminate\Support\Carbon::setTestNow();
        }
    },
    'misses after the whole table of its row label is flushed' => function (Closure $make): void {
        $index = $make();
        $dependencies = new CacheDependencies(['crud'], ['core_users']);
        $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

        $index->flush('table:core_users', 'rows:core_users');

        expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
    },
    'keeps an entry with a row of the table when only the table label is flushed' => function (Closure $make): void {
        $index = $make();

        if (! $index->supportsRowLabels()) {
            $this->markTestSkipped('Membership indexes only: counters turn a row table into its table label.');
        }
        $dependencies = new CacheDependencies(['crud'], ['core_users']);
        $index->store($index->lookup('k', $dependencies), 'v', 60, ['row:core_users:5']);

        $index->flush('table:core_users');

        expect($index->lookup('k', $dependencies)->hit)->toBeTrue();
    },
    'reaches an entry stored after a flush with the next flush' => function (Closure $make): void {
        $index = $make();
        $dependencies = new CacheDependencies(['table:core_users']);
        $index->store($index->lookup('old', $dependencies), 'v', 60);
        $index->flush('table:core_users');

        expect($index->store($index->lookup('fresh', $dependencies), 'v', 60))->toBeTrue();

        $index->flush('table:core_users');

        expect($index->lookup('fresh', $dependencies)->hit)->toBeFalse();
    },
];
