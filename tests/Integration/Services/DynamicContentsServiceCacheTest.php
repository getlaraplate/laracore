<?php

declare(strict_types=1);

use App\Casts\EntityType;
use App\Models\Entity;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\Pivot\Presettable;
use Modules\Core\Services\DynamicContentsService;

beforeEach(function (): void {
    Cache::flush();
    DynamicContentsService::reset();
});

it('clears all dynamic contents in-memory caches', function (): void {
    $service = DynamicContentsService::getInstance();

    $service->clearAllCaches();
    DynamicContentsService::reset();

    expect(DynamicContentsService::getInstance())->toBeInstanceOf(DynamicContentsService::class);
});

it('registers namespaced presettable memo keys for later invalidation', function (): void {
    $service = DynamicContentsService::getInstance();
    $reflection = new ReflectionMethod(DynamicContentsService::class, 'presettableMemoKey');
    $reflection->setAccessible(true);

    $key = $reflection->invoke($service, Presettable::class);

    expect($key)->toBe('core.dynamic_contents.presettables:' . hash('sha256', Presettable::class) . ':g0');
});

it('keeps entity in-memory cache buckets isolated by dynamic content type', function (): void {
    Entity::query()->create([
        'name' => 'Article',
        'slug' => 'article',
        'type' => EntityType::Pages,
    ]);

    $service = DynamicContentsService::getInstance();
    $pages = $service->fetchAvailableEntities(EntityType::Pages);

    $author = Entity::query()->create([
        'name' => 'Writer',
        'slug' => 'writer',
        'type' => EntityType::Authors,
    ]);

    $authors = $service->fetchAvailableEntities(EntityType::Authors);

    expect($pages)->toHaveCount(1)
        ->and($authors->pluck('id')->all())->toBe([$author->id]);
});

/**
 * The entity class is resolved from the type's module like presets and presettables are:
 * an App type maps to App\Models\Entity, not to a `Modules\App\Models\Entity` that cannot exist.
 */
it('fetches App entity types as App entity models', function (): void {
    $entity = Entity::query()->create([
        'name' => 'Landing',
        'slug' => 'landing',
        'type' => EntityType::Pages,
    ]);

    $entities = DynamicContentsService::getInstance()->fetchAvailableEntities(EntityType::Pages);

    expect($entities)->toHaveCount(1)
        ->and($entities->first())->toBeInstanceOf(Entity::class)
        ->and($entities->first()->id)->toBe($entity->id);
});

it('keeps the lists of a metadata generation for a finite time, the generation counter for good', function (): void {
    $written = [];
    Illuminate\Support\Facades\Event::listen(
        Illuminate\Cache\Events\KeyWritten::class,
        static function (Illuminate\Cache\Events\KeyWritten $event) use (&$written): void {
            $written[$event->key] = $event->seconds;
        },
    );

    $service = DynamicContentsService::getInstance();
    $service->fetchAvailableEntities(EntityType::Pages);
    $service->fetchAvailablePresets(EntityType::Pages);
    $service->fetchAvailablePresettables(EntityType::Pages);
    $service->clearAllCaches();

    $generation = array_key_exists('core.dynamic_contents.generation', $written) ? $written['core.dynamic_contents.generation'] : 'missing';
    unset($written['core.dynamic_contents.generation']);

    expect($generation)->toBeNull()
        ->and($written)->toHaveCount(3)
        ->and(array_unique(array_values($written)))->toBe([(int) config('cache.duration.long')]);
});
