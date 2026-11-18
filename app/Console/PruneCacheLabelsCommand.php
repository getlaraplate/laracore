<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Overrides\Command;
use Override;

/**
 * Drop expired cache entries and label memberships.
 */
final class PruneCacheLabelsCommand extends Command
{
    #[Override]
    protected $signature = 'cache:prune-labels';

    #[Override]
    protected $description = 'Prune expired cache entries and label memberships <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(ICacheLabelIndex $index): int
    {
        $this->info('Pruned ' . $index->pruneStale() . ' cache entrie(s).');

        return self::SUCCESS;
    }
}
