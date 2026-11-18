<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Tests\Stubs\Cache\RedisLabelIndexFactory;

afterEach(fn () => RedisLabelIndexFactory::cleanup());

it('flushes a label with more members than one chunk', function (): void {
    $index = RedisLabelIndexFactory::make();
    $dependencies = new CacheDependencies(['table:core_users']);

    for ($i = 0; $i < 2500; $i++) {
        $index->store($index->lookup('k' . $i, $dependencies), $i, 60);
    }

    expect($index->lookup('k0', $dependencies)->hit)->toBeTrue()
        ->and($index->lookup('k2499', $dependencies)->hit)->toBeTrue();

    $index->flush('table:core_users');

    expect($index->lookup('k0', $dependencies)->hit)->toBeFalse()
        ->and($index->lookup('k1250', $dependencies)->hit)->toBeFalse()
        ->and($index->lookup('k2499', $dependencies)->hit)->toBeFalse();
})->group('redis');

it('round-trips arrays, objects, numbers and strings', function (): void {
    $index = RedisLabelIndexFactory::make();
    $dependencies = new CacheDependencies(['crud']);
    $values = ['array' => ['a' => [1, 2], 'b' => null], 'int' => 42, 'float' => 1.5, 'string' => 'hello', 'object' => new ArrayObject([1])];

    foreach ($values as $key => $value) {
        $index->store($index->lookup($key, $dependencies), $value, 60);
        $hit = $index->lookup($key, $dependencies);

        expect($hit->hit)->toBeTrue();
        expect($hit->value)->toEqual($value);
    }
})->group('redis');

it('misses once the redis ttl passed', function (): void {
    $index = RedisLabelIndexFactory::make();
    $dependencies = new CacheDependencies(['table:core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 1);

    usleep(1_200_000);

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
})->group('redis');

it('prunes expired memberships once per entry', function (): void {
    $index = RedisLabelIndexFactory::make();
    $dependencies = new CacheDependencies(['table:core_users']);
    $index->store($index->lookup('k', $dependencies), 'v', 1, ['row:core_users:5']);
    $index->store($index->lookup('long', $dependencies), 'v', 60);

    usleep(1_200_000);

    expect($index->pruneStale())->toBe(1)
        ->and($index->pruneStale())->toBe(0)
        ->and($index->lookup('long', $dependencies)->hit)->toBeTrue();
})->group('redis');

it('forgets a single entry', function (): void {
    $index = RedisLabelIndexFactory::make();
    $dependencies = new CacheDependencies(['table:core_users']);
    $lookup = $index->lookup('k', $dependencies);
    $index->store($lookup, 'v', 60);

    $index->forget($lookup);

    expect($index->lookup('k', $dependencies)->hit)->toBeFalse();
})->group('redis');

it('reports row label support', function (): void {
    expect(RedisLabelIndexFactory::make()->supportsRowLabels())->toBeTrue();
})->group('redis');
