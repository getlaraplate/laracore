<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Cache\Labels\FailoverLabelIndex;
use Modules\Core\Cache\Labels\MeasuredLabelIndex;
use Modules\Core\Cache\Labels\RedisLabelIndex;
use Modules\Core\Cache\Repository;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Role;
use Modules\Core\Tests\Stubs\Cache\RedisLabelIndexFactory;

/**
 * Round trips a write pays on the production stack (stats, failover guard, Redis index), counted per Redis command
 * sent on the `cache` connection. Each command is one round trip: nothing is pipelined.
 */
beforeEach(function (): void {
    $store = RedisLabelIndexFactory::store();
    $this->index = new MeasuredLabelIndex(
        new FailoverLabelIndex(new RedisLabelIndex($store), new Repository(new ArrayStore())),
        new Repository($store),
    );
    app()->instance(ICacheLabelIndex::class, $this->index);
    Redis::connection('cache')->setEventDispatcher(app('events'));

    $this->roles = collect(range(1, 20))->map(
        static fn (int $i): Role => Role::query()->create(['name' => 'write_path_' . $i . '_' . uniqid(), 'guard_name' => 'web']),
    );

    // The first flush of a stats bucket also writes the label list once; the counts below are of the steady state.
    $this->roles->first()->update(['name' => 'warm_' . uniqid()]);
});

afterEach(fn () => RedisLabelIndexFactory::cleanup());

/**
 * @return array{index: int, stats: int}
 */
function redisRoundTripsOf(Closure $write): array
{
    $counts = ['index' => 0, 'stats' => 0];

    Event::listen(CommandExecuted::class, static function (CommandExecuted $event) use (&$counts): void {
        if ($event->connectionName !== 'cache') {
            return;
        }

        $is_stats = $event->command === 'incrby' || str_contains(json_encode($event->parameters) ?: '', 'labelstats:');
        $counts[$is_stats ? 'stats' : 'index']++;
    });

    $write();

    return $counts;
}

it('flushes one update outside a transaction in one index and one stats round trip', function (): void {
    $role = $this->roles->first();

    $counts = redisRoundTripsOf(static fn () => $role->update(['name' => 'renamed_' . uniqid()]));

    expect($counts)->toBe(['index' => 1, 'stats' => 1]);
})->group('redis');

it('flushes a transaction of twenty updates in one index and one stats round trip', function (): void {
    $connection = $this->roles->first()->getConnection();

    $counts = redisRoundTripsOf(fn () => $connection->transaction(function (): void {
        foreach ($this->roles as $role) {
            $role->update(['name' => 'renamed_' . uniqid()]);
        }
    }));

    expect($counts)->toBe(['index' => 1, 'stats' => 1]);
})->group('redis');

it('flushes a transaction of twenty creations in one index and one stats round trip', function (): void {
    $connection = $this->roles->first()->getConnection();

    $counts = redisRoundTripsOf(static fn () => $connection->transaction(static function (): void {
        foreach (range(1, 20) as $i) {
            Role::query()->create(['name' => 'created_' . $i . '_' . uniqid(), 'guard_name' => 'web']);
        }
    }));

    expect($counts)->toBe(['index' => 1, 'stats' => 1]);
})->group('redis');

it('flushes an update whose table and row labels hold cached entries in one index round trip', function (): void {
    $role = $this->roles->first();
    $table = $role->getTable();
    $dependencies = new Modules\Core\Cache\Labels\CacheDependencies([Modules\Core\Cache\Labels\CacheLabel::table($table)]);

    // Entries under the table label and under the row label of the role, well within one script's budget.
    foreach (range(1, 50) as $i) {
        $this->index->store($this->index->lookup('list:' . $i, $dependencies), 'list ' . $i, 60);
    }

    $detail = $this->index->lookup('detail', new Modules\Core\Cache\Labels\CacheDependencies([], row_tables: [$table]));
    $this->index->store($detail, 'detail', 60, [Modules\Core\Cache\Labels\CacheLabel::row($table, $role->getKey())]);

    $counts = redisRoundTripsOf(static fn () => $role->update(['name' => 'renamed_' . uniqid()]));

    expect($counts['index'])->toBe(1)
        ->and($this->index->lookup('list:1', $dependencies)->hit)->toBeFalse()
        ->and($this->index->lookup('detail', new Modules\Core\Cache\Labels\CacheDependencies([], row_tables: [$table]))->hit)->toBeFalse();
})->group('redis');

it('counts flushes and lookups on a Redis stats store', function (): void {
    $this->index->flush('table:core_users', 'row:core_users:1', 'row:core_users:2');
    $this->index->flush('table:core_users');
    $this->index->lookup('k', new Modules\Core\Cache\Labels\CacheDependencies(['table:core_users']));

    expect($this->index->report())
        ->toMatchArray([
            'table:core_users' => ['hits' => 0, 'misses' => 1, 'flushes' => 2],
            'row:core_users:*' => ['hits' => 0, 'misses' => 0, 'flushes' => 1],
        ]);
})->group('redis');
