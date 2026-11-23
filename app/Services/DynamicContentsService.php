<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Contracts\IDynamicEntityTypable;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Entity;
use Modules\Core\Models\Pivot\Presettable;
use Modules\Core\Models\Preset;
use UnexpectedValueException;

/**
 * Singleton service that caches dynamic contents data in-memory during the request/command scope.
 * This prevents redundant cache access when the same data is requested multiple times.
 */
final class DynamicContentsService
{
    /**
     * Shared generation embedded in every typed memo key. Bumped on metadata
     * writes so invalidation works even after {@see self::reset()} drops the
     * in-process key registry (parallel BatchSeeder forks) or when another
     * process never warmed the registry — without this, stale
     * cached preset lists miss new preset ids ("No cached preset [N]").
     */
    private const string METADATA_GENERATION_KEY = 'core.dynamic_contents.generation';

    /**
     * Memo / persistent cache keys used for presettable lists (one per concrete Presettable class).
     *
     * @var list<string>
     */
    private static array $registered_presettable_memo_keys = [];

    /**
     * Memo / persistent cache keys used for entity lists (one per dynamic content type).
     *
     * @var list<string>
     */
    private static array $registered_entity_memo_keys = [];

    /**
     * Memo / persistent cache keys used for preset lists (one per dynamic content type).
     *
     * @var list<string>
     */
    private static array $registered_preset_memo_keys = [];

    /**
     * In-memory cache for entities.
     *
     * @var array<string, Collection<int, Entity>>
     */
    private array $entities_cache = [];

    /**
     * In-memory cache for presets.
     *
     * @var array<string, Collection<int, Preset>>
     */
    private array $presets_cache = [];

    /**
     * In-memory cache for presettables.
     *
     * @var array<string, Collection<int, Presettable>>
     */
    private array $presettables_cache = [];

    /**
     * Get the request-scoped service instance.
     *
     * The instance is a scoped container binding, not a process-level singleton:
     * it memoizes hydrated Entity/Preset/Presettable collections, which must not
     * survive into the next request when the app is served by a long-lived worker.
     */
    public static function getInstance(): self
    {
        return app(self::class);
    }

    /**
     * Drop the current instance and the registered memo keys.
     * Used by tests, cache invalidation and post-fork seeder workers.
     */
    public static function reset(): void
    {
        app()->forgetInstance(self::class);
        self::$registered_presettable_memo_keys = [];
        self::$registered_entity_memo_keys = [];
        self::$registered_preset_memo_keys = [];
    }

    /**
     * Fetch available entities for a given type.
     * Uses in-memory cache first, then external cache, then database.
     *
     * @return Collection<int, Entity>
     */
    public function fetchAvailableEntities(IDynamicEntityTypable $type): Collection
    {
        $type_cache_key = $this->typeCacheKey($type);

        if (isset($this->entities_cache[$type_cache_key])) {
            return $this->entities_cache[$type_cache_key];
        }

        $entity_class = $this->getModuleModelClass($type::class, Entity::class);
        $entity_model = new $entity_class();
        $cache_key = $this->typedMemoKey($entity_model->getCacheKey(), $type_cache_key);
        $this->registerEntityMemoKey($cache_key);

        $this->entities_cache[$type_cache_key] = $this->rememberCollection(
            $cache_key,
            fn (): Collection => $entity_class::query()
                ->withoutGlobalScopes()
                ->where('type', $type->toScalar())
                ->orderBy('is_default', 'desc')
                ->orderBy('name', 'asc')
                ->get(),
        );

        return $this->entities_cache[$type_cache_key];
    }

    /**
     * Fetch available presets for a given type.
     * Uses in-memory cache first, then external cache, then database.
     *
     * @return Collection<int, Preset>
     */
    public function fetchAvailablePresets(IDynamicEntityTypable $type): Collection
    {
        $type_cache_key = $this->typeCacheKey($type);

        if (isset($this->presets_cache[$type_cache_key])) {
            return $this->presets_cache[$type_cache_key];
        }

        $preset_class = $this->getModuleModelClass($type::class, Preset::class);
        $preset_model = new $preset_class();
        $cache_key = $this->typedMemoKey($preset_model->getCacheKey(), $type_cache_key);
        $this->registerPresetMemoKey($cache_key);

        $this->presets_cache[$type_cache_key] = $this->rememberCollection(
            $cache_key,
            fn (): Collection => $preset_class::query()
                ->withoutGlobalScopes()
                ->with(['fields', 'entity'])
                ->whereHas('entity', static function (Builder $query) use ($type): void {
                    $query->where($query->qualifyColumn('type'), $type->toScalar());
                })
                ->orderBy('is_default', 'desc')
                ->orderBy('name', 'asc')
                ->get(),
        );

        return $this->presets_cache[$type_cache_key];
    }

