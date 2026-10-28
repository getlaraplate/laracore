<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Filament\Resources\Modifications\Pages\ListModifications;
use Modules\Core\Filament\Resources\Permissions\Tables\PermissionsTable;
use Modules\Core\Filament\Resources\Settings\Tables\SettingsTable;
use Modules\Core\Models\MediaDraft;
use Modules\Core\Models\Setting;
use Modules\Core\Services\DatabaseConfigOverlay;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Services\SettingsCacheCoordinator;
use Modules\Core\Tests\Stubs\ValidityStubModel;

/**
 * Stores an operator's value on a seeded Core setting and applies the overlay the way a request does.
 */
function core_store_setting(string $name, mixed $value): void
{
    Setting::query()->withoutGlobalScopes()
        ->where('module', 'Core')->where('name', $name)
        ->update(['value' => json_encode($value)]);

    app(SettingsCacheCoordinator::class)->flushAll();
    app()->forgetScopedInstances();

    app(DatabaseConfigOverlay::class)->applyFromDatabase(app(PerModelSettingResolver::class));
}

/**
 * Runs the three Filament sites that cache with the shared TTL and returns the TTL each one passed to the cache.
 *
 * @return array<string, int>
 */
function core_filament_ttls(): array
{
    $ttls = [];

    Cache::shouldReceive('remember')->andReturnUsing(static function (string $key, mixed $ttl) use (&$ttls): array {
        $ttls[$key] = $ttl;

        return ['all' => 0];
    });

    $call = static function (string $class, string $method, mixed ...$arguments): void {
        $reflection = new ReflectionMethod($class, $method);
        $reflection->invoke(null, ...$arguments);
    };

    $call(SettingsTable::class, 'cachedGroupNameOptions');
    $call(SettingsTable::class, 'cachedModuleOptions');
    $call(PermissionsTable::class, 'cachedDistinctOptions', 'guard_name');
    new ListModifications()->getTabs();

    return $ttls;
}

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => CoreDatabaseSeeder::class, '--no-interaction' => true]);
});

it('keeps the three Filament sites on the same TTL: 60 until an operator stores another', function (): void {
    $ttls = core_filament_ttls();

    expect($ttls)->toHaveCount(4)
        ->and(array_unique(array_values($ttls)))->toBe([60]);
});

it('falls back to 60 at every Filament site when the config entry is missing', function (): void {
    config(['core.filament' => []]);

    expect(array_unique(array_values(core_filament_ttls())))->toBe([60]);
});

it('lets the stored tab counts TTL reach every Filament site', function (): void {
    core_store_setting('filament.tabs_counts_ttl_seconds', 90);

    expect(array_unique(array_values(core_filament_ttls())))->toBe([90]);
});

it('reads a stored zero tab counts TTL as one second at every Filament site', function (): void {
    core_store_setting('filament.tabs_counts_ttl_seconds', 0);

    expect(array_unique(array_values(core_filament_ttls())))->toBe([1]);
});

it('measures the expiring window with the stored hours', function (): void {
    expect(ValidityStubModel::expiringWithinHours())->toBe(48);

    core_store_setting('validity.expiring_within_hours', 72);

    expect(ValidityStubModel::expiringWithinHours())->toBe(72);
});

it('reads a stored zero expiring window as one hour', function (): void {
    core_store_setting('validity.expiring_within_hours', 0);

    expect(ValidityStubModel::expiringWithinHours())->toBe(1);
});

it('prunes media drafts older than the stored TTL and keeps newer ones', function (): void {
    core_store_setting('media.draft_ttl_hours', 1);

    $stale = MediaDraft::factory()->create(['created_at' => now()->subHours(2)]);
    $fresh = MediaDraft::factory()->create(['created_at' => now()->subMinutes(30)]);

    Artisan::call('core:prune-media-drafts');

    expect(MediaDraft::query()->whereKey($stale->getKey())->exists())->toBeFalse()
        ->and(MediaDraft::query()->whereKey($fresh->getKey())->exists())->toBeTrue();
});

it('prunes no media draft when the stored TTL is zero or negative', function (int $stored): void {
    core_store_setting('media.draft_ttl_hours', $stored);

    $old = MediaDraft::factory()->create(['created_at' => now()->subYears(2)]);
    $fresh = MediaDraft::factory()->create(['created_at' => now()->subMinutes(30)]);

    Artisan::call('core:prune-media-drafts');

    expect(MediaDraft::query()->whereKey($old->getKey())->exists())->toBeTrue()
        ->and(MediaDraft::query()->whereKey($fresh->getKey())->exists())->toBeTrue();
})->with([
    'zero' => 0,
    'negative' => -5,
]);
