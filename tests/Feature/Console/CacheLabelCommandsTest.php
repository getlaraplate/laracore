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
