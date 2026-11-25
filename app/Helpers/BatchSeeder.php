<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

use function Laravel\Prompts\progress;

use ErrorException;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Laravel\Prompts\Progress;
use LogicException;
use Modules\Core\Concurrency\BatchTask;
use Modules\Core\Concurrency\ErrorPolicy;
use Modules\Core\Concurrency\Exceptions\BatchExecutionFailedException;
use Modules\Core\Concurrency\ParallelTaskRunner;
use Modules\Core\Concurrency\Reporters\ProgressBarReporter;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Search\Traits\Searchable;
use Modules\Core\Services\DynamicContentsService;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

abstract class BatchSeeder extends Seeder
{
    /**
     * Container key under which {@see \Modules\Core\Console\SeedCommand} publishes
     * the resolved dev-seed volume multiplier. Read here rather than off the
     * console command, whose input the nested module:seed -> db:seed calls mutate.
     */
    public const string SCALE_CONTAINER_KEY = 'core.dev-seed-scale';

    private const int MAX_RETRIES = 3;

    private const int BATCHSIZE = 100;

    private const int RETRY_DELAY = 1; // seconds

    private ?string $childDatabaseConnectionName = null;

    /**
     * Put the logic of the seeder here.
     */
    abstract protected function execute(): void;

    /**
     * Run the seeder.
     */
    final public function run(): void
    {
        try {
            $this->execute();
            $this->console()->newLine();
            $this->console()->info('All data seeded successfully!');
        } catch (Throwable $throwable) {
            $this->console()->error('Error during seeding: ' . $throwable->getMessage() . ' in ' . $throwable->getFile() . ' on line ' . $throwable->getLine() . PHP_EOL . $throwable->getTraceAsString());
            Log::error('Seeding error: ' . $throwable->getMessage(), [
                'exception' => $throwable,
                'trace' => $throwable->getTraceAsString(),
            ]);
        }
    }

    /**
     * The console command running the seeder. A batch seeder runs only from `db:seed` or `module:seed`, which set it.
     */
    final protected function console(): Command
    {
        if (! $this->command instanceof Command) {
            throw new LogicException(static::class . ' runs only from a console command.');
        }

        return $this->command;
    }

