<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FailoverStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as BaseRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Contracts\ICacheLabelIndex;

/**
 * Picks the label index that fits the underlying store of a cache repository.
 */
final class CacheLabelIndexFactory
{
    public static function forStore(Repository $cache): ICacheLabelIndex
    {
        $store = $cache instanceof BaseRepository ? $cache->getStore() : null;

        return match (true) {
            $store instanceof RedisStore => new RedisLabelIndex($store),
            $store instanceof ArrayStore => new ArrayLabelIndex(),
            $store instanceof NullStore => new NullLabelIndex(),
            $store instanceof FailoverStore => new FailoverLabelIndex(self::forStore(Cache::store(self::primaryStoreName()))),
            default => new VersionCounterLabelIndex($cache),
        };
    }

    /**
     * Where the stats counters of an index go: the store itself, or the default store under failover.
     */
    public static function statsRepository(Repository $cache): Repository
    {
        $store = $cache instanceof BaseRepository ? $cache->getStore() : null;

        return $store instanceof FailoverStore ? Cache::store() : $cache;
    }

    private static function primaryStoreName(): string
    {
        $stores = config('cache.stores.failover.stores');

        return is_array($stores) && is_string($stores[0] ?? null) ? $stores[0] : 'array';
    }
}
