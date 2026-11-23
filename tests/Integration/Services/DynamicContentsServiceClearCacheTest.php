<?php

declare(strict_types=1);

/**
 * Uses the application {@see Tests\TestCase} (full Laravel bootstrap) so {@see Cache} facade
 * works. {@see Modules\Core\Tests\LaravelTestCase} is avoided here because it runs
 * module migrations that are incompatible with the default SQLite in-memory test database.
 *
 * This file lives under {@see UnitShell} so Pest does not apply {@see Modules\Core\Tests\LaravelTestCase}
 * to the whole {@see Unit} directory binding.
 */
use Illuminate\Support\Facades\Cache;
use Modules\Core\Services\DynamicContentsService;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    app()->forgetInstance('cache.__memoized:array');
    DynamicContentsService::reset();
});

afterEach(function (): void {
    DynamicContentsService::reset();
});

it('clearPresettablesCache forgets the memo store entry for the presettables table', function (): void {
    Cache::forever('presettables', ['stale']);

    DynamicContentsService::getInstance()->clearPresettablesCache();

    expect(Cache::has('presettables'))->toBeFalse();
});

it('clearAllCaches forgets memo store entries for entities, presets, and presettables', function (): void {
    Cache::forever('entities', ['stale']);
    Cache::forever('presets', ['stale']);
    Cache::forever('presettables', ['stale']);

    DynamicContentsService::getInstance()->clearAllCaches();

    expect(Cache::has('entities'))->toBeFalse()
        ->and(Cache::has('presets'))->toBeFalse()
        ->and(Cache::has('presettables'))->toBeFalse();
});

it('clearPresetsCache bumps metadata generation so typed keys miss after reset', function (): void {
    $generation_key = 'core.dynamic_contents.generation';
    Cache::forever($generation_key, 3);

    DynamicContentsService::reset();
    DynamicContentsService::getInstance()->clearPresetsCache();

    expect(Cache::get($generation_key))->toBe(4);
});

it('metadataGeneration recovers when the generation key holds a Collection', function (): void {
    $generation_key = 'core.dynamic_contents.generation';
    Cache::forever($generation_key, new Illuminate\Database\Eloquent\Collection([(object) ['id' => 1]]));

    $service = DynamicContentsService::getInstance();
    $method = new ReflectionMethod(DynamicContentsService::class, 'metadataGeneration');
    $method->setAccessible(true);

    expect($method->invoke($service))->toBe(0)
        ->and(Cache::get($generation_key))->toBe(0);
});

it('rememberCollection reloads when the persistent cache returns a plain array', function (): void {
    $cache_key = 'core.dynamic_contents.test:array-rehydrate';
    Cache::forever($cache_key, ['not' => 'a-collection']);

    $service = DynamicContentsService::getInstance();
    $method = new ReflectionMethod(DynamicContentsService::class, 'rememberCollection');
    $method->setAccessible(true);

    $fresh = new Illuminate\Database\Eloquent\Collection();
    $result = $method->invoke($service, $cache_key, static fn (): Illuminate\Database\Eloquent\Collection => $fresh);

    expect($result)->toBe($fresh)
        ->and($result)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});
