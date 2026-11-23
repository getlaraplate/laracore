<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Contracts\ICacheLabelIndex;

/**
 * Two-tier cache for expensive helper functions (models, connections).
 *
 * Model discovery is served from a per-request static layer backed by the label
 * index (label {@see CacheLabel::models()}, `cache.duration.long`), so the
 * filesystem scan runs at most once per hour instead of once per request. Both
 * layers are cleared automatically after migrations via CommandListenerProvider.
 */
final class HelpersCache
{
    /**
     * Cache-key prefix for the cross-request persistent model discovery layer.
     */
    private const string MODELS_CACHE_PREFIX = 'core.helpers.models.';

    /**
     * @var array<string, list<class-string<Model>>>
     */
    private static array $models = [];

    /**
     * @var array<string, list<string>>
     */
    private static array $connections = [];

    private function __construct() {}

    /**
     * Fully-qualified persistent cache key for a model discovery bucket.
     *
     * Exposed so deploy tooling and tests can target the exact key that backs
     * cross-request model discovery.
     */
    public static function modelsCacheKey(string $key): string
    {
        return self::MODELS_CACHE_PREFIX . $key;
    }

    /**
     * Resolve a model discovery bucket through both cache tiers.
     *
     * Returns the per-request in-memory copy when warm; otherwise runs the
     * discovery closure through the persistent cache and warms the in-memory
     * layer. The in-memory layer is consulted first so explicit {@see setModels()}
     * injections keep overriding discovery.
     *
     * @param  Closure(): list<class-string<Model>>  $discover
     * @return list<class-string<Model>>
     */
    public static function rememberModels(string $key, Closure $discover): array
    {
        if (isset(self::$models[$key])) {
            return self::$models[$key];
        }

        $models = self::persistentCacheAvailable()
            ? resolve(LabelledCache::class)->remember(
                self::modelsCacheKey($key),
                [CacheLabel::models()],
                (int) config('cache.duration.long'),
                $discover,
                is_array(...),
            )
            : $discover();

        self::$models[$key] = $models;

        return $models;
    }

    /**
     * @return list<class-string<Model>>|null
     */
    public static function getModels(string $key): ?array
    {
        return self::$models[$key] ?? null;
    }

    /**
     * @param  list<class-string<Model>>  $models
     */
    public static function setModels(string $key, array $models): void
    {
        self::$models[$key] = $models;
    }

    /**
     * @return list<string>|null
     */
    public static function getConnections(string $key): ?array
    {
        return self::$connections[$key] ?? null;
    }

    /**
     * @param  list<string>  $connections
     */
    public static function setConnections(string $key, array $connections): void
    {
        self::$connections[$key] = $connections;
    }

    public static function clearModels(): void
    {
        self::forgetPersistentModels();
        self::$models = [];
    }

    public static function clearConnections(): void
    {
        self::$connections = [];
    }

    public static function clearAll(): void
    {
        self::forgetPersistentModels();
        self::$models = [];
        self::$connections = [];
    }

    /**
     * Forget every persistent model discovery bucket (a flush of their label)
     * so the next resolution rescans the filesystem. Leaves the in-memory layer untouched so test
     * injections via {@see setModels()} keep working (e.g. permission:refresh).
     */
    public static function forgetPersistentModels(): void
    {
        if (! self::persistentCacheAvailable()) {
            return;
        }

        resolve(ICacheLabelIndex::class)->flush(CacheLabel::models());
    }

    private static function persistentCacheAvailable(): bool
    {
        return function_exists('app') && app()->bound('cache') && app()->bound('files') && app()->bound(ICacheLabelIndex::class);
    }
}
