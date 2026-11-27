<?php

declare(strict_types=1);

use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Models\User;

/**
 * Seeds the persistent model discovery layer in the label index, under the label it is stored with.
 *
 * @param  list<string>  $models
 */
function seedPersistentModels(string $key, array $models): void
{
    $index = resolve(Modules\Core\Contracts\ICacheLabelIndex::class);
    $index->store($index->lookup(HelpersCache::modelsCacheKey($key), new Modules\Core\Cache\Labels\CacheDependencies([Modules\Core\Cache\Labels\CacheLabel::models()])), $models, 3600);
}

function persistentModelsCached(string $key): bool
{
    return resolve(Modules\Core\Contracts\ICacheLabelIndex::class)
        ->lookup(HelpersCache::modelsCacheKey($key), new Modules\Core\Cache\Labels\CacheDependencies([Modules\Core\Cache\Labels\CacheLabel::models()]))
        ->hit;
}

beforeEach(function (): void {
    HelpersCache::clearAll();
});

it('models() returns a non-empty list of model class strings', function (): void {
    HelpersCache::setModels('active', [User::class]);

    $models = models();

    expect($models)->toBeArray()->not->toBeEmpty()
        ->and($models[0])->toBeString()->toContain('\\');
});

it('models() returns the same result on repeated calls (memoized)', function (): void {
    HelpersCache::setModels('active', [User::class]);

    $first = models();
    $second = models();

    expect($first)->toBe($second);
});

it('models() caches results in HelpersCache', function (): void {
    expect(HelpersCache::getModels('active'))->toBeNull();

    models();

    expect(HelpersCache::getModels('active'))->not->toBeNull()->toBeArray();
});

it('models() filters by module when $onlyModule is provided', function (): void {
    HelpersCache::setModels('active', [User::class]);

    $all = models();
    $core_only = models(onlyModule: 'Core');

    expect($core_only)->not->toBeEmpty();

    foreach ($core_only as $class) {
        expect($class)->toStartWith('Modules\\Core\\');
    }

    expect(count($core_only))->toBeLessThanOrEqual(count($all));
});

it('models() filters with custom callable', function (): void {
    HelpersCache::setModels('active', [User::class]);

    $all = models();
    $filtered = models(filter: fn (string $class): bool => str_contains($class, 'User'));

    expect($filtered)->not->toBeEmpty();
    expect(count($filtered))->toBeLessThanOrEqual(count($all));

    foreach ($filtered as $class) {
        expect($class)->toContain('User');
    }
});

it('connections() returns a non-empty list of driver strings', function (): void {
    HelpersCache::setConnections('active', ['sqlite']);

    $connections = connections();

    expect($connections)->toBeArray()->not->toBeEmpty();
});

it('connections() caches results in HelpersCache', function (): void {
    expect(HelpersCache::getConnections('active'))->toBeNull();

    connections();

    expect(HelpersCache::getConnections('active'))->not->toBeNull()->toBeArray();
});

it('HelpersCache::clearAll resets both models and connections', function (): void {
    models();
    connections();

    expect(HelpersCache::getModels('active'))->not->toBeNull();
    expect(HelpersCache::getConnections('active'))->not->toBeNull();

    HelpersCache::clearAll();

    expect(HelpersCache::getModels('active'))->toBeNull();
    expect(HelpersCache::getConnections('active'))->toBeNull();
});

it('HelpersCache::clearModels only clears model cache', function (): void {
    models();
    connections();

    HelpersCache::clearModels();

    expect(HelpersCache::getModels('active'))->toBeNull();
    expect(HelpersCache::getConnections('active'))->not->toBeNull();
});

it('models() serves discovery from the persistent cache when memory is cold', function (): void {
    HelpersCache::clearModels();

    // Seed the persistent layer with a sentinel the filesystem scan would never produce.
    seedPersistentModels('active', ['App\\Models\\SentinelNeverScanned']);

    expect(models(true))->toBe(['App\\Models\\SentinelNeverScanned']);
});

it('clearModels forgets the persistent discovery cache so the next call rescans', function (): void {
    $real = models(true);

    expect($real)->not->toBeEmpty()
        ->and($real)->not->toBe(['App\\Models\\SentinelNeverScanned']);

    // Poison the persistent layer and drop the in-memory copy.
    seedPersistentModels('active', ['App\\Models\\SentinelNeverScanned']);
    HelpersCache::clearModels();

    // clearModels() must have forgotten the persistent entry, forcing a real rescan.
    expect(models(true))->toBe($real);
});

it('forgetPersistentModels drops Redis/file discovery without wiping in-memory injections', function (): void {
    HelpersCache::setModels('active', [User::class]);
    seedPersistentModels('active', ['App\\Models\\SentinelNeverScanned']);

    HelpersCache::forgetPersistentModels();

    expect(persistentModelsCached('active'))->toBeFalse()
        ->and(HelpersCache::getModels('active'))->toBe([User::class])
        ->and(models(true))->toBe([User::class]);
});

it('HelpersCache has private constructor', function (): void {
    $ref = new ReflectionClass(HelpersCache::class);
    $constructor = $ref->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor->isPrivate())->toBeTrue();

    $constructor->setAccessible(true);
    $instance = $ref->newInstanceWithoutConstructor();
    $constructor->invoke($instance);

    expect($instance)->toBeInstanceOf(HelpersCache::class);
});

it('HelpersCache::clearConnections only clears connection cache', function (): void {
    models();
    connections();

    HelpersCache::clearConnections();

    expect(HelpersCache::getConnections('active'))->toBeNull();
    expect(HelpersCache::getModels('active'))->not->toBeNull();
});

it('caches model discovery through the label index under the models label, for the long duration', function (): void {
    $index = new Modules\Core\Tests\Stubs\Cache\SpyLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);
    HelpersCache::clearModels();

    models(true);

    expect($index->stores)->toHaveCount(1)
        ->and($index->stores[0]['lookup']->key)->toBe(HelpersCache::modelsCacheKey('active'))
        ->and($index->stores[0]['lookup']->dependencies->labels)->toBe([Modules\Core\Cache\Labels\CacheLabel::models()])
        ->and($index->stores[0]['ttl'])->toBe((int) config('cache.duration.long'));
});

it('forgetPersistentModels flushes the models label', function (): void {
    $index = new Modules\Core\Tests\Stubs\Cache\SpyLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);

    HelpersCache::forgetPersistentModels();

    expect($index->flushes)->toBe([[Modules\Core\Cache\Labels\CacheLabel::models()]]);
});
