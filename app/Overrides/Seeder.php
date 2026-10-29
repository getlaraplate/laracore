<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use function is_laraplate_owned_module;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder as BaseSeeder;
use Modules\Core\Console\Concerns\BenchmarksSeedingRun;
use Modules\Core\Console\Concerns\HasBenchmark;
use Modules\Core\Database\Seeders\Concerns\HasSeedersUtils;
use Modules\Core\Helpers\BatchSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Seeding\SeedDefinition;

class Seeder extends BaseSeeder
{
    use HasBenchmark;
    use HasSeedersUtils;

    /**
     * Environment variable set by {@see BatchSeeder::bootstrapChildProcess}
     * in fork workers so destructors skip benchmark output (shared STDOUT with parent).
     */
    public const string PARALLEL_BATCH_WORKER_ENV = 'LARAPLE_PARALLEL_BATCH_WORKER';

    /**
     * Container key bound while a seeding command benchmarks the whole run
     * ({@see BenchmarksSeedingRun}): a seeder created inside it skips its own benchmark.
     */
    public const string RUN_BENCHMARK_BINDING = 'core.seeding.run_benchmark';

    protected bool $disableBenchmark = false;

    public function __construct(protected DatabaseManager $db)
    {
        $this->db = $db;

        if (config('app.debug') && ! $this->disableBenchmark && ! app()->bound(self::RUN_BENCHMARK_BINDING)) {
            $this->startBenchmark();
        }
    }

    public function __destruct()
    {
        if ($this->isParallelBatchForkWorker()) {
            return;
        }

        if ($this->benchmarkStartTime !== null) {
            $this->endBenchmark();
        }
    }

    /**
     * Reconcile definition for the settings a module ships.
     *
     * `is_internal` follows the declaring module's ownership, not the fact that a
     * seeder wrote the row: {@see is_laraplate_owned_module()} reads `module.json`
     * `laraplate_owned`, falling back to a `getlaraplate/lara*` composer name. A
     * third-party module shipping its own settings through this definition gets
     * `is_internal = false`. The flag is structural, so a re-seed realigns rows
     * written before it existed and follows a module that changes ownership.
     * `managed` (default false) marks a value a command writes: it is realigned like the type,
     * while the value itself is written on insert only. `group_name` is written on insert only: operators regroup settings freely and
     * a re-seed keeps their choice. The action a setting runs is code-owned and
     * realigned like its type.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    protected static function internalSettingsDefinition(string $module, array $rows): SeedDefinition
    {
        return self::settingsDefinition(
            $module,
            $rows,
            ['type', 'description', 'choices', 'is_internal', 'managed', 'action_command', 'action_queued'],
        );
    }

    /**
     * Same as {@see internalSettingsDefinition()} for settings whose choices a command
     * refreshes: `choices` is written when the row is created and never realigned, so a
     * re-seed cannot overwrite the list the command wrote.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    protected static function commandManagedChoicesSettingsDefinition(string $module, array $rows): SeedDefinition
    {
        return self::settingsDefinition(
            $module,
            $rows,
            ['type', 'description', 'is_internal', 'managed', 'action_command', 'action_queued'],
        );
    }

    /**
     * Rows without an action get explicit defaults: an upsert needs every row to carry the
     * same columns, and a row whose action was removed must realign it to none.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $structural
     */
    private static function settingsDefinition(string $module, array $rows, array $structural): SeedDefinition
    {
        $is_internal = is_laraplate_owned_module($module);

        return SeedDefinition::for(Setting::class)
            ->identity(['name'])
            ->structural($structural)
            ->initial(['value'])
            ->ownedBy($module)
            ->rows(array_map(
                static fn (array $row): array => [
                    'action_command' => null,
                    'action_queued' => false,
                    'managed' => false,
                    ...$row,
                    'is_internal' => $is_internal,
                ],
                $rows,
            ));
    }

    /**
     * Whether this PHP process is a spatie/fork worker started by BatchSeeder.
     */
    private function isParallelBatchForkWorker(): bool
    {
        $flag = getenv(self::PARALLEL_BATCH_WORKER_ENV);

        return $flag === '1' || $flag === 'true';
    }
}
