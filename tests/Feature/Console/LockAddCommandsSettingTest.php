<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Models\Template;
use Modules\Core\Services\PerModelSettingResolver;

beforeEach(function (): void {
    // The commands write a migration file: keep it in memory, read the stub from disk.
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('isDirectory')->andReturnTrue();
    $files->shouldReceive('exists')->andReturnFalse();
    $files->shouldReceive('put')->andReturn(1);
    app()->instance(Filesystem::class, $files);
});

dataset('lock add commands', [
    'locked' => ['model:locked-add', CoreDatabaseSeeder::LOCK_NAME_PREFIX],
    'optimistic' => ['model:optimistic-lock-add', CoreDatabaseSeeder::OPTIMISTIC_LOCK_NAME_PREFIX],
]);

it('stores the lock setting of the model table', function (string $command, string $prefix): void {
    $table = new Template()->getTable();

    $this->artisan($command, ['model' => 'Template', '--namespace' => 'Modules\\Core\\Models'])->assertSuccessful();

    $setting = Setting::query()->where('name', PerModelSettingResolver::nameFor($prefix, $table))->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBeTrue()
        ->and($setting->encrypted)->toBeFalse()
        ->and($setting->group_name)->toBe('locking');
})->with('lock add commands');

it('switches an existing lock setting back on', function (string $command, string $prefix): void {
    $table = new Template()->getTable();
    $name = PerModelSettingResolver::nameFor($prefix, $table);

    $this->artisan($command, ['model' => 'Template', '--namespace' => 'Modules\\Core\\Models'])->assertSuccessful();
    Setting::query()->where('name', $name)->update(['value' => json_encode(false)]);

    $this->artisan($command, ['model' => 'Template', '--namespace' => 'Modules\\Core\\Models'])->assertSuccessful();

    expect(Setting::query()->where('name', $name)->count())->toBe(1)
        ->and(Setting::query()->where('name', $name)->first()?->value)->toBeTrue();
})->with('lock add commands');
