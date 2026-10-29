<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Modules\Core\Contracts\BootSampler;
use Modules\Core\Overrides\Command;
use Modules\Core\Performance\BenchmarkStats;
use Modules\Core\Performance\BootTime;
use Override;

/**
 * Measures framework boot time with reliable percentiles by sampling many fresh
 * sub-processes, amortizing the per-run noise that makes a single cold CLI boot
 * measurement untrustworthy.
 *
 *   php artisan perf:boot --runs=30
 */
final class PerfBootCommand extends Command
{
    #[Override]
    protected $signature = 'perf:boot
        {--runs=15 : Number of fresh sub-processes to sample}
        {--child : Internal: print this process boot time and exit}';

    #[Override]
    protected $description = 'Measure framework boot time across fresh processes (percentiles) <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(BootSampler $sampler): int
    {
        if ((bool) $this->option('child')) {
            $ms = BootTime::seconds($this->laravel) * 1000;
            $this->output->writeln('BOOT_MS=' . sprintf('%.3f', $ms));

            return self::SUCCESS;
        }

        $runs = max(1, (int) $this->option('runs'));
        $this->info(sprintf('Sampling boot time across %d fresh processes…', $runs));

        $samples = $sampler->sample($runs);

        if ($samples === []) {
            $this->error('No boot samples were collected. Could not read BOOT_MS from any sub-process.');

            return self::FAILURE;
        }

        $this->renderStats(BenchmarkStats::fromSamples($samples), count($samples), $runs);

        return self::SUCCESS;
    }

    private function renderStats(BenchmarkStats $stats, int $collected, int $runs): void
    {
        $this->table(
            ['runs', 'min (ms)', 'p50 (ms)', 'p95 (ms)', 'p99 (ms)', 'max (ms)', 'mean (ms)'],
            [[
                sprintf('%d/%d', $collected, $runs),
                sprintf('%.1f', $stats->min),
                sprintf('%.1f', $stats->p50),
                sprintf('%.1f', $stats->p95),
                sprintf('%.1f', $stats->p99),
                sprintf('%.1f', $stats->max),
                sprintf('%.1f', $stats->mean),
            ]],
        );
    }
}
