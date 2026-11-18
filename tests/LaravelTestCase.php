<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\Repository as CoreCacheRepository;

/**
 * Base test case for Core tests that need the full Laraplate application (same bootstrap as production).
 */
abstract class LaravelTestCase extends \Tests\TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureCoolsamModulesResourceAlias();
        $this->ensureTestCacheStore();
    }

    /**
     * Ensure Coolsam\Modules\Resource resolves in tests when the Coolsam package is not installed.
     */
    private function ensureCoolsamModulesResourceAlias(): void
    {
        if (class_exists(\Coolsam\Modules\Resource::class)) {
            return;
        }

        if (! class_exists(\Filament\Resources\Resource::class)) {
            return;
        }

        class_alias(\Filament\Resources\Resource::class, \Coolsam\Modules\Resource::class);
    }

    /**
     * Override cache.store so the default driver returns the Core Repository.
     */
    private function ensureTestCacheStore(): void
    {
        $app = $this->app;
        $store = new ArrayStore;
        $config = $app['config']->get('cache.stores.array', []);
        $repository = new CoreCacheRepository($store, $config);
        $repository->setEventDispatcher($app['events']);
        $app->instance('cache.store', $repository);
        $app->instance('cache', new class($app) extends \Illuminate\Cache\CacheManager
        {
            public function store($name = null)
            {
                $name = $name ?? $this->getDefaultDriver();

                if ($name === 'array' || $name === $this->getDefaultDriver()) {
                    return $this->app['cache.store'];
                }

                return parent::store($name);
            }
        });

        // Facade caches the manager from earlier bootstrap; without this, __callStatic
        // keeps using a default CacheManager whose Repository forwards unknown methods to ArrayStore.
        Cache::clearResolvedInstance();

        // Cache::memo() is a scoped binding that can outlive the store swap above and
        // keep stale rememberForever values across tests in the same parallel worker.
        foreach (array_unique(['array', (string) $app['config']->get('cache.default')]) as $driver) {
            $app->forgetInstance('cache.__memoized:' . $driver);
        }
    }
}
