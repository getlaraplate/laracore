<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Modules\Core\SoftDeletes\Console\ModelSoftDeletesRefreshCommand;
use Modules\Core\Tests\Stubs\Console\ConstructorConfiguredSoftDeleteModel;
use Symfony\Component\Console\Application as SymfonyConsoleApplication;

/**
 * Runs `model:soft-deletes-remove` on a stub model, its migration written to a filesystem that keeps nothing.
 */
function softDeletesRemove(string $model): PendingCommand
{
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('put')->andReturn(1);
    $files->shouldReceive('makeDirectory')->andReturn(true);
    app()->instance(Filesystem::class, $files);

    return test()->artisan('model:soft-deletes-remove', [
        'model' => $model,
        '--namespace' => 'Modules\\Core\\Tests\\Stubs\\Console',
    ]);
}

it('registers the soft delete commands', function (): void {
    expect(array_keys(Artisan::all()))->toContain('model:soft-deletes-add', 'model:soft-deletes-remove', 'model:soft-deletes-refresh');
});

/**
 * The stub table with one live row and two in the bin.
 */
function softDeletesStubTable(): void
{
    Schema::create('mass_write_stub', static function (Blueprint $table): void {
        $table->id();
        $table->timestamp('deleted_at')->nullable();
    });
    DB::table('mass_write_stub')->insert([
        ['deleted_at' => null],
        ['deleted_at' => now()],
        ['deleted_at' => now()],
    ]);
}

it('keeps soft deletes and the records in the bin when the purge is refused', function (): void {
    softDeletesStubTable();

    try {
        softDeletesRemove('MassWriteStubModel')
            ->expectsOutputToContain('Found 2 logical-deleted records on `mass_write_stub`.')
            ->expectsConfirmation('Purge those records with hard delete before removing soft delete columns?', 'no')
            ->expectsOutputToContain('Operation cancelled. No columns were removed.')
            ->assertFailed()
            ->run();

        expect(DB::table('mass_write_stub')->count())->toBe(3);
    } finally {
        Schema::dropIfExists('mass_write_stub');
    }
});

it('leaves the trait, the migrations, the setting and the records in the bin untouched when the purge is refused', function (): void {
    Schema::create('soft_deletes_stub', static function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
        $table->boolean('is_deleted')->default(false);
    });
    DB::table('soft_deletes_stub')->insert([['name' => 'trashed', 'deleted_at' => now(), 'is_deleted' => true]]);
    $name = Modules\Core\Services\PerModelSettingResolver::nameFor(Modules\Core\Database\Seeders\CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, 'soft_deletes_stub');
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => $name, 'value' => true, 'type' => 'boolean', 'group_name' => 'soft_deletes']);
    $model_file = (string) new ReflectionClass(Modules\Core\Tests\Stubs\SoftDeletesStubModel::class)->getFileName();
    $source = file_get_contents($model_file);
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldNotReceive('put');
    app()->instance(Filesystem::class, $files);

    try {
        $this->artisan('model:soft-deletes-remove', ['model' => 'SoftDeletesStubModel', '--namespace' => 'Modules\\Core\\Tests\\Stubs'])
            ->expectsConfirmation('Purge those records with hard delete before removing soft delete columns?', 'no')
            ->assertFailed()
            ->run();

        expect(file_get_contents($model_file))->toBe($source)
            ->and(DB::table('soft_deletes_stub')->count())->toBe(1)
            ->and(Modules\Core\Models\Setting::query()->where('name', $name)->exists())->toBeTrue();
    } finally {
        Schema::dropIfExists('soft_deletes_stub');
    }
});

it('deletes the setting of the table and names it', function (): void {
    $name = Modules\Core\Services\PerModelSettingResolver::nameFor(Modules\Core\Database\Seeders\CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, 'mass_write_stub');
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => $name, 'value' => true, 'type' => 'boolean', 'group_name' => 'soft_deletes']);

    softDeletesRemove('MassWriteStubModel')
        ->expectsOutputToContain('Runtime setting ' . $name . ' deleted.')
        ->assertSuccessful()
        ->run();

    expect(Modules\Core\Models\Setting::query()->where('name', $name)->exists())->toBeFalse();
});

it('purges the records in the bin before it removes soft deletes', function (): void {
    softDeletesStubTable();

    try {
        softDeletesRemove('MassWriteStubModel')
            ->expectsConfirmation('Purge those records with hard delete before removing soft delete columns?', 'yes')
            ->expectsOutputToContain('Purged 2 record(s).')
            ->assertSuccessful()
            ->run();

        expect(DB::table('mass_write_stub')->whereNotNull('deleted_at')->count())->toBe(0)
            ->and(DB::table('mass_write_stub')->count())->toBe(1);
    } finally {
        Schema::dropIfExists('mass_write_stub');
    }
});

it('refresh command merges application quiet option without duplicate definition', function (): void {
    $command = app(ModelSoftDeletesRefreshCommand::class);
    $command->setLaravel(app());
    $command->setApplication(new SymfonyConsoleApplication('coverage', '1.0.0'));
    $command->mergeApplicationDefinition();

    expect($command->getDefinition()->hasOption('quiet'))->toBeTrue();
});

it('removes soft deletes from the table and connection its model sets in its constructor', function (): void {
    config()->set('database.connections.soft_delete_constructor_connection', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    DB::purge('soft_delete_constructor_connection');

    try {
        softDeletesRemove(ConstructorConfiguredSoftDeleteModel::class)
            ->expectsOutputToContain('_remove_soft_deletes_columns_from_soft_delete_constructor_table.php')
            ->assertSuccessful();
    } finally {
        DB::disconnect('soft_delete_constructor_connection');
        DB::purge('soft_delete_constructor_connection');
    }
});
