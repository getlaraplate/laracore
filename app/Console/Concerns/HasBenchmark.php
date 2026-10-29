<?php

declare(strict_types=1);

namespace Modules\Core\Console\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Connection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Performance\BootTime;
use Throwable;

trait HasBenchmark
{
    protected ?float $bootTime = null;

    protected ?float $benchmarkStartTime = null;

    protected ?int $benchmarkStartMemory = null;

    protected ?int $startQueries = null;

    protected ?int $startRowCount = null;

    protected ?string $benchmarkTable = null;

    protected ?Connection $benchmarkConnection = null;

    public function cancelBenchmark(): void
    {
        if ($this->benchmarkConnection instanceof Connection) {
            $this->benchmarkConnection->disableQueryLog();
            $this->benchmarkConnection->flushQueryLog();
        }

        $this->benchmarkStartTime = null;
        $this->benchmarkStartMemory = null;
        $this->startQueries = null;
        $this->startRowCount = null;
        $this->benchmarkTable = null;
        $this->benchmarkConnection = null;
    }

    /**
     * Start the benchmark.
     */
    protected function startBenchmark(?string $table = null, ?Connection $connection = null): void
    {
        if (! self::databaseIsAvailable()) {
            return;
        }

        if (! $this->bootTime) {
            $this->bootTime = BootTime::seconds(app());
        }

        $this->benchmarkStartTime = microtime(true);
        $this->benchmarkTable = $table;
        $this->benchmarkStartMemory = memory_get_usage();
        $this->benchmarkConnection = $connection ?? DB::connection(DB::getDefaultConnection());

        if (! in_array($table, [null, '', '0'], true)) {
            $this->startRowCount = $this->benchmarkConnection->table($table)->count();
        }

        $this->benchmarkConnection->enableQueryLog();

        $this->startQueries = $this->getQueryCount($this->benchmarkConnection);
    }

    protected function stepBenchmark(): void
    {
        if (! $this->benchmarkStartTime) {
            return;
        }

        $executionTime = microtime(true) - $this->benchmarkStartTime;
        $usage = memory_get_usage() - $this->benchmarkStartMemory;

        try {
            if (isset($this->startQueries)) {
                // Get row count after we've stopped tracking queries
                // $benchmarkConnection is nullable: without one there are no queries to count.
                $queriesCount = $this->benchmarkConnection instanceof Connection
                    ? $this->getQueryCount($this->benchmarkConnection) - $this->startQueries + ($this->startQueries > 0 ? -1 : 0) // Subtract the Questions query itself
                    : 0;
            } else {
                $queriesCount = 0;
            }

            $rowDiff = $this->benchmarkTable && isset($this->startRowCount) && $this->benchmarkConnection instanceof Connection
                ? $this->benchmarkConnection->table($this->benchmarkTable)->count() - $this->startRowCount
                : 0;
        } catch (Throwable) {
            $queriesCount = 0;
            $rowDiff = 0;
        }

        if ($this->benchmarkConnection instanceof Connection) {
            $this->benchmarkConnection->flushQueryLog();
        }

        // bootTime is null until the framework reports it; zero is the honest reading.
        $this->composeOutput($executionTime, $usage, $queriesCount, $rowDiff, $this->bootTime ?? 0.0);
    }

    protected function stepBenchmarkAndRestart(): void
    {
        $this->stepBenchmark();
        $this->startBenchmark(connection: $this->benchmarkConnection);
    }

    /**
     * End the benchmark.
     */
    protected function endBenchmark(): void
    {
        $this->stepBenchmark();

        // $this->cancelBenchmark();
        if ($this->benchmarkConnection instanceof Connection) {
            $this->benchmarkConnection->disableQueryLog();
        }
    }

