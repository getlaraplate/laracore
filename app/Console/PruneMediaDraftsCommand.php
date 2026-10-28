<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use function config;

use Modules\Core\Models\MediaDraft;
use Modules\Core\Overrides\Command;
use Override;

/**
 * Prune stale pending-media drafts (and their staged media) older than the
 * configured TTL. Drafts are deleted through the model so the Spatie media
 * lifecycle removes the associated files.
 */
final class PruneMediaDraftsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    #[Override]
    protected $signature = 'core:prune-media-drafts';

    /**
     * The console command description.
     */
    #[Override]
    protected $description = 'Prune stale pending media drafts and their staged media <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $ttl_hours = (int) config('core.media.draft_ttl_hours', 24);

        if ($ttl_hours <= 0) {
            return self::SUCCESS;
        }

        $threshold = now()->subHours($ttl_hours);
        $deleted = 0;

        MediaDraft::query()
            ->where('created_at', '<', $threshold)
            ->get()
            ->each(static function (MediaDraft $draft) use (&$deleted): void {
                $draft->delete();
                $deleted++;
            });

        $this->info("Pruned {$deleted} stale media draft(s).");

        return self::SUCCESS;
    }
}
