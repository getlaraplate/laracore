<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Setting;
use Modules\Core\Seeding\ModuleState;
use Modules\Core\Seeding\ModuleStateResolver;
use Modules\Core\Seeding\SettingsCleaner;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Tests\Stubs\Cache\RecordingLabelIndex;
use Modules\Core\Tests\Stubs\Seeding\FixedModuleStateResolver;

// Core's own writes that fire no model event flush the whole table they wrote, after the commit.

beforeEach(function (): void {
    $this->index = new RecordingLabelIndex();
    app()->instance(ICacheLabelIndex::class, $this->index);
});

function massWriteWholeTable(string $table): array
{
    return [CacheLabel::table($table), CacheLabel::rows($table)];
}

function massWriteSetting(string $name, ?string $module = null, mixed $baseline = null, mixed $value = 1): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => $name,
        'value' => $value,
        'encrypted' => false,
        'type' => SettingTypeEnum::Integer,
        'group_name' => 'base',
        'description' => 'Probe',
        'module' => $module,
        'seeded_value' => $baseline,
    ]);
}

/**
 * Runs a settings command on the stub model, its migration written to a filesystem that keeps nothing.
 */
function massWriteRunCommand(string $command): void
{
    $files = Mockery::mock(Illuminate\Filesystem\Filesystem::class)->makePartial();
    $files->shouldReceive('put')->andReturn(1);
    $files->shouldReceive('makeDirectory')->andReturn(true);
    app()->instance(Illuminate\Filesystem\Filesystem::class, $files);

    test()->artisan($command, [
        'model' => 'MassWriteStubModel',
        '--namespace' => 'Modules\\Core\\Tests\\Stubs\\Console',
    ])->assertSuccessful();
}

it('flushes the settings table after a settings command removes its setting', function (string $command, string $prefix): void {
    massWriteSetting(Modules\Core\Services\PerModelSettingResolver::nameFor($prefix, 'mass_write_stub'));
    $this->index->flushes = [];

    massWriteRunCommand($command);

    expect($this->index->flushedLabels())->toContain(...massWriteWholeTable((new Setting)->getTable()))
        ->and(Setting::query()->where('name', Modules\Core\Services\PerModelSettingResolver::nameFor($prefix, 'mass_write_stub'))->exists())->toBeFalse();
})->with([
    'locked remove' => ['model:locked-remove', Modules\Core\Database\Seeders\CoreDatabaseSeeder::LOCK_NAME_PREFIX],
    'optimistic lock remove' => ['model:optimistic-lock-remove', Modules\Core\Database\Seeders\CoreDatabaseSeeder::OPTIMISTIC_LOCK_NAME_PREFIX],
    'soft deletes remove' => ['model:soft-deletes-remove', Modules\Core\Database\Seeders\CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX],
]);

it('flushes the settings table after the optimistic lock command inserts its setting', function (): void {
    massWriteRunCommand('model:optimistic-lock-add');

    expect($this->index->flushedLabels())->toContain(...massWriteWholeTable((new Setting)->getTable()));
});

it('flushes the settings table after the cleaner moves customized settings to the bin', function (): void {
    massWriteSetting('mass_write_clean_' . uniqid(), 'MES', 5, 99);
    app()->instance(ModuleStateResolver::class, new FixedModuleStateResolver(ModuleState::Disabled));
    $this->index->flushes = [];

    $connection = (new Setting)->getConnection();
    $connection->transaction(function (): void {
        app(SettingsCleaner::class)->clean();

        expect($this->index->flushedLabels())->not->toContain(CacheLabel::rows((new Setting)->getTable()));
    });

    expect($this->index->flushedLabels())->toContain(...massWriteWholeTable((new Setting)->getTable()));
});

it('flushes the settings table after the public API setting created for a callback is removed', function (): void {
    Setting::query()->where('module', 'Core')->where('name', 'expose_api')->forceDelete();

    CrudApiExposure::runEnabled(function (): void {
        $this->index->flushes = [];
    });

    expect($this->index->flushedLabels())->toContain(...massWriteWholeTable((new Setting)->getTable()));
});

it('flushes the modifications table after a record deleted for good closes its pending changes', function (): void {
    $setting = massWriteSetting('mass_write_approvals_' . uniqid());
    $this->index->flushes = [];

    $setting->forceDelete();

    expect($this->index->flushedLabels())->toContain(...massWriteWholeTable((new Modification)->getTable()));
});
