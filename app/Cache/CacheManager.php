<?php

declare(strict_types=1);

namespace Modules\Core\Cache;

use Illuminate\Cache\CacheManager as BaseCacheManager;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Application;

final class CacheManager extends BaseCacheManager
{
    /**
     * Cached app name to avoid repeated config calls.
     */
    private static ?string $app_name = null;

    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * Generate a namespaced cache key in the format:
     * {app_name}:{namespace}:{part1}:{part2}:...
     *
     * This ensures all cache keys are prefixed with the application name,
     * preventing collisions in shared cache environments.
     */
    public static function key(string $namespace, string ...$parts): string
    {
        if (self::$app_name === null) {
            self::$app_name = (string) config('app.name');
        }

        $segments = array_merge([self::$app_name, $namespace], $parts);

        return implode(':', $segments);
    }

    /**
     * Reset the cached app name (used in tests or long-running processes).
     */
    public static function resetAppNameCache(): void
    {
        self::$app_name = null;
    }

    /**
     * Create a Core Repository for every store so methods like clearByEntity
     * are available on Cache:: regardless of driver (array,
     * redis, failover, …).
     */
    public function repository(Store $store, array $config = []): Repository
    {
        return new Repository($store, $config);
    }
}