    /**
     * Hook executed once inside each forked child process before the batch task runs.
     *
     * The default implementation reconnects the database (PDO links inherited
     * from the parent are not safe to reuse after a fork) and purges Cache/Redis
     * managers so workers open fresh connections. Override in your seeder if you
     * need additional resets (e.g. queue or search engine clients). Avoid
     * destructive operations like Cache::flush() which would truncate shared
     * stores (Redis FLUSHDB).
     *
     * Forked workers inherit a copy of this seeder instance (including HasBenchmark
     * state). Child processes share STDOUT with the parent; without cancelling the
     * benchmark here, {@see Seeder::__destruct} could emit
     * duplicate benchmark lines (one per worker) when the child exits.
     *
     * Sets {@see Seeder::PARALLEL_BATCH_WORKER_ENV} so the
     * destructor can skip benchmark output even if timing state was inconsistent.
     */
    protected function bootstrapChildProcess(): void
    {
        putenv(self::PARALLEL_BATCH_WORKER_ENV . '=1');
        $this->cancelBenchmark();

        if ($this->childDatabaseConnectionName !== null) {
            DB::reconnect($this->childDatabaseConnectionName);
        }

        // Redis sockets inherited from the parent are not fork-safe; purge so
        // the next Cache/Redis access opens a fresh connection in this child.
        // Cache::purge() without a name only drops the default store wrapper —
        // failover's underlying redis/array repositories survive and must be
        // purged explicitly so children do not reuse poisoned sockets/state.
        Cache::purge();
        Cache::purge('redis');
        Cache::purge('array');

        $this->purgeForkedRedisConnections();

        // Cache::memo() is a scoped container binding that survives Cache::purge().
        // After fork it still wraps the parent's dead Redis connection; forget it so
        // the next memo() rebuilds against fresh stores. Reset DynamicContentsService
        // so workers do not reuse in-memory buckets paired with that stale memo store
        // (persistent cache can rehydrate Eloquent collections as plain arrays).
        $default_driver = (string) config('cache.default');
        app()->forgetInstance('cache.__memoized:' . $default_driver);
        DynamicContentsService::reset();

        // PermissionRegistrar keeps its own Repository from construction; after
        // Cache::purge() that handle still points at the parent's dead store.
        if (app()->bound(PermissionRegistrar::class)) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->initializeCache();
            $registrar->clearPermissionsCollection();
        }
    }

    /**
     * Create the data in batches sequentially.
     *
     * @param  class-string<Model>  $modelClass  The model class to create the data for
     * @param  int  $totalCount  The total number of data to create
     * @param  int|null  $batchSize  The size of the batch to create
     */
    final protected function createInBatches(
        string $modelClass,
        int $totalCount,
        ?int $batchSize = null,
    ): int {
        $totalCount = $this->scaleTargetCount($totalCount);
        $current_count = $this->countCurrentRecords($modelClass);
        $count_to_create = $this->countToCreate($totalCount, $current_count);
        $entity_name = new ReflectionClass($modelClass)->newInstanceWithoutConstructor()->getTable();

        if ($count_to_create <= 0) {
            $this->console()->info($entity_name . ' already at target count.');

            return 0;
        }

        $start_time = microtime(true);

        $progress = progress('Creating ' . $entity_name, $count_to_create);
        $progress->start();

        $created = 0;
        $batch = 0;
        $effective_batch_size = $batchSize ?? self::BATCHSIZE;

        while ($created < $count_to_create) {
            $remaining = $count_to_create - $created;
            $current_batch_size = min($effective_batch_size, $remaining);

            $this->executeBatch(
                $modelClass,
                $entity_name,
                $batch,
                $current_batch_size,
                $current_count,
                $count_to_create,
                $created,
                $remaining,
                $progress,
            );
            $batch++;
        }

        if ($created > 0) {
            $progress->label("Successfully created {$created} {$entity_name} records in " . microtime(true) - $start_time . ' seconds');
            $progress->render();
            Sleep::usleep(250_000);
        }

        $progress->finish();

        return $created;
    }

    /**
     * Create the data in parallel batches using the ParallelTaskRunner worker pool.
     *
     * @param  class-string<Model>  $modelClass  The model class to create the data for
     * @param  int  $totalCount  The total number of data to create
     * @param  int|null  $batchSize  The size of the batch to create
     * @param  int  $maxParallelCount  The maximum number of concurrent worker processes
     */
    final protected function createInParallelBatches(
        string $modelClass,
        int $totalCount,
        ?int $batchSize = null,
        int $maxParallelCount = 10,
    ): int {
        $totalCount = $this->scaleTargetCount($totalCount);
        $current_count = $this->countCurrentRecords($modelClass);
        $count_to_create = $this->countToCreate($totalCount, $current_count);
        $model = new $modelClass();
        $entity_name = $model->getTable();
        $db_connection_name = $model->getConnection()->getName();

        if ($count_to_create <= 0) {
            $this->console()->info($entity_name . ' already at target count.');

            return 0;
        }

        $effective_batch_size = $batchSize ?? self::BATCHSIZE;
        $total_batches = (int) ceil($count_to_create / $effective_batch_size);

        $tasks = $this->buildSeederTasks($modelClass, $count_to_create, $effective_batch_size, $total_batches);

        return $this->runParallelTasks(
            $tasks,
            'Creating ' . $entity_name . ' (parallel)',
            $db_connection_name,
            $effective_batch_size,
            $maxParallelCount,
        );
    }

    /**
     * Run pre-built BatchTasks through the fork-based worker pool, wiring the
     * shared reporter, fail-fast policy and per-fork bootstrap. Records the
     * child DB connection so {@see self::bootstrapChildProcess()} reconnects the
     * correct one after each fork.
     *
     * @param  list<BatchTask>  $tasks
     */
    final protected function runParallelTasks(
        array $tasks,
        string $label,
        string $connectionName,
        int $batchSize,
        int $maxParallelCount = 10,
    ): int {
        if ($tasks === []) {
            return 0;
        }

        $this->childDatabaseConnectionName = $connectionName;

        $reporter = new ProgressBarReporter(
            label: $label,
            extraHint: 'Up to ' . $maxParallelCount . ' workers, ' . count($tasks) . ' batches',
        );

        try {
            $summary = ParallelTaskRunner::make()
                ->concurrent($maxParallelCount)
                ->withResourceSizing($connectionName, $batchSize)
                ->errorPolicy(ErrorPolicy::FailFast)
                ->reportTo($reporter)
                ->beforeChild(fn () => $this->bootstrapChildProcess())
                ->run($tasks);
        } catch (BatchExecutionFailedException $e) {
            $error = $e->outcome->error;
            $message = $error['message'] ?? 'unknown error';
            $file = $error['file'] ?? '?';
            $line = (int) ($error['line'] ?? 0);

            $this->console()->error("Task {$e->outcome->taskId} failed: {$message} in {$file} on line {$line}");

            if (($trace = $e->trace()) !== '') {
                $this->console()->error($trace);
            }

            Log::error('BatchSeeder parallel run failed', [
                'task_id' => $e->outcome->taskId,
                'error' => $error,
            ]);

            exit(1);
        }

        return $summary->totalUnitsProcessed;
    }

    /**
     * Run $callback with versioning disabled for each given model class, then
     * restore each class's prior state. Dev bulk data needs no version history,
     * and creating snapshots adds many writes per record; this mirrors the Scout
     * ModelObserver::disableSyncingFor() pattern the dev seeders already use.
     * Disabling in the parent before a parallel run is inherited by every fork.
     *
     * @param  list<class-string>  $modelClasses
     */
    final protected function withoutModelVersioning(array $modelClasses, callable $callback): mixed
    {
        $previous = [];

        foreach ($modelClasses as $model_class) {
            /** @phpstan-ignore staticMethod.notFound */
            $previous[$model_class] = $model_class::getVersioning();

            /** @phpstan-ignore staticMethod.notFound */
            $model_class::disableVersioning();
        }

        try {
            return $callback();
        } finally {
            foreach ($previous as $model_class => $was_enabled) {
                /** @phpstan-ignore staticMethod.notFound */
                $was_enabled ? $model_class::enableVersioning() : $model_class::disableVersioning();
            }
        }
    }

    /**
     * Drop every configured Redis connection after fork.
     *
     * {@see \Illuminate\Redis\RedisManager::purge()} with no argument only
     * clears the `default` connection. The cache store uses `cache` (see
     * config/cache.php), so leaving it inherited after pcntl_fork lets parent
     * and children share a TCP socket — replies can be stolen and a GET for an
     * int generation counter can unserialize as an Eloquent Collection.
     */
    private function purgeForkedRedisConnections(): void
    {
        if (! app()->bound('redis')) {
            return;
        }

        $redis = app('redis');

        foreach (array_keys(config('database.redis', [])) as $name) {
            if (in_array($name, ['client', 'options'], true)) {
                continue;
            }

            $redis->purge($name);
        }
    }

    /**
     * Build the BatchTask list for parallel seeding.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<BatchTask>
     */
    private function buildSeederTasks(
        string $modelClass,
        int $count_to_create,
        int $effective_batch_size,
        int $total_batches,
    ): array {
        $tasks = [];
        $remaining = $count_to_create;

        for ($i = 0; $i < $total_batches; $i++) {
            $size = min($effective_batch_size, $remaining);

            if ($size <= 0) {
                break;
            }

            $remaining -= $size;

            $tasks[] = new BatchTask(
                id: "batch_{$i}",
                units: $size,
                run: fn (): int => $this->executeSingleBatch($modelClass, $size),
            );
        }

        return $tasks;
    }

    /**
     * Execute a single batch in an isolated forked process.
     *
     * Connection bootstrap is centralised in bootstrapChildProcess(), invoked
     * once per fork by ParallelTaskRunner via beforeChild().
     */
    private function executeSingleBatch(string $modelClass, int $batchSize): int
    {
        $model_instance = new $modelClass();

        /** @phpstan-ignore staticMethod.notFound */
        $factory = $model_instance->factory();

        /** @var \Illuminate\Database\Eloquent\Factories\Factory<Model> $factory */
        $new_models = $factory->count($batchSize)->create();

        if ($new_models->isNotEmpty() && method_exists($factory, 'createDynamicContentRelations')) {
            $factory->createDynamicContentRelations($new_models);
        }

        unset($new_models, $factory, $model_instance);
        gc_collect_cycles();

        return $batchSize;
    }

    private function countCurrentRecords(string $modelClass): int
    {
        return $modelClass::query()->withoutGlobalScopes()->count();
    }

    private function countToCreate(int $totalCount, int $currentCount): int
    {
        $currentCount = $this->console()->option('force') ? 0 : $currentCount;

        return $totalCount - $currentCount;
    }

    /**
     * Apply the resolved dev-seed scale to a seeder's target count. A positive
     * target is floored to one record so a scaled-down run never empties a table
     * other seeders depend on; a zero target stays zero.
     */
    private function scaleTargetCount(int $totalCount): int
    {
        if ($totalCount < 1) {
            return $totalCount;
        }

        return max(1, (int) round($totalCount * $this->resolveSeedScale()));
    }

    /**
     * Resolve the dev-seed volume multiplier published by
     * {@see \Modules\Core\Console\SeedCommand}. Absent (any non dev run) the
     * target count is used in full.
     */
    private function resolveSeedScale(): float
    {
        return app()->bound(self::SCALE_CONTAINER_KEY)
            ? (float) app(self::SCALE_CONTAINER_KEY)
            : 1.0;
    }

    private function isSearchable(string $modelClass): bool
    {
        return class_uses_trait($modelClass, Searchable::class);
    }

    private function getCacheConnectionName(string $modelClass): string
    {
        if (! $this->isSearchable($modelClass)) {
            return config('cache.default');
        }

        return new $modelClass()->searchableConnection() ?? config('cache.default');
    }

    /**
     * Execute a batch of data creation in the synchronous path.
     *
     * @param  class-string<Model>  $modelClass  The model class to create the data for
     * @param  string  $entityName  The name of the entity to create the data for
     * @param  int  $batch  The batch number
     * @param  int  $batchSize  The size of the batch to create
     * @param  int  $currentCount  The current number of records in the database
     * @param  int  $countToCreate  The total number of records to create
     * @param  int  $created  The number of records created so far
     * @param  int  $remaining  The number of records remaining to create
     * @param  Progress  $progress  The progress bar
     * @param  bool  $asyncMode  Whether to run the batch with isolated async connections
     * @param  callable|null  $force_kill_batches  Optional callback invoked on failure
     */
    private function executeBatch(string &$modelClass, string &$entityName, int &$batch, int &$batchSize, int &$currentCount, int &$countToCreate, int &$created, int &$remaining, Progress $progress, bool $asyncMode = false, ?callable $force_kill_batches = null): void
    {
        $retry_count = 0;
        $success = false;

        if ($remaining <= 0) {
            return;
        }

        $model = new $modelClass();
        $connections = [
            'database' => $model->getConnection()->getName(),
            'cache' => $this->getCacheConnectionName($modelClass),
        ];

        while (! $success && $retry_count < self::MAX_RETRIES) {
            try {
                if ($asyncMode) {
                    $connections = $this->setupAsyncConnections($connections['database'], $connections['cache']);
                }

                $model_instance = new $modelClass();
                $model_instance->setConnection($connections['database']);

                if ($this->isSearchable($modelClass)) {
                    $model_instance->setCacheConnection($connections['cache']);
                }
                $factory = $model_instance->factory();

                /** @var \Illuminate\Database\Eloquent\Factories\Factory<Model> $factory */
                $new_models = $factory->count($batchSize)->create();

                if ($new_models->isNotEmpty() && method_exists($factory, 'createDynamicContentRelations')) {
                    $factory->createDynamicContentRelations($new_models);
                }

                $success = true;
                $created += $batchSize;

                $progress->hint('');
                $progress->advance($batchSize);
            } catch (QueryException|ErrorException|ValidationException $e) {
                if ($force_kill_batches !== null) {
                    $force_kill_batches($e, $batch);

                    return;
                }

                $message = sprintf('Failed to create %s batch {', $entityName) . ($batch + 1) . '}: ' . $e->getMessage();
                Log::error($message);
                $progress->hint("<fg=red>{$message}</>");
                $progress->render();

                throw $e;
            } catch (Throwable $e) {
                $retry_count++;

                if ($retry_count >= self::MAX_RETRIES) {
                    $this->console()->error($e->getTraceAsString());

                    if ($force_kill_batches !== null) {
                        $force_kill_batches($e, $batch);

                        return;
                    }

                    $message = sprintf('Failed to create %s batch {', $entityName) . ($batch + 1) . '}: ' . $e->getMessage();
                    Log::error($message);
                    $progress->hint("<fg=red>{$message}</>");
                    $progress->render();

                    throw $e;
                }

                if ($asyncMode) {
                    $this->resetAsyncConnections($connections['database'], $connections['cache']);
                }

                $created_before = $created;
                $created = $this->countCurrentRecords($modelClass) - $currentCount;
                $progress->advance($created - $created_before);
                $message = sprintf('Retry %d for %s batch {', $retry_count, $entityName) . ($batch + 1) . '}: ' . $e->getMessage();
                Log::warning($message);
                $progress->hint("<fg=yellow>{$message}</>");
                $progress->render();

                $remaining = $countToCreate - $created;
                $batchSize = min(self::BATCHSIZE, $remaining);

                Sleep::sleep(self::RETRY_DELAY);
            } finally {
                gc_collect_cycles();
            }
        }
    }

    /**
     * Setup fresh connections for async operations.
     *
     * @return list{database:string,cache:string}
     */
    private function setupAsyncConnections(string $db_connection_name, string $cache_connection_name): array
    {
        return [
            'database' => $this->setupAsyncDatabaseConnection($db_connection_name),
            'cache' => $this->setupAsyncCacheConnection($cache_connection_name),
        ];
    }

    /**
     * Setup a fresh database connection for async operations.
     */
    private function setupAsyncDatabaseConnection(string $connection_name): string
    {
        config([sprintf('database.connections.%s.prepared_statements', $connection_name) => false]);

        $tmp_connection_name = 'async_db_' . uniqid();
        config([
            'database.connections.' . $tmp_connection_name => config('database.connections.' . $connection_name),
        ]);

        DB::purge($tmp_connection_name);
        DB::setDefaultConnection($tmp_connection_name);
        DB::reconnect($tmp_connection_name);

        return $tmp_connection_name;
    }

    /**
     * Setup a fresh Redis connection for async operations.
     */
    private function setupAsyncCacheConnection(string $cache_connection_name): string
    {
        $tmp_cache_connection_name = 'async_cache_' . uniqid();
        $cache_config = config('cache.stores.' . $cache_connection_name);

        config([
            'cache.stores.' . $tmp_cache_connection_name => $cache_config,
        ]);

        Cache::purge();

        return $tmp_cache_connection_name;
    }

    /**
     * Reset all connections for async operations.
     */
    private function resetAsyncConnections(string $db_connection_name, string $cache_connection_name): void
    {
        $this->resetAsyncDatabaseConnection($db_connection_name);
        $this->resetAsyncCacheConnection($cache_connection_name);
    }

    /**
     * Reset the database connection for async operations.
     */
    private function resetAsyncDatabaseConnection(string $connection_name): void
    {
        $current_connection = DB::connection($connection_name);
        $current_connection->disconnect();

        DB::reconnect($connection_name);
    }

    /**
     * Reset the Redis connection for async operations.
     */
    private function resetAsyncCacheConnection(string $connection_name): void
    {
        /** @var Repository $store */
        $store = Cache::store($connection_name);
        $cache_connection = $store->getConnection();
        $cache_connection->disconnect();

        $cache_connection->connect();
    }
}
