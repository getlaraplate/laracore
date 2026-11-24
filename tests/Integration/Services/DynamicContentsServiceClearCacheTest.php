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

it('bumps the metadata generation from 0 when the generation key holds a Collection', function (): void {
    $generation_key = 'core.dynamic_contents.generation';
    // Fork-poisoned Redis replies, or a failover array hit, can leave a collection under the key.
    Cache::forever($generation_key, new Illuminate\Database\Eloquent\Collection([(object) ['id' => 1]]));

    DynamicContentsService::getInstance()->clearPresetsCache();

    expect(Cache::get($generation_key))->toBe(1);
});

it('reloads a list the label index answers as a plain array, and stores the reloaded collection over it', function (): void {
    $index = new Modules\Core\Tests\Stubs\Cache\PlainArrayHitLabelIndex((new App\Models\Entity())->getCacheKey() . ':');
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);

    $entities = DynamicContentsService::getInstance()->fetchAvailableEntities(App\Casts\EntityType::Pages);

    expect($entities)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class)
        ->and($index->stored_values)->toHaveCount(1)
        ->and($index->stored_values[0])->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});
