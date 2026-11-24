<?php

declare(strict_types=1);

use App\Casts\EntityType;
use App\Models\Entity;
use Illuminate\Support\Facades\Cache;
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

it('keeps the presettable list across requests until clearPresettablesCache()', function (): void {
    $preset = Modules\Core\Tests\Support\AppDynamicEntities::preset(EntityType::Pages);
    $count = static function (): int {
        DynamicContentsService::reset();

        return DynamicContentsService::getInstance()->fetchAvailablePresettables(EntityType::Pages)->count();
    };
    $before = $count();

    // A write that fires no model event flushes nothing: the next request still reads the cached list.
    $preset->getConnection()->table(Modules\Core\Enums\CoreTables::Presettables->value)->insert([
        'preset_id' => $preset->id,
        'entity_id' => $preset->entity_id,
        'version' => 99,
        'fields_snapshot' => '[]',
    ]);
    $cached = $count();
    DynamicContentsService::getInstance()->clearPresettablesCache();

    expect($cached)->toBe($before)
        ->and($count())->toBe($before + 1);
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

it('caches the lists through the label index under the dynamic contents label, for the long duration', function (): void {
    $index = new Modules\Core\Tests\Stubs\Cache\SpyLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);

    $service = DynamicContentsService::getInstance();
    $service->fetchAvailableEntities(EntityType::Pages);
    $service->fetchAvailablePresets(EntityType::Pages);
    $service->fetchAvailablePresettables(EntityType::Pages);

    $stores = array_values(array_filter(
        $index->stores,
        static fn (array $store): bool => $store['lookup']->dependencies->labels === [Modules\Core\Cache\Labels\CacheLabel::dynamicContents()],
    ));

    expect($stores)->toHaveCount(3)
        ->and(array_unique(array_column($stores, 'ttl')))->toBe([(int) config('cache.duration.long')]);
});

it('flushes the dynamic contents label when the metadata generation moves, and keeps the generation for good', function (): void {
    $index = new Modules\Core\Tests\Stubs\Cache\SpyLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);
    $written = [];
    Illuminate\Support\Facades\Event::listen(
        Illuminate\Cache\Events\KeyWritten::class,
        static function (Illuminate\Cache\Events\KeyWritten $event) use (&$written): void {
            $written[$event->key] = $event->seconds;
        },
    );

    $service = DynamicContentsService::getInstance();
    $service->fetchAvailableEntities(EntityType::Pages);
    $service->clearAllCaches();

    $generation = array_key_exists('core.dynamic_contents.generation', $written) ? $written['core.dynamic_contents.generation'] : 'missing';

    expect($index->flushes)->toContain([Modules\Core\Cache\Labels\CacheLabel::dynamicContents()])
        ->and($generation)->toBeNull()
        ->and(DynamicContentsService::getInstance()->fetchAvailableEntities(EntityType::Pages))->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});

/**
 * Whether the entity list of the type is answered by the label index, read by a fresh service as another request would.
 */
function dynamicContentsListCached(Modules\Core\Tests\Stubs\Cache\SpyLabelIndex $index, EntityType $type): bool
{
    DynamicContentsService::reset();
    $before = count($index->lookups);
    DynamicContentsService::getInstance()->fetchAvailableEntities($type);
    $lookups = array_slice($index->lookups, $before);

    return $lookups !== [] && $lookups[0]->hit;
}

it('drops a list another request cached between a metadata write and its commit', function (): void {
    $index = new Modules\Core\Tests\Stubs\Cache\SpyLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);
    $connection = (new Entity())->getConnection();

    $connection->transaction(function () use ($index): void {
        Entity::query()->create(['name' => 'Pending', 'slug' => 'pending', 'type' => EntityType::Pages]);

        // A read between the write and the commit stores the list it sees.
        dynamicContentsListCached($index, EntityType::Pages);
    });

    expect(dynamicContentsListCached($index, EntityType::Pages))->toBeFalse();
});

it('drops a list cached from rows a rolled back metadata write created', function (): void {
    $index = new Modules\Core\Tests\Stubs\Cache\SpyLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);
    $connection = (new Entity())->getConnection();

    try {
        $connection->transaction(function () use ($index): void {
            Entity::query()->create(['name' => 'Never', 'slug' => 'never', 'type' => EntityType::Pages]);
            dynamicContentsListCached($index, EntityType::Pages);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(dynamicContentsListCached($index, EntityType::Pages))->toBeFalse();
});
