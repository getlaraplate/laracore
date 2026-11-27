<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Repository;

it('returns bound cache store for default driver', function (): void {
    config(['cache.default' => 'array']);
    $manager = new CacheManager($this->app);

    $first = $manager->store();
    $second = $manager->store();

    expect($first)->toBeInstanceOf(Repository::class)
        ->and($second)->toBe($first)
        ->and(method_exists($first, 'clearByEntity'))->toBeTrue();
});

it('falls back to parent store for non-default driver', function (): void {
    config(['cache.default' => 'file']);
    $manager = new CacheManager($this->app);

    $store = $manager->store('array');

    expect($store)->toBeInstanceOf(Repository::class)
        ->and(method_exists($store, 'clearByEntity'))->toBeTrue();
});

it('creates core repository instances from store', function (): void {
    $manager = new CacheManager($this->app);

    $repository = $manager->repository(new ArrayStore(), ['driver' => 'array']);

    expect($repository)->toBeInstanceOf(Repository::class);
});

// Feature: performance-optimization, Property 7: Cache key format is consistent
it('generates cache key with app name prefix and namespace', function (): void {
    CacheManager::resetAppNameCache();
    config(['app.name' => 'laraplate']);

    $key = CacheManager::key('version_strategies');

    expect($key)->toBe('laraplate:version_strategies');
});

it('generates cache key with app name, namespace and multiple parts', function (): void {
    CacheManager::resetAppNameCache();
    config(['app.name' => 'laraplate']);

    $key = CacheManager::key('acl', 'user', '42', 'perm', '7');

    expect($key)->toBe('laraplate:acl:user:42:perm:7');
});

// Feature: performance-optimization, Property 7: Cache key format is consistent
// Validates: Requirements 6.1
it('generates key matching {app_name}:{namespace}:{parts} for any namespace and zero parts', function (): void {
    $app_name = fake()->word();
    $namespace = fake()->slug(fake()->numberBetween(1, 3));

    CacheManager::resetAppNameCache();
    config(['app.name' => $app_name]);

    $key = CacheManager::key($namespace);

    expect($key)->toBe("{$app_name}:{$namespace}");
})->repeat(20);

it('generates key matching {app_name}:{namespace}:{part} for any namespace and one part', function (): void {
    $app_name = fake()->word();
    $namespace = fake()->word();
    $part = (string) fake()->randomNumber();

    CacheManager::resetAppNameCache();
    config(['app.name' => $app_name]);

    $key = CacheManager::key($namespace, $part);

    expect($key)->toBe("{$app_name}:{$namespace}:{$part}");
})->repeat(20);

it('generates key matching {app_name}:{namespace}:{parts} for any namespace and two parts', function (): void {
    $app_name = fake()->word();
    $namespace = fake()->word();
    $part1 = (string) fake()->randomNumber();
    $part2 = fake()->word();

    CacheManager::resetAppNameCache();
    config(['app.name' => $app_name]);

    $key = CacheManager::key($namespace, $part1, $part2);

    expect($key)->toBe("{$app_name}:{$namespace}:{$part1}:{$part2}");
})->repeat(20);

it('generates key matching {app_name}:{namespace}:{parts} for any namespace and N parts', function (): void {
    $app_name = fake()->word();
    $namespace = fake()->word();
    $parts_count = fake()->numberBetween(3, 8);
    $parts = array_map(static fn (): string => (string) fake()->randomNumber(), range(1, $parts_count));

    CacheManager::resetAppNameCache();
    config(['app.name' => $app_name]);

    $key = CacheManager::key($namespace, ...$parts);

    $expected = implode(':', array_merge([$app_name, $namespace], $parts));
    expect($key)->toBe($expected);
})->repeat(20);

it('generates key with only namespace when no parts provided', function (): void {
    CacheManager::resetAppNameCache();
    config(['app.name' => 'myapp']);

    $key = CacheManager::key('geocoding');

    expect($key)->toStartWith('myapp:')
        ->and($key)->toBe('myapp:geocoding');
});

it('resets app name cache so config changes are reflected', function (): void {
    CacheManager::resetAppNameCache();
    config(['app.name' => 'first']);
    $key1 = CacheManager::key('ns');

    CacheManager::resetAppNameCache();
    config(['app.name' => 'second']);
    $key2 = CacheManager::key('ns');

    expect($key1)->toBe('first:ns')
        ->and($key2)->toBe('second:ns');
});
