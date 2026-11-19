<?php

declare(strict_types=1);

use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Modules\Core\Tests\Stubs\Geocoding\CountingGeocodingService;

it('serves a geocoding search from the index on the second read and recomputes it after the geocoding label is flushed', function (): void {
    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $service = new CountingGeocodingService();

    $service->search('Piazza Duomo', 'Milano');
    $service->search('Piazza Duomo', 'Milano');

    expect($service->searches)->toBe(1)
        ->and($index->stores)->toHaveCount(1)
        ->and($index->stores[0]['lookup']->dependencies->labels)->toBe(['geocoding'])
        ->and($index->stores[0]['ttl'])->toBe((int) config('cache.duration.long'));

    $index->flush('geocoding');
    $service->search('Piazza Duomo', 'Milano');

    expect($service->searches)->toBe(2);
});
