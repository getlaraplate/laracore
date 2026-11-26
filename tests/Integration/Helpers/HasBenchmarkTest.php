<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Console\Concerns\HasBenchmark;
use Modules\Core\Support\Db2Drivers;
use Modules\Core\Tests\Stubs\Benchmark\BenchmarkHarness;
use Modules\Core\Tests\Stubs\Benchmark\BenchmarkSeederHarness;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function prepare_benchmark_command(BenchmarkHarness $command): BufferedOutput
{
    $input = new ArrayInput([]);
    $input->bind($command->getDefinition());
    $command->setInput($input);
    $buffer = new BufferedOutput;
    $command->setOutput(new OutputStyle($input, $buffer));

    return $buffer;
}

beforeEach(function (): void {
    Schema::dropIfExists('bench_rows');
});

it('cancels benchmark and clears start time', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testCancelBenchmark();
});

it('runs benchmark without table and prints output to the console', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testStartEndWithoutTable();

    expect($output->fetch())->toContain('BOOT')->not->toBeEmpty();
});

it('counts rows when benchmark table is set', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testStartEndWithTable();

    expect($output->fetch())->toContain('BOOT')->not->toBeEmpty();
});

it('keeps benchmark row counts and query logging on the supplied connection', function (): void {
    config()->set('database.connections.benchmark_affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('benchmark_affinity');
    $connection = DB::connection('benchmark_affinity');

    $connection->getSchemaBuilder()->create('bench_affinity_rows', function (Blueprint $table): void {
        $table->id();
    });
    $connection->table('bench_affinity_rows')->insert(['id' => 1]);

    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);

    try {
        $cmd->testStartEndWithConnection($connection);

        expect($output->fetch())->toContain('ROWS 1');
    } finally {
        DB::disconnect('benchmark_affinity');
        DB::purge('benchmark_affinity');
    }
});

it('uses the database manager runtime default when no connection is supplied', function (): void {
    config()->set('database.connections.benchmark_runtime_default', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('benchmark_runtime_default');
    $runtime_connection = DB::connection('benchmark_runtime_default');
    $database_manager = DB::getFacadeRoot();
    $runtime_manager = Mockery::mock();
    $runtime_manager->shouldReceive('getDefaultConnection')->andReturn('benchmark_runtime_default');
    $runtime_manager->shouldReceive('connection')
        ->with('benchmark_runtime_default')
        ->andReturn($runtime_connection);
    DB::swap($runtime_manager);

    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $start = new ReflectionMethod($cmd, 'startBenchmark');
    $start->setAccessible(true);
    $connection = new ReflectionProperty($cmd, 'benchmarkConnection');
    $connection->setAccessible(true);

    try {
        $start->invoke($cmd);

        expect($connection->getValue($cmd)?->getName())->toBe('benchmark_runtime_default');
    } finally {
        $cmd->testCancelBenchmark();
        DB::swap($database_manager);
        DB::disconnect('benchmark_runtime_default');
        DB::purge('benchmark_runtime_default');
    }
});

it('stepBenchmark returns early when not started', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testStepBenchmarkWithoutStartIsNoOp();
});

it('stepBenchmarkAndRestart keeps timing running', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testStepBenchmarkAndRestart();

    expect($output->fetch())->toContain('BOOT')->not->toBeEmpty();
});

it('exposes sqlite query count and time formatting', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testGetQueryCountUsesSqliteBranch();
    $cmd->testFormatTimeBranches();
});

it('does not expose the query-count test helper in production', function (): void {
    expect((new ReflectionClass(HasBenchmark::class))->hasMethod('queryCountFor'))->toBeFalse();
});

it('treats missing startQueries as zero in stepBenchmark', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testStepBenchmarkUsesZeroQueriesWhenStartQueriesUnset();

    expect($output->fetch())->toContain('BOOT')->not->toBeEmpty();
});

it('stepBenchmark tolerates missing benchmark table when counting rows', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testStepBenchmarkSurvivesInvalidBenchmarkTable();

    expect($output->fetch())->toContain('BOOT')->not->toBeEmpty();
});

it('skips starting when the database binding cannot be checked', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testStartBenchmarkReturnsWhenDatabaseBindingThrows(Container::getInstance());
});

it('formats zero memory usage in benchmark output', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testComposeOutputHandlesZeroMemoryUsage();

    expect($output->fetch())->toContain('MEM 0b');
});

it('skips writing to console when command output is not set', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);

    $output_prop = new ReflectionProperty(BenchmarkHarness::class, 'output');
    $output_prop->setAccessible(true);
    $output_prop->setValue($cmd, null);

    $console_prop = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $console_prop->setAccessible(true);
    $console_prop->setValue($this->app, true);

    $display = new ReflectionMethod(BenchmarkHarness::class, 'displayOutput');
    $display->setAccessible(true);
    $display->invoke($cmd, 'bench');

    expect(app()->runningInConsole())->toBeTrue();
});

it('returns zero from getQueryCount for unsupported drivers', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testGetQueryCountReturnsZeroForUnsupportedDriver();
});

it('uses pgsql and oracle branches in getQueryCount', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);
    $cmd->testGetQueryCountUsesPgsqlBranch();
    $cmd->testGetQueryCountUsesOracleBranch();
});

it('reports the exact sql query count in the benchmark output', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);

    $cmd->testQueryCountAccuracy(3);

    expect($output->fetch())->toContain('SQL 3');
});

it('writes benchmark output when used from a seeder with command set', function (): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    $output = prepare_benchmark_command($cmd);

    $seeder = new BenchmarkSeederHarness;
    $seeder->setContainer($this->app);
    $seeder->runBenchmark($cmd);

    expect($output->fetch())->toContain('BOOT')->not->toBeEmpty();
});

it('does not throw when a seeder has no command instance', function (): void {
    $seeder = new class extends Seeder
    {
        use HasBenchmark;

        public function emitBenchmarkOutput(string $output): void
        {
            $method = new ReflectionMethod($this, 'displayOutput');
            $method->setAccessible(true);
            $method->invoke($this, $output);
        }
    };

    expect(fn () => $seeder->emitBenchmarkOutput('bench output'))->not->toThrow(Error::class);
});

it('counts the query log on a Db2 connection', function (string $driver): void {
    $cmd = new BenchmarkHarness;
    $cmd->setLaravel($this->app);
    prepare_benchmark_command($cmd);

    $cmd->testGetQueryCountUsesDb2Branch($driver);
})->with(Db2Drivers::DRIVERS);
