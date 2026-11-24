<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\MeasuredLabelIndex;
use Modules\Core\Contracts\ICacheLabelIndex;

it('prints the pruned count', function (): void {
    $index = new ArrayLabelIndex();
    $this->app->instance(ICacheLabelIndex::class, $index);
    $dependencies = new CacheDependencies(['table:core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 60);
    $this->travel(2)->minutes();

    $this->artisan('cache:prune-labels')->expectsOutputToContain('Pruned 1 cache entrie')->assertSuccessful();
});

it('lists hits, misses and flushes per label', function (): void {
    $stats = Cache::store('array');
    $stats->flush();
    $index = new MeasuredLabelIndex(new ArrayLabelIndex(), $stats);
    $this->app->instance(ICacheLabelIndex::class, $index);
    $dependencies = new CacheDependencies(['table:core_users', 'crud']);

    $lookup = $index->lookup('k', $dependencies);
    $index->store($lookup, 'v', 60);
    $index->lookup('k', $dependencies);
    $index->lookup('k', $dependencies);
    $index->flush('table:core_users');

    expect($index->report())->toBe(['table:core_users' => ['hits' => 2, 'misses' => 1, 'flushes' => 1]]);

    $this->artisan('cache:label-stats')->expectsOutputToContain('table:core_users')->assertSuccessful();
});

it('touches the label list only the first time a label is counted', function (): void {
    $stats = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $stats->shouldReceive('add')->twice()->andReturn(true, false);
    $stats->shouldReceive('increment')->twice();
    $stats->shouldReceive('get')->once()->with('labelstats:labels', [])->andReturn([]);
    $stats->shouldReceive('put')->once();
    $index = new MeasuredLabelIndex(new ArrayLabelIndex(), $stats);
    $dependencies = new CacheDependencies(['table:core_users']);

    $index->lookup('k', $dependencies);
    $index->lookup('k', $dependencies);
});

it('counts the flushes of every row of a table and of every user under one label each', function (): void {
    $stats = Cache::store('array');
    $stats->flush();
    $index = new MeasuredLabelIndex(new ArrayLabelIndex(), $stats);

    $index->flush('table:core_users', 'row:core_users:1');
    $index->flush('row:core_users:2', 'user:1', 'user:2');

    expect($index->report())->toBe([
        'row:core_users:*' => ['hits' => 0, 'misses' => 0, 'flushes' => 2],
        'table:core_users' => ['hits' => 0, 'misses' => 0, 'flushes' => 1],
        'user:*' => ['hits' => 0, 'misses' => 0, 'flushes' => 1],
    ]);
});

it('never fails the read or write it measures when the stats store throws', function (): void {
    Illuminate\Support\Facades\Exceptions::fake();
    $stats = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $stats->shouldReceive('add')->once()->andThrow(new RuntimeException('stats store down'));
    $inner = new ArrayLabelIndex();
    $index = new MeasuredLabelIndex($inner, $stats);
    $dependencies = new CacheDependencies(['table:core_users']);

    $lookup = $index->lookup('k', $dependencies);
    $index->store($lookup, 'v', 60);
    $index->flush('row:core_users:1');

    expect($inner->lookup('k', $dependencies)->hit)->toBeTrue();
    Illuminate\Support\Facades\Exceptions::assertReportedCount(1);
});

it('counts nothing while the guarded index is degraded', function (): void {
    $primary = new Modules\Core\Tests\Stubs\Cache\ThrowingLabelIndex();
    $primary->failing = true;
    $marker = new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore());
    $stats = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $stats->shouldNotReceive('add');
    $index = new MeasuredLabelIndex(new Modules\Core\Cache\Labels\FailoverLabelIndex($primary, $marker), $stats);

    $index->lookup('k', new CacheDependencies(['table:core_users']));
    $index->flush('table:core_users');
});

it('stops counting for a minute after a stats failure, then counts again', function (): void {
    Illuminate\Support\Facades\Exceptions::fake();
    $this->freezeTime();
    $stats = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $stats->shouldReceive('add')->once()->ordered()->andThrow(new RuntimeException('stats store down'));
    $stats->shouldReceive('add')->once()->ordered()->andReturn(false);
    $stats->shouldReceive('increment')->once();
    $index = new MeasuredLabelIndex(new ArrayLabelIndex(), $stats);
    $dependencies = new CacheDependencies(['table:core_users']);

    $index->lookup('k', $dependencies);
    $this->travel(59)->seconds();
    $index->lookup('k', $dependencies);
    $this->travel(1)->seconds();
    $index->lookup('k', $dependencies);

    Illuminate\Support\Facades\Exceptions::assertReportedCount(1);
});

/**
 * The measured Redis index of production, on a connection that answers INFO memory and CONFIG GET with the replies
 * given (an exception is thrown).
 */
function cacheLabelCommandsRedisIndex(mixed $info, mixed $config): MeasuredLabelIndex
{
    $connection = Mockery::mock(Illuminate\Redis\Connections\PhpRedisConnection::class);
    $connection->shouldReceive('command')->with('info', ['memory'])->andReturnUsing(
        static fn (): mixed => $info instanceof Throwable ? throw $info : $info,
    );
    $connection->shouldReceive('command')->with('config', ['get', 'maxmemory-policy'])->andReturnUsing(
        static fn (): mixed => $config instanceof Throwable ? throw $config : $config,
    );
    $store = Mockery::mock(Illuminate\Cache\RedisStore::class);
    $store->shouldReceive('connection')->andReturn($connection);
    $store->shouldReceive('getPrefix')->andReturn('');
    $marker = new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore());
    $stats = Cache::store('array');
    $stats->flush();

    return new MeasuredLabelIndex(new Modules\Core\Cache\Labels\FailoverLabelIndex(new Modules\Core\Cache\Labels\RedisLabelIndex($store), $marker), $stats);
}

