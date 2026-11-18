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
use InvalidArgumentException;
use Modules\Core\Contracts\ICacheLabelIndex;

/**
 * Picks the label index that fits the underlying store of a cache repository.
 */
final class CacheLabelIndexFactory
{
    /**
     * A Redis index is always guarded by {@see FailoverLabelIndex}, alone or as the first store of a failover cache: a
     * Redis outage degrades to "nothing cached, writes succeed" instead of failing every write that flushes.
     */
    public static function forStore(Repository $cache): ICacheLabelIndex
    {
        $store = $cache instanceof BaseRepository ? $cache->getStore() : null;

        return match (true) {
            $store instanceof RedisStore => new FailoverLabelIndex(new RedisLabelIndex($store)),
            $store instanceof ArrayStore => new ArrayLabelIndex(),
            $store instanceof NullStore => new NullLabelIndex(),
            $store instanceof FailoverStore => self::guarded(self::forStore(Cache::store(self::primaryStoreName($cache)))),
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

    private static function guarded(ICacheLabelIndex $index): ICacheLabelIndex
    {
        return $index instanceof FailoverLabelIndex ? $index : new FailoverLabelIndex($index);
    }

    /**
     * The first store of a failover cache, read from the configuration entry of that cache, whatever its name.
     *
     * @throws InvalidArgumentException when the cache has no configuration entry listing its stores.
     */
    private static function primaryStoreName(Repository $cache): string
    {
        $name = $cache instanceof BaseRepository ? $cache->getName() : null;
        $stores = is_string($name) && $name !== '' ? config('cache.stores.' . $name . '.stores') : null;

        if (! is_array($stores) || ! is_string($stores[0] ?? null) || $stores[0] === '') {
            throw new InvalidArgumentException(sprintf(
                'The failover cache store [%s] has no `stores` list in config/cache.php: its label index cannot find the store that serves it.',
                is_string($name) ? $name : 'unnamed',
            ));
        }

        return $stores[0];
    }
}
