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

it('rememberCollection reloads when the label index returns a plain array', function (): void {
    $cache_key = 'core.dynamic_contents.test:array-rehydrate';
    $index = resolve(Modules\Core\Contracts\ICacheLabelIndex::class);
    $dependencies = new Modules\Core\Cache\Labels\CacheDependencies([Modules\Core\Cache\Labels\CacheLabel::dynamicContents()]);
    $index->store($index->lookup($cache_key, $dependencies), ['not' => 'a-collection'], 60);

    $service = DynamicContentsService::getInstance();
    $method = new ReflectionMethod(DynamicContentsService::class, 'rememberCollection');
    $method->setAccessible(true);

    $fresh = new Illuminate\Database\Eloquent\Collection();
    $result = $method->invoke($service, $cache_key, static fn (): Illuminate\Database\Eloquent\Collection => $fresh);

    expect($result)->toBe($fresh)
        ->and($result)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});

it('rememberCollection keeps the reloaded collection in the label index over the plain array', function (): void {
    $cache_key = 'core.dynamic_contents.test:array-overwrite';
    $index = resolve(Modules\Core\Contracts\ICacheLabelIndex::class);
    $dependencies = new Modules\Core\Cache\Labels\CacheDependencies([Modules\Core\Cache\Labels\CacheLabel::dynamicContents()]);
    $index->store($index->lookup($cache_key, $dependencies), ['not' => 'a-collection'], 60);

    $method = new ReflectionMethod(DynamicContentsService::class, 'rememberCollection');
    $method->invoke(DynamicContentsService::getInstance(), $cache_key, static fn (): Illuminate\Database\Eloquent\Collection => new Illuminate\Database\Eloquent\Collection());

    expect($index->lookup($cache_key, $dependencies)->value)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});
