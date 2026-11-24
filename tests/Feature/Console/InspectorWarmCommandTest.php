<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Console\InspectorWarmCommand;
use Symfony\Component\Console\Command\Command as BaseCommand;

/**
 * Stub schema builder to avoid Mockery duplicate-class issues and to drive Schema::connection()->getTables().
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function inspector_warm_schema_builder_stub(array $rows): SchemaBuilder
{
    return new class(app('db')->connection(), $rows) extends SchemaBuilder
    {
        /**
         * @param  array<int, array<string, mixed>>  $table_rows
         */
        public function __construct(Connection $connection, private array $table_rows)
        {
            parent::__construct($connection);
        }

        public function getTables($schema = null): array
        {
            return $this->table_rows;
        }
    };
}

function registerInspectorWarmCommand(): void
{
    /** @var ConsoleKernel $kernel */
    $kernel = app(ConsoleKernel::class);

    // Avoid duplicate registration if already present
    foreach ($kernel->all() as $name => $command) {
        if ($name === 'inspector:warm') {
            return;
        }
    }

    $kernel->registerCommand(app(InspectorWarmCommand::class));
}

it('returns success and prints message when no tables to warm', function (): void {
    registerInspectorWarmCommand();

    $schema_builder = inspector_warm_schema_builder_stub([]);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema_builder);

    DB::shouldReceive('connection')->withAnyArgs()->andReturn($connection);
    DB::shouldReceive('getConnections')->zeroOrMoreTimes()->andReturn([]);
    Schema::shouldReceive('dropIfExists')
        ->zeroOrMoreTimes();
    Schema::shouldReceive('table')
        ->zeroOrMoreTimes();
    Schema::shouldReceive('drop')
        ->zeroOrMoreTimes();

    $this->artisan('inspector:warm')
        ->expectsOutputToContain('No tables to warm.')
        ->assertExitCode(BaseCommand::SUCCESS);
});

it('warms inspector cache for discovered tables', function (): void {
    registerInspectorWarmCommand();

    $schema_builder = inspector_warm_schema_builder_stub([
        ['name' => 'users'],
        ['name' => 'posts'],
    ]);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema_builder);

    DB::shouldReceive('connection')->withAnyArgs()->andReturn($connection);
    DB::shouldReceive('getConnections')->zeroOrMoreTimes()->andReturn([]);
    Schema::shouldReceive('dropIfExists')
        ->zeroOrMoreTimes();
    Schema::shouldReceive('table')
        ->zeroOrMoreTimes();
    Schema::shouldReceive('drop')
        ->zeroOrMoreTimes();

    $tester = $this->artisan('inspector:warm');

    $tester->assertExitCode(BaseCommand::SUCCESS);
});

it('returns success with no-tables message when getTables returns entries without name key', function (): void {
    registerInspectorWarmCommand();

    $schema_builder = inspector_warm_schema_builder_stub([
        ['schema' => 'public'],
    ]);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema_builder);

    DB::shouldReceive('connection')->withAnyArgs()->andReturn($connection);
    DB::shouldReceive('getConnections')->zeroOrMoreTimes()->andReturn([]);
    Schema::shouldReceive('dropIfExists')
        ->zeroOrMoreTimes();
    Schema::shouldReceive('table')
        ->zeroOrMoreTimes();
    Schema::shouldReceive('drop')
        ->zeroOrMoreTimes();

    $tester = $this->artisan('inspector:warm');

    $tester->assertExitCode(BaseCommand::SUCCESS);
});

it('uses provided connection option when warming inspector cache', function (): void {
    registerInspectorWarmCommand();

    $schema_builder = inspector_warm_schema_builder_stub([
        ['name' => 'users'],
    ]);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getSchemaBuilder')->atLeast()->once()->andReturn($schema_builder);

    DB::shouldReceive('connection')->with('inspector_secondary')->atLeast()->once()->andReturn($connection);
    DB::shouldReceive('connection')->withAnyArgs()->zeroOrMoreTimes()->andReturn($connection);
    DB::shouldReceive('getConnections')->zeroOrMoreTimes()->andReturn([]);
    Schema::shouldReceive('dropIfExists')->zeroOrMoreTimes();
    Schema::shouldReceive('table')->zeroOrMoreTimes();
    Schema::shouldReceive('drop')->zeroOrMoreTimes();

    $tester = $this->artisan('inspector:warm', ['--connection' => 'inspector_secondary']);
    $tester->assertExitCode(BaseCommand::SUCCESS);
});