it('warns when the Redis of the label index may evict its keys', function (mixed $info, mixed $config, string $policy): void {
    $this->app->instance(ICacheLabelIndex::class, cacheLabelCommandsRedisIndex($info, $config));

    $this->artisan('cache:label-stats')
        ->expectsOutputToContain('maxmemory-policy [' . $policy . ']')
        ->assertSuccessful();
})->with([
    'from INFO memory (phpredis)' => [['maxmemory_policy' => 'allkeys-lru'], null, 'allkeys-lru'],
    'from INFO memory (Predis)' => [['Memory' => ['maxmemory_policy' => 'volatile-ttl']], null, 'volatile-ttl'],
    'from CONFIG GET (phpredis)' => [[], ['maxmemory-policy' => 'volatile-lru'], 'volatile-lru'],
    'from CONFIG GET (list reply)' => [false, ['maxmemory-policy', 'allkeys-random'], 'allkeys-random'],
]);

it('does not warn when Redis evicts nothing or the policy cannot be read', function (mixed $info, mixed $config): void {
    $this->app->instance(ICacheLabelIndex::class, cacheLabelCommandsRedisIndex($info, $config));

    $this->artisan('cache:label-stats')
        ->doesntExpectOutputToContain('maxmemory-policy')
        ->assertSuccessful();
})->with([
    'noeviction' => [['maxmemory_policy' => 'noeviction'], null],
    'CONFIG refused' => [[], new RuntimeException('ERR unknown command CONFIG')],
    'CONFIG answering nothing' => [[], false],
    'no memory limit' => [['maxmemory' => '0', 'maxmemory_policy' => 'allkeys-lru'], null],
    'no memory limit (integer)' => [['maxmemory' => 0, 'maxmemory_policy' => 'volatile-lru'], null],
    'INFO and CONFIG refused' => [new RedisException('ERR unknown command INFO'), new RuntimeException('ERR unknown command CONFIG')],
]);

it('does not degrade the failover index when Redis refuses INFO and CONFIG', function (): void {
    $connection = Mockery::mock(Illuminate\Redis\Connections\PhpRedisConnection::class);
    $connection->shouldReceive('command')->with('info', ['memory'])->andThrow(new RedisException('ERR unknown command INFO'));
    $connection->shouldReceive('command')->with('config', ['get', 'maxmemory-policy'])->andThrow(new RedisException('ERR unknown command CONFIG'));
    $store = Mockery::mock(Illuminate\Cache\RedisStore::class);
    $store->shouldReceive('connection')->andReturn($connection);
    $store->shouldReceive('getPrefix')->andReturn('');
    $index = new Modules\Core\Cache\Labels\FailoverLabelIndex(
        new Modules\Core\Cache\Labels\RedisLabelIndex($store),
        new Modules\Core\Cache\Repository(new Illuminate\Cache\ArrayStore()),
    );

    expect($index->evictionPolicy())->toBeNull()
        ->and($index->isDegraded())->toBeFalse();
});

it('logs the eviction warning when it prunes, the scheduled run reporting it once an hour', function (): void {
    $this->app->instance(ICacheLabelIndex::class, new Modules\Core\Tests\Stubs\Cache\EvictionPolicyLabelIndex('allkeys-lru'));
    Illuminate\Support\Facades\Log::spy();

    $this->artisan('cache:prune-labels')->expectsOutputToContain('allkeys-lru')->assertSuccessful();

    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
});

it('logs nothing when it prunes a store that evicts nothing or cannot tell', function (?string $policy): void {
    $this->app->instance(ICacheLabelIndex::class, new Modules\Core\Tests\Stubs\Cache\EvictionPolicyLabelIndex($policy));
    Illuminate\Support\Facades\Log::spy();

    $this->artisan('cache:prune-labels')->assertSuccessful();

    Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
})->with(['noeviction' => 'noeviction', 'unknown' => null]);
