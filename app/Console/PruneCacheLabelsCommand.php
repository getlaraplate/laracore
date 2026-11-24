<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Support\Facades\Log;
use Modules\Core\Cache\Labels\EvictionPolicy;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Overrides\Command;
use Override;

/**
 * Drop expired cache entries and label memberships, and log a warning when the Redis of the index may evict its keys.
 */
final class PruneCacheLabelsCommand extends Command
{
    #[Override]
    protected $signature = 'cache:prune-labels';

    #[Override]
    protected $description = 'Prune expired cache entries and label memberships <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(ICacheLabelIndex $index): int
    {
        // Scheduled hourly on one server: the place a misconfigured Redis is reported at runtime without a cost per
        // request.
        $eviction_warning = EvictionPolicy::warningFor($index);

        if ($eviction_warning !== null) {
            Log::warning($eviction_warning);
            $this->warn($eviction_warning);
        }

        $this->info('Pruned ' . $index->pruneStale() . ' cache entrie(s).');

        return self::SUCCESS;
    }
}
