<?php

declare(strict_types=1);

namespace Modules\Core\Concurrency\Sizing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compute safe parallelism limits based on local resources.
 *
 * The sizer caps the requested parallelism by:
 *  - the number of usable CPU cores (one is reserved for the orchestrator);
 *  - the number of available connections on the target database (Postgres
 *    and MySQL are introspected; other drivers, Oracle and Db2 included, fall back to the CPU cap).
 *
 * The optional batch-size attribute is reduced proportionally when the
 * effective parallelism shrinks, so a single worker doesn't end up holding a
 * batch that's too large for the time/memory budget originally planned.
 */
final readonly class ResourceSizer
{
    private const int MIN_BATCH_SIZE = 10;

    private const float BATCH_REDUCTION_FACTOR = 0.7;

    /**
     * @param  int  $requestedParallel  Caller-requested parallelism
     * @param  int  $cpuParallel  Cap based on CPU cores
     * @param  int  $dbParallel  Cap based on database connection availability
     * @param  int  $effectiveParallel  Final cap (min of the two)
     * @param  int  $originalBatchSize  Caller-requested batch size
     * @param  int  $effectiveBatchSize  Adjusted batch size (proportional to the reduction)
     * @param  list<string>  $warnings  Human-readable rationale messages
     */
    public function __construct(
        public int $requestedParallel,
        public int $cpuParallel,
        public int $dbParallel,
        public int $effectiveParallel,
        public int $originalBatchSize,
        public int $effectiveBatchSize,
        public array $warnings,
    ) {}

    /**
     * Build a new sizing decision.
     *
     * @param  string|null  $connection  Connection name; null skips the database cap
     */
    public static function compute(int $requestedParallel, int $batchSize, ?string $connection = null): self
    {
        $requested = max(1, $requestedParallel);
        $batch = max(self::MIN_BATCH_SIZE, $batchSize);
        $warnings = [];

        $cpu_parallel = self::capByCpu($requested);

        if ($cpu_parallel < $requested) {
            $warnings[] = sprintf(
                'Reduced parallel count from %d to %d (CPU cores).',
                $requested,
                $cpu_parallel,
            );
        }

        $db_parallel = $connection !== null
            ? self::capByDatabase($connection, $cpu_parallel)
            : $cpu_parallel;

        if ($db_parallel < $cpu_parallel) {
            $warnings[] = sprintf(
                'Reduced parallel count from %d to %d (database connection limits on "%s").',
                $cpu_parallel,
                $db_parallel,
                $connection ?? 'default',
            );
        }

        $effective = $db_parallel;

        $reduction = 1.0 - ($effective / $requested);
        $effective_batch = max(
            self::MIN_BATCH_SIZE,
            (int) round($batch * (1.0 - ($reduction * self::BATCH_REDUCTION_FACTOR))),
        );

        if ($effective_batch !== $batch) {
            $warnings[] = sprintf(
                'Reduced batch size from %d to %d (proportional to parallelism reduction).',
                $batch,
                $effective_batch,
            );
        }

        return new self(
            requestedParallel: $requested,
            cpuParallel: $cpu_parallel,
            dbParallel: $db_parallel,
            effectiveParallel: $effective,
            originalBatchSize: $batch,
            effectiveBatchSize: $effective_batch,
            warnings: $warnings,
        );
    }

    /**
     * Detect the number of usable CPU cores, leaving one for the orchestrator.
     */
    public static function detectCpuCores(): int
    {
        if (! function_exists('shell_exec')) {
            return 1;
        }

        if (str_contains(PHP_OS_FAMILY, 'Linux')) {
            return (int) shell_exec('nproc') ?: 1;
        }

        if (str_contains(PHP_OS_FAMILY, 'Darwin')) {
            return (int) shell_exec('sysctl -n hw.ncpu') ?: 1;
        }

        if (str_contains(PHP_OS_FAMILY, 'Windows')) {
            return (int) getenv('NUMBER_OF_PROCESSORS') ?: 1;
        }

        return 1;
    }

    private static function capByCpu(int $requested): int
    {
        $cores = self::detectCpuCores();

        return min($requested, max(1, $cores - 1));
    }

    private static function capByDatabase(string $connectionName, int $currentLimit): int
    {
        try {
            $connection = DB::connection($connectionName);
            $driver = $connection->getDriverName();

            if ($driver === 'pgsql') {
                $max_connections = (int) ($connection->selectOne('SHOW max_connections')->max_connections ?? 0);
                $reserved = (int) ($connection->selectOne('SHOW superuser_reserved_connections')->superuser_reserved_connections ?? 0);
                $active = (int) ($connection->selectOne('SELECT sum(numbackends) AS active_backends FROM pg_stat_database')->active_backends ?? 0);

                $available = max(1, $max_connections - $reserved - $active - 1);

                return max(1, min($currentLimit, $available));
            }

            if ($driver === 'mysql') {
                $max_connections = (int) ($connection->selectOne("SHOW VARIABLES LIKE 'max_connections'")->Value ?? 0);
                $active = (int) ($connection->selectOne("SHOW STATUS LIKE 'Threads_connected'")->Value ?? 0);

                $available = max(1, $max_connections - $active - 1);

                return max(1, min($currentLimit, $available));
            }

            return $currentLimit;
        } catch (Throwable $e) {
            Log::warning('ResourceSizer: unable to determine DB parallel limit, keeping CPU cap.', [
                'connection' => $connectionName,
                'error' => $e->getMessage(),
            ]);

            return $currentLimit;
        }
    }
}
