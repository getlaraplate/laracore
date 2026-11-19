<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\RedisLabelIndex;
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

it('refuses a store whose lookup started more than the store window ago', function (): void {
    $index = new RedisLabelIndex(RedisLabelIndexFactory::store(), 1);
    $dependencies = new CacheDependencies(['table:core_users']);
    $late = $index->lookup('late', $dependencies);

    usleep(1_200_000);

    expect($index->store($late, 'v', 60))->toBeFalse()
        ->and($index->lookup('late', $dependencies)->hit)->toBeFalse()
        ->and($index->store($index->lookup('late', $dependencies), 'v', 60))->toBeTrue();
})->group('redis');

it('keeps a flush record for the store window only', function (): void {
    $store = RedisLabelIndexFactory::store();
    new RedisLabelIndex($store, 5)->flush('table:core_users');

    $ttl = (int) $store->connection()->pttl($store->getPrefix() . 'labelflush:table:core_users');

    // One second of margin over the window, so a record outlives every store the window lets through.
    expect($ttl)->toBeGreaterThan(5_000)->toBeLessThanOrEqual(6_000);
})->group('redis');

it('flushes several labels holding more members than one script deletes', function (): void {
    $store = RedisLabelIndexFactory::store();
    $index = new RedisLabelIndex($store);
    $labels = ['table:core_users', 'table:core_roles', 'row:core_users:5'];
    $prefix = $store->getPrefix();
    $expires_at = (string) ((time() + 600) * 1_000_000);
    // A member is the real key name, client prefix included, as the store script writes it.
    $client_prefix = (string) $store->connection()->client()->getOption(Redis::OPT_PREFIX);

    $store->connection()->pipeline(static function ($pipe) use ($labels, $prefix, $expires_at, $client_prefix): void {
        foreach ($labels as $position => $label) {
            for ($i = 0; $i < 1500; $i++) {
                $pipe->set($prefix . "k{$position}_{$i}", 'v');
                $pipe->zadd($prefix . 'label:' . $label, $expires_at, $client_prefix . $prefix . "k{$position}_{$i}");
            }
        }
    });

    $index->flush(...$labels);

    $connection = $store->connection();

    foreach ($labels as $position => $label) {
        expect($connection->exists($prefix . 'label:' . $label))->toBe(0)
            ->and($connection->exists($prefix . "k{$position}_0", $prefix . "k{$position}_1499"))->toBe(0);
    }
})->group('redis');
