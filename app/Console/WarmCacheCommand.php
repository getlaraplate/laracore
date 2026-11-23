<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Log;
use Modules\Core\Actions\Cron\LoadActiveCronJobsAction;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Models\CronJob;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Command;
use Modules\Core\Services\PerModelSettingResolver;
use Override;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * Artisan command to pre-warm critical runtime cache entries.
 *
 * Warms the following entries, through the label index:
 * 1. The settings name index and every settings group, as {@see PerModelSettingResolver} reads them (the version
 *    strategies are the `versioning` group)
 * 2. Cron jobs
 */
final class WarmCacheCommand extends Command
{
    #[Override]
    protected $signature = 'cache:warm';

    #[Override]
    protected $description = 'Pre-warm critical runtime cache entries (settings, cron jobs) <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(): int
    {
        if ($this->output === null) {
            $this->setOutput(new OutputStyle(new StringInput(''), new NullOutput()));
        }

        $start = microtime(true);
        $total_warmed = 0;
        $failed_steps = 0;
        $total_steps = 2;

        $this->info('Warming cache entries...');

        // Step 1: Warm all Settings (all groups)
        try {
            $warmed = $this->warmSettings();
            $total_warmed += $warmed;
            $this->line("  <fg=green>✓</fg=green> Settings: {$warmed} entries warmed.");
        } catch (Throwable $e) {
            $failed_steps++;
            Log::error('cache:warm — failed to warm settings', ['exception' => $e->getMessage()]);
            $this->line('  <fg=red>✗</fg=red> Settings: failed (' . $e->getMessage() . ')');
        }

        // Step 2: Warm cron jobs
        try {
            $warmed = $this->warmCronJobs();
            $total_warmed += $warmed;
            $this->line("  <fg=green>✓</fg=green> Cron jobs: {$warmed} entries warmed.");
        } catch (Throwable $e) {
            $failed_steps++;
            Log::error('cache:warm — failed to warm cron jobs', ['exception' => $e->getMessage()]);
            $this->line('  <fg=red>✗</fg=red> Cron jobs: failed (' . $e->getMessage() . ')');
        }

        $elapsed = round((microtime(true) - $start) * 1000, 2);

        $this->newLine();
        $this->info("Cache warming complete: {$total_warmed} entries populated in {$elapsed}ms.");

        if ($failed_steps === $total_steps) {
            $this->error('All warming steps failed.');

            return BaseCommand::FAILURE;
        }

        return BaseCommand::SUCCESS;
    }

    /**
     * Warm the settings name index and every settings group the resolver reads, through a resolver of its own so the
     * in-memory groups of the running process do not hide a missing entry.
     * Returns the number of cache entries read or written.
     */
    private function warmSettings(): int
    {
        if (! SchemaInspector::getInstance()->hasTable(CoreTables::Settings->value)) {
            return 0;
        }

        $resolver = new PerModelSettingResolver();
        $groups = $resolver->collection()->map(static fn (Setting $setting): string => (string) $setting->group_name)->unique();

        return 1 + $groups->count();
    }

    /**
     * Warm cron jobs into the persistent cache.
     * Mirrors the logic in CoreServiceProvider::registerCommandSchedules().
     * Returns the number of cache entries written.
     */
    private function warmCronJobs(): int
    {
        if (! SchemaInspector::getInstance()->hasTable(CoreTables::CronJobs->value)) {
            return 0;
        }

        $cache_key = new CronJob()->getTable();

        $crons = resolve(LabelledCache::class)->refresh(
            $cache_key,
            [CacheLabel::table($cache_key)],
            (int) config('cache.duration.long'),
            static fn (): array => resolve(LoadActiveCronJobsAction::class)(),
            static fn (mixed $crons): bool => $crons !== [],
        );

        return $crons === [] ? 0 : 1;
    }
}
