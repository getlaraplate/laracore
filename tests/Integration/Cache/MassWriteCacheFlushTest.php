<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Locking\Console\LockedRemoveCommand;
use Modules\Core\Locking\Console\OptimisticLockAddCommand;
use Modules\Core\Locking\Console\OptimisticLockRemoveCommand;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Setting;
use Modules\Core\Seeding\ModuleState;
use Modules\Core\Seeding\ModuleStateResolver;
use Modules\Core\Seeding\SettingsCleaner;
use Modules\Core\SoftDeletes\Console\ModelSoftDeletesRemoveCommand;
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

it('flushes the settings table after a settings command removes its setting', function (string $command_class, string $prefix): void {
    $command = app($command_class);
    $table = 'mass_write_' . uniqid();
    massWriteSetting(Modules\Core\Services\PerModelSettingResolver::nameFor($prefix, $table));
    $this->index->flushes = [];

    (new ReflectionMethod($command, 'updateSettingsTable'))->invoke($command, ...($command_class === ModelSoftDeletesRemoveCommand::class ? [$table] : [$table, false]));

    expect($this->index->flushedLabels())->toContain(...massWriteWholeTable((new Setting)->getTable()));
})->with([
    'locked remove' => [LockedRemoveCommand::class, Modules\Core\Database\Seeders\CoreDatabaseSeeder::LOCK_NAME_PREFIX],
    'optimistic lock remove' => [OptimisticLockRemoveCommand::class, Modules\Core\Database\Seeders\CoreDatabaseSeeder::OPTIMISTIC_LOCK_NAME_PREFIX],
    'soft deletes remove' => [ModelSoftDeletesRemoveCommand::class, Modules\Core\Database\Seeders\CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX],
]);

it('flushes the settings table when the optimistic lock command creates its setting', function (): void {
    $command = app(OptimisticLockAddCommand::class);
    $table = 'mass_write_' . uniqid();

    (new ReflectionMethod($command, 'updateSettingsTable'))->invoke($command, $table, true);

    $setting = Setting::query()->where('name', Modules\Core\Services\PerModelSettingResolver::nameFor(Modules\Core\Database\Seeders\CoreDatabaseSeeder::OPTIMISTIC_LOCK_NAME_PREFIX, $table))->first();

    expect($setting)->not->toBeNull()
        ->and($this->index->flushedLabels())->toContain(CacheLabel::table($setting->getTable()));
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