    protected function getQueryCount(Connection $connection): int
    {
        return match ($connection->getDriverName()) {
            'mysql', 'sqlite' => count($connection->getQueryLog()), // Requires Connection::enableQueryLog()
            'pgsql' => (int) $connection->select(
                'SELECT pg_stat_get_db_xact_commit(pg_backend_pid()) + pg_stat_get_db_xact_rollback(pg_backend_pid()) as count',
            )[0]->count,
            'oracle' => (int) $connection->select(
                "SELECT value AS count FROM v\$sesstat WHERE statistic# = (SELECT statistic# FROM v\$statname WHERE name = 'execute count') AND sid = (SELECT sid FROM v\$mystat WHERE ROWNUM = 1)",
            )[0]->count ?? 0,
            default => 0,
        };
    }

    private static function databaseIsAvailable(): bool
    {
        try {
            return app()->bound('db');
        } catch (Throwable) {
            return false;
        }
    }

    private static function formatTime(float $time): string
    {
        return match (true) {
            $time >= 60 => sprintf('%dm %ds', (int) ($time / 60), (int) ($time - ((int) ($time / 60) * 60))),
            $time >= 1 => round($time, 2) . 's',
            default => round($time * 1000) . 'ms',
        };
    }

    /**
     * Compose the output for the benchmark.
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     */
    private function composeOutput(float $executionTime, int $memoryUsage, int $queriesCount, int $rowDiff, float $bootTime): void
    {
        // Convert memory usage to a more readable format
        $unit = ['b', 'K', 'M', 'G', 'T', 'P'];

        if ($memoryUsage <= 0) {
            $usage = '0b';
        } else {
            $i = (int) floor(log($memoryUsage, 1024));
            $i = max(0, min($i, count($unit) - 1));
            $usage = round($memoryUsage / (1024 ** $i), 2) . $unit[$i];
        }

        // Format boot time
        $formattedBootTime = self::formatTime($bootTime);

        // Format execution time
        $formattedTime = self::formatTime($executionTime);

        // create badges
        $is_in_console = app()->runningInConsole();
        $output_values = [];
        $output = '⚡';
        $this->addBlockToOutput($output, $output_values, 'BOOT', $formattedBootTime, 'bright-blue', 'black', $is_in_console);
        $this->addBlockToOutput($output, $output_values, 'TIME', $formattedTime, 'bright-blue', 'black', $is_in_console);
        $this->addBlockToOutput($output, $output_values, 'MEM', $usage, 'bright-green', 'black', $is_in_console);

        if ($queriesCount !== 0) {
            $this->addBlockToOutput($output, $output_values, 'SQL', number_format($queriesCount), 'bright-magenta', 'black', $is_in_console);
        }

        if ($rowDiff !== 0) {
            $this->addBlockToOutput($output, $output_values, 'ROWS', number_format($rowDiff), 'bright-magenta', 'black', $is_in_console);
        }

        $this->addBlockToOutput($output, $output_values, '', static::class, 'gray', 'black', $is_in_console);
        $output = sprintf($output, ...$output_values);

        $this->displayOutput($output);
    }

    private function displayOutput(string $output): void
    {
        $command = null;

        if (app()->runningInConsole()) {
            if ($this instanceof Seeder) {
                if ($this->command instanceof \Illuminate\Console\Command) {
                    $command = $this->command;
                }
            } elseif ($this instanceof \Illuminate\Console\Command && $this->output !== null) {
                $command = $this;
            }

            if ($command instanceof \Illuminate\Console\Command) {
                $command->newLine();
                $command->line($output);
                $command->newLine();
            }
        }
    }

    /**
     * Add a formatted block to the output.
     */
    /**
     * The values end up in sprintf(), which takes scalars: typing them as mixed only
     * moves the complaint one call down.
     *
     * @param  list<bool|float|int|string|null>  $outputValues
     */
    private function addBlockToOutput(string &$output, array &$outputValues, string $blockName, bool|float|int|string|null $blockValue, string $blockBgColor, string $blockFgColor, bool $isInConsole): void
    {
        if ($blockName !== '' && $blockName !== '0') {
            $blockName .= ' ';
        }

        $output .= $isInConsole ? sprintf(' <bg=%s;fg=%s> %s%%s </>', $blockBgColor, $blockFgColor, $blockName) : sprintf(' %s%%s', $blockName);
        $outputValues[] = $blockValue;
    }
}
