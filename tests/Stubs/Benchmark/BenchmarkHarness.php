<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Benchmark;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Modules\Core\Console\Concerns\HasBenchmark;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class BenchmarkHarness extends Command
{
    use HasBenchmark;

    protected $signature = 'bench';

    protected $description = 'test';

    /**
     * Minimal DatabaseManager stand-in so DB::connection() and DB::__call hit the same mocked Connection.
     */
    public static function fakeDbManager(Connection $connection): object
    {
        return new class($connection)
        {
            public function __construct(private Connection $db_connection) {}

            /**
             * @param  array<int, mixed>  $parameters
             */
            public function __call(string $method, array $parameters): mixed
            {
                return $this->db_connection->{$method}(...$parameters);
            }

            public function connection($name = null): Connection
            {
                return $this->db_connection;
            }
        };
    }

    public function testCancelBenchmark(): void
    {
        $this->startBenchmark();
        $connection = $this->benchmarkConnection;
        $connection?->select('select 1');

        expect($connection?->logging())->toBeTrue()
            ->and($connection?->getQueryLog())->not->toBeEmpty();

        $this->cancelBenchmark();

        expect($this->benchmarkStartTime)->toBeNull()
            ->and($connection?->logging())->toBeFalse()
            ->and($connection?->getQueryLog())->toBeEmpty();
    }

    public function testStartEndWithoutTable(): void
    {
        $this->startBenchmark();
        $this->endBenchmark();
    }

    public function testStartEndWithTable(): void
    {
        Schema::create('bench_rows', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('bench_rows')->insert(['id' => 1]);

        $this->startBenchmark('bench_rows');
        DB::table('bench_rows')->insert(['id' => 2]);
        $this->endBenchmark();
    }

    public function testStartEndWithConnection(Connection $connection): void
    {
        $this->startBenchmark('bench_affinity_rows', $connection);
        $connection->table('bench_affinity_rows')->insert(['id' => 2]);
        $this->endBenchmark();
    }

    public function testStepBenchmarkWithoutStartIsNoOp(): void
    {
        $this->benchmarkStartTime = null;
        $this->stepBenchmark();
        expect($this->benchmarkStartTime)->toBeNull();
    }

    public function testStepBenchmarkAndRestart(): void
    {
        $this->startBenchmark();
        $this->stepBenchmarkAndRestart();
        expect($this->benchmarkStartTime)->not->toBeNull();
    }

    public function testGetQueryCountUsesSqliteBranch(): void
    {
        expect($this->queryCountForTest(DB::connection()))->toBeInt();
    }

    public function testFormatTimeBranches(): void
    {
        $method = new ReflectionMethod(HasBenchmark::class, 'formatTime');
        $method->setAccessible(true);
        expect($method->invoke(null, 90.0))->toContain('m')
            ->and($method->invoke(null, 2.5))->toContain('s')
            ->and($method->invoke(null, 0.05))->toContain('ms');
    }

    public function testStepBenchmarkUsesZeroQueriesWhenStartQueriesUnset(): void
    {
        $this->startBenchmark();
        $prop = new ReflectionProperty(self::class, 'startQueries');
        $prop->setAccessible(true);
        $prop->setValue($this, null);
        $this->stepBenchmark();
    }

    public function testStepBenchmarkSurvivesInvalidBenchmarkTable(): void
    {
        $this->startBenchmark();
        $table_prop = new ReflectionProperty(self::class, 'benchmarkTable');
        $table_prop->setAccessible(true);
        $table_prop->setValue($this, 'bench_missing_table_xyz');
        $row_prop = new ReflectionProperty(self::class, 'startRowCount');
        $row_prop->setAccessible(true);
        $row_prop->setValue($this, 0);
        $this->stepBenchmark();
    }

    public function testStartBenchmarkReturnsWhenDatabaseBindingThrows(Container $original_container): void
    {
        Container::setInstance(new class extends Container
        {
            public function bound($abstract): bool
            {
                throw new RuntimeException('container unavailable');
            }
        });

        try {
            $this->startBenchmark();
            expect($this->benchmarkStartTime)->toBeNull();
        } finally {
            Container::setInstance($original_container);
        }
    }

    public function testComposeOutputHandlesZeroMemoryUsage(): void
    {
        $method = new ReflectionMethod(self::class, 'composeOutput');
        $method->setAccessible(true);
        $method->invoke($this, 0.01, 0, 0, 0, 0.0);
    }

    public function testQueryCountAccuracy(int $count): void
    {
        DB::flushQueryLog();
        $this->startBenchmark();

        for ($i = 0; $i < $count; $i++) {
            DB::select('select 1');
        }

        $this->endBenchmark();
    }

    public function testGetQueryCountReturnsZeroForUnsupportedDriver(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('unsupported-driver');

        $original = DB::getFacadeRoot();
        DB::swap(self::fakeDbManager($connection));

        try {
            expect($this->queryCountForTest($connection))->toBe(0);
        } finally {
            DB::swap($original);
        }
    }

    public function testGetQueryCountUsesPgsqlBranch(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('select')->andReturn([(object) ['count' => 11]]);

        $original = DB::getFacadeRoot();
        DB::swap(self::fakeDbManager($connection));

        try {
            expect($this->queryCountForTest($connection))->toBe(11);
        } finally {
            DB::swap($original);
        }
    }

    public function testGetQueryCountUsesOracleBranch(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('oracle');
        $connection->shouldReceive('select')->andReturn([(object) ['count' => 4]]);

        $original = DB::getFacadeRoot();
        DB::swap(self::fakeDbManager($connection));

        try {
            expect($this->queryCountForTest($connection))->toBe(4);
        } finally {
            DB::swap($original);
        }
    }

    public function testGetQueryCountUsesDb2Branch(string $driver): void
    {
        $connection = Db2PretendConnection::make($driver);
        $connection->enableQueryLog();
        $connection->pretend(static function (Connection $pretending): void {
            $pretending->select('select 1 from sysibm.sysdummy1');
            $pretending->select('select 2 from sysibm.sysdummy1');
        });

        expect($this->queryCountForTest($connection))->toBe(2);
    }

    private function queryCountForTest(Connection $connection): int
    {
        return $this->getQueryCount($connection);
    }
}
