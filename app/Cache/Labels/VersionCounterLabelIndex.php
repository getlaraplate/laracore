<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IResolvesCacheLabelKeys;
use Override;

/**
 * Index for stores without sets: each label has a version counter and the entry key embeds
 * the versions it was built under, so flushing a label orphans every older key.
 * Row labels are not tracked; a row flush, or a flush of `rows:{table}`, bumps its table counter instead, once per
 * flush call however many labels map to it.
 */
final readonly class VersionCounterLabelIndex implements ICacheLabelIndex, IResolvesCacheLabelKeys
{
    private const string PREFIX = 'labelv:';

    /**
     * Ten years: far beyond the ttl of any entry, so a counter never resets while entries of its versions live.
     */
    private const int COUNTER_TTL = 315_360_000;

    public function __construct(private CacheRepository $cache) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $started_at = Clock::micros();
        $resolved = $this->resolveKey($key, $dependencies);
        $entry = $this->cache->get($resolved);

        if (is_array($entry) && array_key_exists('value', $entry)) {
            return new CacheLookup($resolved, true, $entry['value'], $started_at, $dependencies);
        }

        return new CacheLookup($resolved, false, null, $started_at, $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        return $this->cache->put($lookup->key, ['value' => $value], $ttl);
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

            // The database and memcached stores do not increment a missing key: the counter starts at 1 instead,
            // through the store's own atomic add (a ttl is required for it, so a very long one is given), and a
            // flush that created it in between is followed by an increment of its own. When that increment fails too
            // the store failed every write (a memcached server that is down answers false, never an exception): the
            // flush did not happen, and a failover index must degrade instead of losing it.
            if ($this->cache->increment($counter) === false
                && ! $this->cache->add($counter, 1, self::COUNTER_TTL)
                && $this->cache->increment($counter) === false) {
                throw new LabelIndexUnavailableException(sprintf('The version counter store refused the flush of [%s].', $target));
            }
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

        $versions = $this->cache->many(array_map(fn (string $label): string => self::PREFIX . $label, $labels));

        $resolved = $key;

        foreach ($labels as $label) {
            $resolved .= '|' . $label . '=' . (int) ($versions[self::PREFIX . $label] ?? 0);
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
}