    /**
     * Fetch available presettables for a given type.
     * Uses in-memory cache first, then external cache, then database.
     *
     * @return Collection<int, Presettable>
     */
    public function fetchAvailablePresettables(IDynamicEntityTypable $type): Collection
    {
        $type_cache_key = $this->typeCacheKey($type);

        if (isset($this->presettables_cache[$type_cache_key])) {
            return $this->presettables_cache[$type_cache_key];
        }

        $presettable_class = $this->getModuleModelClass($type::class, Presettable::class);

        // Namespaced key (table name alone collides with unrelated cache entries and shared Redis DBs).
        $cache_key = $this->presettableMemoKey($presettable_class, $type);
        $this->registerPresettableMemoKey($cache_key);

        $presettables_table = CoreTables::Presettables->value;
        $presets_table = CoreTables::Presets->value;
        $entities_table = CoreTables::Entities->value;

        $this->presettables_cache[$type_cache_key] = $this->rememberCollection(
            $cache_key,
            fn (): Collection => $presettable_class::query()
                ->join($presets_table, "{$presettables_table}.preset_id", '=', "{$presets_table}.id")
                ->join($entities_table, "{$presets_table}.entity_id", '=', "{$entities_table}.id")
                ->whereNull("{$presettables_table}.deleted_at")
                ->whereNull("{$presets_table}.deleted_at")
                ->where("{$entities_table}.type", $type->toScalar())
                ->addSelect("{$presettables_table}.*", DB::raw("CASE WHEN {$presets_table}.is_default THEN 1 ELSE 0 END + CASE WHEN {$entities_table}.is_default THEN 1 ELSE 0 END as order_score"))
                ->orderBy('order_score', 'desc')
                ->get(),
        );

        return $this->presettables_cache[$type_cache_key];
    }

    /**
     * Clear in-memory cache for entities.
     * Should be called when entities are modified, with the written model as owner (see bumpMetadataGeneration()).
     */
    public function clearEntitiesCache(?Model $owner = null): void
    {
        $this->entities_cache = [];
        $this->forgetRegisteredMemoKeys(self::$registered_entity_memo_keys);
        $this->forgetMemoCacheKey('entities');
        $this->bumpMetadataGeneration($owner);
    }

    /**
     * Clear in-memory cache for presets.
     * Should be called when presets are modified, with the written model as owner (see bumpMetadataGeneration()).
     */
    public function clearPresetsCache(?Model $owner = null): void
    {
        $this->presets_cache = [];
        $this->forgetRegisteredMemoKeys(self::$registered_preset_memo_keys);
        $this->forgetMemoCacheKey('presets');
        $this->bumpMetadataGeneration($owner);
    }

    /**
     * Clear in-memory cache for presettables.
     * Should be called when presettables are modified, with the written model as owner (see bumpMetadataGeneration()).
     */
    public function clearPresettablesCache(?Model $owner = null): void
    {
        $this->presettables_cache = [];
        $this->forgetRegisteredMemoKeys(self::$registered_presettable_memo_keys);
        $this->forgetMemoCacheKey('presettables');
        $this->bumpMetadataGeneration($owner);
    }

    /**
     * Clear all in-memory caches, with the written model as owner when a write causes it (see bumpMetadataGeneration()).
     */
    public function clearAllCaches(?Model $owner = null): void
    {
        $this->entities_cache = [];
        $this->presets_cache = [];
        $this->presettables_cache = [];
        $this->forgetRegisteredMemoKeys(self::$registered_entity_memo_keys);
        $this->forgetRegisteredMemoKeys(self::$registered_preset_memo_keys);
        $this->forgetRegisteredMemoKeys(self::$registered_presettable_memo_keys);
        $this->forgetMemoCacheKey('entities');
        $this->forgetMemoCacheKey('presets');
        $this->forgetMemoCacheKey('presettables');
        $this->bumpMetadataGeneration($owner);
    }

    /**
     * Laravel's memoized cache layer keeps values in process memory; `cache:clear` only flushes
     * the underlying store, so stale memo entries must be forgotten explicitly.
     */
    private function forgetMemoCacheKey(string $key): void
    {
        Cache::forget($key);
        Cache::memo()->forget($key);
    }

    /**
     * @param  list<string>  $in_memory_keys
     */
    private function forgetRegisteredMemoKeys(array &$in_memory_keys): void
    {
        foreach ($in_memory_keys as $key) {
            $this->forgetMemoCacheKey($key);
        }

        $in_memory_keys = [];
    }

