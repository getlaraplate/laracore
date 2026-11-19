<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\BatchSeeder;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Services\DynamicContentsService;
use Spatie\Permission\PermissionRegistrar;

uses(Tests\TestCase::class);

afterEach(function (): void {
    putenv(Seeder::PARALLEL_BATCH_WORKER_ENV);
});

it('keeps the legacy no-argument bootstrap hook contract', function (): void {
    $bootstrap = new ReflectionMethod(BatchSeeder::class, 'bootstrapChildProcess');

    expect($bootstrap->getNumberOfRequiredParameters())->toBe(0);
});

it('bootstrapChildProcess clears benchmark state inherited after fork simulation', function (): void {
    config()->set('app.debug', true);

    $seeder = new class(app(DatabaseManager::class)) extends BatchSeeder
    {
        public function __destruct()
        {
            // Skip parent destructor: Pest tears down the app before object destruction,
            // and Seeder::__destruct() calls config() which is no longer available.
        }

        protected function execute(): void {}
    };

    $benchmark_prop = (new ReflectionObject($seeder))->getProperty('benchmarkStartTime');
    $benchmark_prop->setAccessible(true);

    expect($benchmark_prop->getValue($seeder))->not->toBeNull();

    $bootstrap = new ReflectionMethod(BatchSeeder::class, 'bootstrapChildProcess');
    $bootstrap->setAccessible(true);
    $connection_name = (new ReflectionClass(BatchSeeder::class))->getProperty('childDatabaseConnectionName');
    $connection_name->setValue($seeder, app(DatabaseManager::class)->getDefaultConnection());
    $bootstrap->invoke($seeder);

    expect($benchmark_prop->getValue($seeder))->toBeNull();
    expect(getenv(Seeder::PARALLEL_BATCH_WORKER_ENV))->toBe('1');
});

it('bootstrapChildProcess reconnects the resolved worker connection', function (): void {
    $seeder = new class(app(DatabaseManager::class)) extends BatchSeeder
    {
        public function __destruct() {}

        protected function execute(): void {}
    };

    $bootstrap = new ReflectionMethod(BatchSeeder::class, 'bootstrapChildProcess');
    $bootstrap->setAccessible(true);
    $connection_name = (new ReflectionClass(BatchSeeder::class))->getProperty('childDatabaseConnectionName');
    $connection_name->setValue($seeder, 'batch_affinity');
    $original = DB::getFacadeRoot();
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('reconnect')->once()->with('batch_affinity');
    DB::swap($database);

    try {
        $bootstrap->invoke($seeder);
    } finally {
        DB::swap($original);
    }
});

it('bootstrapChildProcess rebuilds Cache::memo and resets DynamicContentsService', function (): void {
    config()->set('cache.default', 'array');
    $default_driver = (string) config('cache.default');

    $memo_before = Cache::memo();
    $service_before = DynamicContentsService::getInstance();

    $seeder = new class(app(DatabaseManager::class)) extends BatchSeeder
    {
        public function __destruct() {}

        protected function execute(): void {}
    };

    $bootstrap = new ReflectionMethod(BatchSeeder::class, 'bootstrapChildProcess');
    $bootstrap->setAccessible(true);
    $connection_name = (new ReflectionClass(BatchSeeder::class))->getProperty('childDatabaseConnectionName');
    $connection_name->setValue($seeder, app(DatabaseManager::class)->getDefaultConnection());
    $bootstrap->invoke($seeder);

    expect(Cache::memo())->not->toBe($memo_before)
        ->and(DynamicContentsService::getInstance())->not->toBe($service_before)
        ->and(app()->bound('cache.__memoized:' . $default_driver))->toBeTrue();
});

it('bootstrapChildProcess purges the redis cache connection, not only default', function (): void {
    if (! app()->bound('redis')) {
        $this->markTestSkipped('Redis is not bound in this environment.');
    }

    $redis = app('redis');
    $cache_connection = (string) config('cache.stores.redis.connection', 'cache');

    // Put both connections in the manager's local cache without opening a socket:
    // purge() only forgets the cached instance, so stand-ins prove the same thing
    // and the test never reaches a Redis server.
    $connections_prop = (new ReflectionObject($redis))->getProperty('connections');
    $connections_prop->setAccessible(true);
    $connections_prop->setValue($redis, [
        'default' => Mockery::mock(Connection::class),
        $cache_connection => Mockery::mock(Connection::class),
    ]);

    expect($connections_prop->getValue($redis))->toHaveKeys(['default', $cache_connection]);

    $seeder = new class(app(DatabaseManager::class)) extends BatchSeeder
    {
        public function __destruct() {}

        protected function execute(): void {}
    };

    $bootstrap = new ReflectionMethod(BatchSeeder::class, 'bootstrapChildProcess');
    $bootstrap->setAccessible(true);
    $connection_name = (new ReflectionClass(BatchSeeder::class))->getProperty('childDatabaseConnectionName');
    $connection_name->setValue($seeder, app(DatabaseManager::class)->getDefaultConnection());
    $bootstrap->invoke($seeder);

    $connections_after = $connections_prop->getValue($redis);

    expect($connections_after)->not->toHaveKey('default')
        ->and($connections_after)->not->toHaveKey($cache_connection);
});

it('bootstrapChildProcess reinitializes PermissionRegistrar cache handle', function (): void {
    $registrar = app(PermissionRegistrar::class);
    $cache_before = (new ReflectionObject($registrar))->getProperty('cache');
    $cache_before->setAccessible(true);
    $repo_before = $cache_before->getValue($registrar);

    $seeder = new class(app(DatabaseManager::class)) extends BatchSeeder
    {
        public function __destruct() {}

        protected function execute(): void {}
    };

    $bootstrap = new ReflectionMethod(BatchSeeder::class, 'bootstrapChildProcess');
    $bootstrap->setAccessible(true);
    $connection_name = (new ReflectionClass(BatchSeeder::class))->getProperty('childDatabaseConnectionName');
    $connection_name->setValue($seeder, app(DatabaseManager::class)->getDefaultConnection());
    $bootstrap->invoke($seeder);

    $repo_after = $cache_before->getValue(app(PermissionRegistrar::class));

    expect($repo_after)->not->toBe($repo_before);
});
