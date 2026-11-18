<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Cache\Repository as BaseRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;
use Throwable;

/**
 * Counts hits, misses (per first table label) and flushes on any cache driver.
 *
 * Flushes are counted per label, except labels that grow with the data: every row label of a table counts under
 * `row:{table}:*` and every user label under `user:*`, so the counters and the label list stay as many as the tables
 * and the fixed labels. Cost per counted label: one add and one increment on the stats store; the label list is
 * written only the first time a label is seen, under a lock of the stats store when it has locks.
 *
 * Stats are best effort: a failure of the stats store is reported once and stops the counting of this instance, and
 * nothing is counted while a guarded index is degraded. It never fails the read or the write it measures.
 */
final class MeasuredLabelIndex implements ICacheLabelIndex
{
    private const string PREFIX = 'labelstats:';

    private const string LABELS_KEY = 'labelstats:labels';

    private const int TTL = 86400 * 30;

    private bool $stats_failed = false;

    public function __construct(private readonly ICacheLabelIndex $inner, private readonly Repository $stats) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $lookup = $this->inner->lookup($key, $dependencies);

        foreach ($dependencies->labels as $label) {
            if (str_starts_with($label, 'table:')) {
                $this->count($lookup->hit ? 'hits' : 'misses', $label);

                break;
            }
        }

        return $lookup;
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        return $this->inner->store($lookup, $value, $ttl, $row_labels);
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        $this->inner->forget($lookup);
    }

    #[Override]
    public function flush(string ...$labels): void
    {
        $this->inner->flush(...$labels);

        foreach (array_unique(array_map(self::bucketOf(...), $labels)) as $bucket) {
            $this->count('flushes', $bucket);
        }
    }

    #[Override]
    public function pruneStale(): int
    {
        return $this->inner->pruneStale();
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return $this->inner->supportsRowLabels();
    }

    /**
     * @return array<string, array{hits: int, misses: int, flushes: int}> label => counters
     */
    public function report(): array
    {
        $labels = $this->stats->get(self::LABELS_KEY, []);
        $report = [];

        foreach (is_array($labels) ? $labels : [] as $label) {
            $report[(string) $label] = [
                'hits' => (int) $this->stats->get(self::PREFIX . 'hits:' . $label, 0),
                'misses' => (int) $this->stats->get(self::PREFIX . 'misses:' . $label, 0),
                'flushes' => (int) $this->stats->get(self::PREFIX . 'flushes:' . $label, 0),
            ];
        }

        ksort($report);

        return $report;
    }

    /**
     * The label a flush is counted under: one per table for row labels, one for every user label.
     */
    private static function bucketOf(string $label): string
    {
        return match (true) {
            CacheLabel::isRow($label) => CacheLabel::row(CacheLabel::rowTable($label), '*'),
            CacheLabel::isUser($label) => CacheLabel::user('*'),
            default => $label,
        };
    }

    private function count(string $metric, string $label): void
    {
        if ($this->stats_failed || ($this->inner instanceof FailoverLabelIndex && $this->inner->isDegraded())) {
            return;
        }

        try {
            $key = self::PREFIX . $metric . ':' . $label;
            $first_time = $this->stats->add($key, 0, self::TTL);
            $this->stats->increment($key);

            if ($first_time) {
                $this->register($label);
            }
        } catch (Throwable $throwable) {
            $this->stats_failed = true;
            report($throwable);
        }
    }

    /**
     * Adds a label to the list {@see report()} reads. The list is read, changed and written back, so writers seeing a
     * label for the first time at once take the lock of the stats store, when it has one, and do not drop each other's.
     */
    private function register(string $label): void
    {
        $register = function () use ($label): void {
            $labels = $this->stats->get(self::LABELS_KEY, []);
            $labels = is_array($labels) ? $labels : [];

            if (! in_array($label, $labels, true)) {
                $labels[] = $label;
                $this->stats->put(self::LABELS_KEY, $labels, self::TTL);
            }
        };

        $store = $this->stats instanceof BaseRepository ? $this->stats->getStore() : null;

        if ($store instanceof LockProvider) {
            $store->lock(self::LABELS_KEY . ':lock', 5)->block(1, $register);

            return;
        }

        $register();
    }
}
