<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use function config;

use Modules\Core\Models\SearchQueryStat;
use Modules\Core\Overrides\Command;
use Override;

/**
 * Prune the daily aggregates of the search registry older than the retention window. Pruning is always
 * allowed, also while the registry is switched off, so rows recorded earlier still expire.
 */
final class PruneSearchQueryStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    #[Override]
    protected $signature = 'search:prune-registry';

    /**
     * The console command description.
     */
    #[Override]
    protected $description = 'Prune search registry rows older than the retention window <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $value = config('core.search.registry.retention_days', 90);
        $retention_days = is_numeric($value) ? (int) $value : 90;

        if ($retention_days <= 0) {
            return self::SUCCESS;
        }

        $deleted = SearchQueryStat::query()
            ->where('day', '<', now()->subDays($retention_days)->toDateString())
            ->delete();
        $deleted_count = is_int($deleted) ? $deleted : 0;

        $this->info("Pruned {$deleted_count} search registry row(s).");

        return self::SUCCESS;
    }
}
