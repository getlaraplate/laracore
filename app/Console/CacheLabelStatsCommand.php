<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Modules\Core\Cache\Labels\EvictionPolicy;
use Modules\Core\Cache\Labels\MeasuredLabelIndex;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Overrides\Command;
use Override;

/**
 * Show cache label hits, misses and flushes, after a warning when the Redis of the index may evict its keys.
 */
final class CacheLabelStatsCommand extends Command
{
    #[Override]
    protected $signature = 'cache:label-stats';

    #[Override]
    protected $description = 'Show cache label hit, miss and flush counters <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(ICacheLabelIndex $index): int
    {
        if (! $index instanceof MeasuredLabelIndex) {
            $this->warn('The cache label index is not measured.');

            return self::FAILURE;
        }

        $eviction_warning = EvictionPolicy::warningFor($index);

        if ($eviction_warning !== null) {
            $this->warn($eviction_warning);
        }

        $rows = [];

        foreach ($index->report() as $label => $counters) {
            $rows[] = [$label, $counters['hits'], $counters['misses'], $counters['flushes']];
        }

        $this->table(['Label', 'Hits', 'Misses', 'Flushes'], $rows);

        return self::SUCCESS;
    }
}
