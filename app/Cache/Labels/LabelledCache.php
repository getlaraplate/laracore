<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Closure;
use Modules\Core\Contracts\ICacheLabelIndex;

/**
 * The lookup, compute and store of a value cached through the label index, in one call.
 *
 * It follows the rules of {@see ICacheLabelIndex}: the value is computed after the lookup, so a label flushed while it
 * was computed refuses the store (no stale write-back); a degraded failover index stores nothing; a value whose
 * dependencies are incomplete is never stored. Resolve it from the container: it holds no state of its own.
 */
final readonly class LabelledCache
{
    public function __construct(private ICacheLabelIndex $index) {}

    /**
     * The value cached under the key, or the value of `$compute`, stored under the dependencies for `$ttl` seconds.
     *
     * @template T
     *
     * @param  CacheDependencies|list<string>  $dependencies  The dependencies of the value, or only its labels.
     * @param  Closure(): T  $compute
     * @param  (Closure(mixed): bool)|null  $cacheable  Whether a value may be served from and kept in the cache: a hit
     *                                                  it rejects is computed again, a computed value it rejects is
     *                                                  returned without being stored. Null accepts every value.
     * @return T
     */
    public function remember(string $key, CacheDependencies|array $dependencies, int $ttl, Closure $compute, ?Closure $cacheable = null): mixed
    {
        $lookup = $this->index->lookup($key, self::dependenciesOf($dependencies));

        if ($lookup->hit && ($cacheable === null || $cacheable($lookup->value))) {
            /** @var T */
            return $lookup->value;
        }

        return $this->computeAndStore($lookup, $ttl, $compute, $cacheable);
    }

    /**
     * Computes the value again and stores it whatever the cache holds, as a warmer does. Same rules as
     * {@see remember()}.
     *
     * @template T
     *
     * @param  CacheDependencies|list<string>  $dependencies
     * @param  Closure(): T  $compute
     * @param  (Closure(mixed): bool)|null  $cacheable  Whether the computed value may be stored. Null accepts every value.
     * @return T
     */
    public function refresh(string $key, CacheDependencies|array $dependencies, int $ttl, Closure $compute, ?Closure $cacheable = null): mixed
    {
        return $this->computeAndStore($this->index->lookup($key, self::dependenciesOf($dependencies)), $ttl, $compute, $cacheable);
    }

    /**
     * Drops the value cached under the key and dependencies. On a version counter store the stored key embeds the
     * versions of the labels, so the dependencies must be those the value was remembered with.
     *
     * @param  CacheDependencies|list<string>  $dependencies
     */
    public function forget(string $key, CacheDependencies|array $dependencies): void
    {
        $this->index->forget($this->index->lookup($key, self::dependenciesOf($dependencies)));
    }

    /**
     * @param  CacheDependencies|list<string>  $dependencies
     */
    private static function dependenciesOf(CacheDependencies|array $dependencies): CacheDependencies
    {
        return $dependencies instanceof CacheDependencies ? $dependencies : new CacheDependencies($dependencies);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @param  (Closure(mixed): bool)|null  $cacheable
     * @return T
     */
    private function computeAndStore(CacheLookup $lookup, int $ttl, Closure $compute, ?Closure $cacheable): mixed
    {
        $value = $compute();

        if ($lookup->dependencies->complete && ($cacheable === null || $cacheable($value))) {
            $this->index->store($lookup, $value, $ttl);
        }

        return $value;
    }
}
