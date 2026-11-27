<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\Repository as BaseRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Memcached;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IResolvesCacheLabelKeys;
use Override;

/**
 * Index for stores without sets: each label has a version counter and the entry key embeds
 * the versions it was built under, so flushing a label orphans every older key.
 * Row labels are not tracked; a row flush, or a flush of `rows:{table}`, bumps its table counter instead, once per
 * flush call however many labels map to it.
 *
 * No version is ever 0 or any other value a lost counter would read again: a missing counter is created at a seed
 * taken from the clock ({@see Clock::micros()}), at the lookup as at the flush. A counter lost to an eviction, to the
 * restart of the memcached node that held it or to a deleted `database` row restarts at a fresh value, so the entries
 * of its old versions are orphaned (over-invalidation), never served again.
 *
 * On a memcached store every call is checked against the client's result code: Laravel's store answers a read that
 * failed on one node of a pool as a miss of every key (`getMulti` reports `RES_SOME_ERRORS`), which would resolve every
 * counter to the same value. Any code but a success, a missing key, a refused add or an existing key throws
 * {@see LabelIndexUnavailableException}, so a guarding {@see FailoverLabelIndex} degrades on reads as on flushes.
 */
final readonly class VersionCounterLabelIndex implements ICacheLabelIndex, IResolvesCacheLabelKeys
{
    private const string PREFIX = 'labelv:';

    /**
     * Ten years: far beyond the ttl of any entry, so a counter never resets while entries of its versions live.
     */
    private const int COUNTER_TTL = 315_360_000;

    /**
     * The memcached result codes of a call that was served: a success, a missing key, an add refused because the key
     * exists, a compare-and-swap conflict, a write buffered by the client (`OPT_BUFFER_WRITES`).
     */
    private const array MEMCACHED_SERVED = [Memcached::RES_SUCCESS, Memcached::RES_NOTFOUND, Memcached::RES_NOTSTORED, Memcached::RES_DATA_EXISTS, Memcached::RES_BUFFERED];

    public function __construct(private CacheRepository $cache) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $started_at = Clock::micros();
        $resolved = $this->resolveKey($key, $dependencies);
        $entry = $this->cache->get($resolved);
        $this->assertServed('get');

        if (is_array($entry) && array_key_exists('value', $entry)) {
            return new CacheLookup($resolved, true, $entry['value'], $started_at, $dependencies);
        }

        return new CacheLookup($resolved, false, null, $started_at, $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        $stored = $this->cache->put($lookup->key, ['value' => $value], $ttl);
        $this->assertServed('put');

        return $stored;
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        $this->cache->forget($lookup->key);
    }

    #[Override]
    public function flush(string ...$labels): void
    {
        $targets = array_unique(array_map(self::counterLabelOf(...), $labels));

        foreach ($targets as $target) {
            $counter = self::PREFIX . $target;

            // A missing counter starts at a seed, through the store's own atomic add (a ttl is required for it, so a
            // very long one is given), before any increment: the array, file and Redis stores would create it at 1,
            // a value a lost counter may have held. An existing one is incremented, and one lost between the two is
            // added again. When the last add fails too the store failed every write (a memcached server that is down
            // answers false, never an exception): the flush did not happen, and a failover index must degrade
            // instead of losing it.
            if ($this->seed($counter) || $this->served($this->cache->increment($counter), 'increment') !== false || $this->seed($counter)) {
                continue;
            }

            throw new LabelIndexUnavailableException(sprintf('The version counter store refused the flush of [%s].', $target));
        }
    }

    #[Override]
    public function pruneStale(): int
    {
        return 0;
    }

    /**
     * The key with the current version of every label it depends on appended: reads the counters, not the entry.
     */
    #[Override]
    public function resolveKey(string $key, CacheDependencies $dependencies): string
    {
        $labels = array_values(array_unique([
            ...$dependencies->labels,
            ...array_map(CacheLabel::table(...), $dependencies->row_tables),
        ]));
        sort($labels);

        $versions = $this->versionsOf(array_map(fn (string $label): string => self::PREFIX . $label, $labels));

        $resolved = $key;

        foreach ($labels as $label) {
            $resolved .= '|' . $label . '=' . $versions[self::PREFIX . $label];
        }

        return $resolved;
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return false;
    }

    /**
     * The counter a label moves: a row label and a `rows:{table}` label move their table's counter, since keys hold
     * table versions only.
     */
    private static function counterLabelOf(string $label): string
    {
        return match (true) {
            CacheLabel::isRow($label) => CacheLabel::tableOfRow($label),
            CacheLabel::isRows($label) => CacheLabel::tableOfRows($label),
            default => $label,
        };
    }

    /**
     * The value of each counter, a missing one created at a seed taken from the clock and read again, so a lookup
     * never resolves a version a lost counter held. A counter the store still does not hold gets a seed of its own,
     * never stored: a key no other lookup builds, so the value is computed and its entry is never read.
     *
     * @param  list<string>  $counters
     * @return array<string, int>
     */
    private function versionsOf(array $counters): array
    {
        $read = $this->served($this->cache->many($counters), 'many');
        $missing = array_values(array_filter($counters, static fn (string $counter): bool => ! is_numeric($read[$counter] ?? null)));

        if ($missing !== []) {
            foreach ($missing as $counter) {
                $this->served($this->cache->add($counter, Clock::micros(), self::COUNTER_TTL), 'add');
            }

            $read = [...$read, ...$this->served($this->cache->many($missing), 'many')];
        }

        $versions = [];

        foreach ($counters as $counter) {
            $version = $read[$counter] ?? null;
            $versions[$counter] = is_numeric($version) ? (int) $version : Clock::micros();
        }

        return $versions;
    }

    /**
     * The result of a call, once a memcached store has told it was served (see the class description).
     *
     * @template T
     *
     * @param  T  $result
     *
     * @throws LabelIndexUnavailableException when a memcached store failed the call.
     *
     * @return T
     */
    private function served(mixed $result, string $call): mixed
    {
        $this->assertServed($call);

        return $result;
    }

    /**
     * @throws LabelIndexUnavailableException when a memcached store failed the last call.
     */
    private function assertServed(string $call): void
    {
        $store = $this->cache instanceof BaseRepository ? $this->cache->getStore() : null;

        if (! $store instanceof MemcachedStore) {
            return;
        }

        $client = $store->getMemcached();
        $code = $client->getResultCode();

        if (in_array($code, self::MEMCACHED_SERVED, true)) {
            return;
        }

        throw new LabelIndexUnavailableException(sprintf('The memcached store failed the %s of a version counter: %s (%d).', $call, $client->getResultMessage(), $code));
    }

    /**
     * Adds the counter at a seed unless it exists. Called again after a failed increment, it is not the same call.
     *
     * @phpstan-impure
     */
    private function seed(string $counter): bool
    {
        return (bool) $this->served($this->cache->add($counter, Clock::micros(), self::COUNTER_TTL), 'add');
    }
}
