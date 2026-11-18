<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;
use Predis\PredisException;
use RedisException;
use Throwable;

/**
 * Counts hits, misses (per first table label) and flushes (per label) on any cache driver.
 * Cost per counted call: one add and one increment on the stats store; the label list is only
 * touched the first time a label is seen. Connection failures of the stats store are ignored.
 */
final readonly class MeasuredLabelIndex implements ICacheLabelIndex
{
    private const string PREFIX = 'labelstats:';

    private const string LABELS_KEY = 'labelstats:labels';

    public function __construct(private ICacheLabelIndex $inner, private Repository $stats) {}

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

        foreach (array_unique($labels) as $label) {
            $this->count('flushes', $label);
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

    private function count(string $metric, string $label): void
    {
        try {
            $key = self::PREFIX . $metric . ':' . $label;
            $first_time = $this->stats->add($key, 0, 86400 * 30);
            $this->stats->increment($key);

            if (! $first_time) {
                return;
            }

            $labels = $this->stats->get(self::LABELS_KEY, []);
            $labels = is_array($labels) ? $labels : [];

            if (! in_array($label, $labels, true)) {
                $labels[] = $label;
                $this->stats->put(self::LABELS_KEY, $labels, 86400 * 30);
            }
        } catch (Throwable $throwable) {
            if (! $throwable instanceof RedisException && ! (class_exists(PredisException::class) && $throwable instanceof PredisException)) {
                throw $throwable;
            }
        }
    }
}
