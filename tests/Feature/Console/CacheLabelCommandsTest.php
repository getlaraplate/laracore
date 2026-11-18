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
