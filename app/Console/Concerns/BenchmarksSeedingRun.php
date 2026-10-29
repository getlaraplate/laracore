<?php

declare(strict_types=1);

namespace Modules\Core\Console\Concerns;

use Closure;
use Illuminate\Database\Connection;
use Modules\Core\Overrides\Seeder;

/**
 * One benchmark for a whole seeding run, printed by the command the operator typed.
 *
 * The outermost seeding command binds {@see Seeder::RUN_BENCHMARK_BINDING} while it runs: the
 * seeders it runs, and the `db:seed` calls nested in `module:seed` or `db:seed --dev`, skip their
 * own benchmark, so the run ends with one line holding the totals instead of one per seeder.
 */
trait BenchmarksSeedingRun
{
    use HasBenchmark;

    private int $seedingRunQueries = 0;

    private bool $countsSeedingRunQueries = false;

    /**
     * Queries are counted by a listener rather than read from the query log: a dev seed can
     * run millions of them, and the log would keep every one in memory until the run ends.
     * Replaces {@see HasBenchmark::getQueryCount()}.
     */
    protected function getQueryCount(Connection $connection): int
    {
        return $this->seedingRunQueries;
    }

    /**
     * @param  Closure(): int  $run
     */
    private function benchmarkSeedingRun(Closure $run): int
    {
        if (! config('app.debug') || app()->bound(Seeder::RUN_BENCHMARK_BINDING)) {
            return $run();
        }

        app()->instance(Seeder::RUN_BENCHMARK_BINDING, true);
        $this->seedingRunQueries = 0;
        $this->startBenchmark();

        if ($this->benchmarkConnection instanceof Connection) {
            $this->benchmarkConnection->disableQueryLog();

            if (! $this->countsSeedingRunQueries) {
                $this->countsSeedingRunQueries = true;
                $this->benchmarkConnection->listen(function (): void {
                    if (app()->bound(Seeder::RUN_BENCHMARK_BINDING)) {
                        $this->seedingRunQueries++;
                    }
                });
            }
        }

        try {
            return $run();
        } finally {
            app()->forgetInstance(Seeder::RUN_BENCHMARK_BINDING);
            $this->endBenchmark();
        }
    }
}
