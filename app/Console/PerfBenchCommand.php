<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Core\Overrides\Command;
use Modules\Core\Performance\EndpointBenchmarkReport;
use Modules\Core\Performance\EndpointProfiler;
use Override;

/**
 * Benchmarks HTTP endpoints by dispatching real requests through the kernel and
 * reporting latency percentiles, per-request query counts and peak memory.
 *
 * Example:
 *   php artisan test:perf:bench GET:/api/v1/health POST:/app/... --iterations=50
 */
final class PerfBenchCommand extends Command
{
    #[Override]
    protected $signature = 'test:perf:bench
        {endpoint* : One or more METHOD:URI targets, e.g. GET:/api/v1/health}
        {--iterations=30 : Number of measured iterations per endpoint}
        {--warmup=3 : Number of unmeasured warmup iterations per endpoint}
        {--user= : Authenticate profiled requests as this user id}
        {--json : Output raw JSON instead of a formatted table}';

    #[Override]
    protected $description = 'Benchmark HTTP endpoints through the real kernel (latency, queries, memory) <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(EndpointProfiler $profiler, AuthFactory $auth): int
    {
        $iterations = max(1, (int) $this->option('iterations'));
        $warmup = max(0, (int) $this->option('warmup'));

        $user = null;
        $user_id = $this->option('user');

        if ($user_id !== null && $user_id !== '') {
            $user = $this->resolveUser($auth, (string) $user_id);

            if (! $user instanceof Authenticatable) {
                $this->error(sprintf('No authenticatable user found with id %s.', $user_id));

                return self::FAILURE;
            }
        }

        /** @var list<string> $endpoints */
        $endpoints = array_values((array) $this->argument('endpoint'));

        $reports = [];

        foreach ($endpoints as $spec) {
            $parsed = $this->parseSpec($spec);

            if ($parsed === null) {
                $this->error(sprintf("Invalid endpoint spec '%s'. Expected METHOD:URI, e.g. GET:/api/v1/health.", $spec));

                return self::FAILURE;
            }

            [$method, $uri] = $parsed;
            $reports[] = $profiler->profile($method, $uri, $iterations, $warmup, $user);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(array_map($this->toArray(...), $reports), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderTable($reports);

        return self::SUCCESS;
    }

    private function resolveUser(AuthFactory $auth, string $userId): ?Authenticatable
    {
        $guard = $auth->guard();

        if (! method_exists($guard, 'getProvider')) {
            return null;
        }

        return $guard->getProvider()->retrieveById($userId);
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseSpec(string $spec): ?array
    {
        if (! str_contains($spec, ':')) {
            return null;
        }

        [$method, $uri] = explode(':', $spec, 2);
        $method = mb_trim($method);
        $uri = mb_trim($uri);

        if ($method === '' || $uri === '' || preg_match('/^[A-Za-z]+$/', $method) !== 1) {
            return null;
        }

        return [$method, $uri];
    }

    /**
     * @param  list<EndpointBenchmarkReport>  $reports
     */
    private function renderTable(array $reports): void
    {
        $rows = [];

        foreach ($reports as $report) {
            $stats = $report->benchmark->durationStats;
            $rows[] = [
                $report->method,
                $report->uri,
                (string) $report->lastStatus,
                sprintf('%.2f', $stats->p50),
                sprintf('%.2f', $stats->p95),
                sprintf('%.2f', $stats->max),
                sprintf('%.1f', $report->benchmark->queryStats->mean),
                sprintf('%.1f', $report->benchmark->peakMemoryBytes / 1048576),
            ];
        }

        $this->table(
            ['method', 'uri', 'status', 'p50 (ms)', 'p95 (ms)', 'max (ms)', 'queries', 'peak MB'],
            $rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(EndpointBenchmarkReport $report): array
    {
        $stats = $report->benchmark->durationStats;

        return [
            'method' => $report->method,
            'uri' => $report->uri,
            'status' => $report->lastStatus,
            'iterations' => $report->benchmark->iterations,
            'warmup' => $report->benchmark->warmup,
            'duration_ms' => [
                'min' => $stats->min,
                'mean' => $stats->mean,
                'p50' => $stats->p50,
                'p95' => $stats->p95,
                'p99' => $stats->p99,
                'max' => $stats->max,
            ],
            'queries_mean' => $report->benchmark->queryStats->mean,
            'peak_memory_bytes' => $report->benchmark->peakMemoryBytes,
        ];
    }
}