    /**
     * Remember a list through the label index for `cache.duration.long`, guaranteeing an Eloquent Collection.
     *
     * The entry carries {@see CacheLabel::dynamicContents()}, flushed when the metadata generation moves, and on a
     * guarded index the `stored` label the recovery purge flushes, so a bump lost during an outage is made good. The
     * keys still embed the generation, which keeps processes that missed a flush on a key of their own. No
     * Cache::memo() layer: this scoped service already keeps each list in memory for the request.
     *
     * Persistent drivers (Redis serializers, failover, fork reconnect) can rehydrate Eloquent collections as plain
     * arrays: such a hit is reloaded from the database and stored over.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(): Collection<int, TModel>  $loader
     * @return Collection<int, TModel>
     */
    private function rememberCollection(string $cache_key, callable $loader): Collection
    {
        /** @var Collection<int, TModel> */
        return resolve(LabelledCache::class)->remember(
            $cache_key,
            [CacheLabel::dynamicContents()],
            (int) config('cache.duration.long'),
            Closure::fromCallable($loader),
            static fn (mixed $list): bool => $list instanceof Collection,
        );
    }

    /**
     * Get the module model class for a given local and target class.
     *
     * @param  class-string  $local_class  The local class
     * @param  class-string  $target_class  The target class
     * @return class-string The module model class
     */
    private function getModuleModelClass(string $local_class, string $target_class): string
    {
        $from_module = class_module($local_class);
        $to_module = class_module($target_class);
        $search_prefix = $to_module === 'App'
            ? $to_module . '\\'
            : 'Modules\\' . $to_module . '\\';
        $replace_prefix = $from_module === 'App'
            ? $from_module . '\\'
            : 'Modules\\' . $from_module . '\\';
        $pattern = '#^' . preg_quote($search_prefix, '#') . '#';
        $replaced = (string) preg_replace($pattern, $replace_prefix, $target_class, 1);

        throw_if(! class_exists($replaced), UnexpectedValueException::class, "Target class not found: {$replaced}");

        return $replaced;
    }

    private function typeCacheKey(IDynamicEntityTypable $type): string
    {
        return $type::class . ':' . $type->toScalar();
    }

    private function metadataGeneration(): int
    {
        $value = Cache::get(self::METADATA_GENERATION_KEY, 0);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        // Fork-poisoned Redis replies (or a failover array hit) can yield an
        // Eloquent Collection under this key. Repair so typed memo keys stay usable.
        Cache::forever(self::METADATA_GENERATION_KEY, 0);

        return 0;
    }

    /**
     * Moves the generation at once, and flushes the label of the lists through the invalidator: with the written model
     * as owner, after the commit of its transaction, or after its rollback, so a list another request cached from the
     * rows before the commit, or one built from rows a rollback removed, is not served. Without an owner it flushes at
     * once.
     */
    private function bumpMetadataGeneration(?Model $owner): void
    {
        // Use the non-memo store only. Cache::memo()->forget() also deletes from the
        // underlying repository, which would wipe the generation we just wrote.
        Cache::forever(self::METADATA_GENERATION_KEY, $this->metadataGeneration() + 1);
        resolve(CacheLabelInvalidator::class)->flushLabels($owner, CacheLabel::dynamicContents());
    }

    private function typedMemoKey(string $cache_key, string $type_cache_key): string
    {
        return $cache_key . ':' . hash('sha256', $type_cache_key) . ':g' . $this->metadataGeneration();
    }

    /**
     * @param  class-string<Presettable>  $presettable_class
     */
    private function presettableMemoKey(string $presettable_class, ?IDynamicEntityTypable $type = null): string
    {
        $key_parts = $type instanceof IDynamicEntityTypable
            ? $presettable_class . ':' . $this->typeCacheKey($type)
            : $presettable_class;

        return 'core.dynamic_contents.presettables:' . hash('sha256', $key_parts) . ':g' . $this->metadataGeneration();
    }

    private function registerEntityMemoKey(string $cache_key): void
    {
        if (! in_array($cache_key, self::$registered_entity_memo_keys, true)) {
            self::$registered_entity_memo_keys[] = $cache_key;
        }
    }

    private function registerPresetMemoKey(string $cache_key): void
    {
        if (! in_array($cache_key, self::$registered_preset_memo_keys, true)) {
            self::$registered_preset_memo_keys[] = $cache_key;
        }
    }

    private function registerPresettableMemoKey(string $cache_key): void
    {
        if (! in_array($cache_key, self::$registered_presettable_memo_keys, true)) {
            self::$registered_presettable_memo_keys[] = $cache_key;
        }
    }
}
