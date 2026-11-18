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
