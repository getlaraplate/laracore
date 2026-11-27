<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Services\SettingsCacheCoordinator;
use Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe;

beforeEach(function (): void {
    app(SettingsCacheCoordinator::class)->flushAll();
});

it('flushes the settings resolver persistent cache', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'coordinator_flush_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    $resolver = app(PerModelSettingResolver::class);

    expect($resolver->boolean('coordinator_flush_test', false))->toBeTrue();

    Setting::query()->where('name', 'coordinator_flush_test')->update(['value' => false]);

    expect($resolver->boolean('coordinator_flush_test', true))->toBeTrue();

    app(SettingsCacheCoordinator::class)->flushAll();

    expect($resolver->boolean('coordinator_flush_test', true))->toBeFalse();
});

it('runs registered invalidators on flush all', function (): void {
    $called = false;

    app(SettingsCacheCoordinator::class)->registerInvalidator(static function () use (&$called): void {
        $called = true;
    });

    app(SettingsCacheCoordinator::class)->flushAll();

    expect($called)->toBeTrue();
});

it('invalidates settings on model save via observer', function (): void {
    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'observer_flush_test',
        'value' => 10,
        'type' => SettingTypeEnum::Integer,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    $resolver = app(PerModelSettingResolver::class);

    expect($resolver->int('observer_flush_test', 0))->toBe(10);

    $setting->setForcedApprovalUpdate(true);
    $setting->value = 20;
    $setting->save();

    expect($resolver->int('observer_flush_test', 0))->toBe(20)
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('base')))->toBeTrue();
});

it('syncs runtime config on model save via observer after cache flush', function (): void {
    config(['core.expose_api' => true]);

    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'expose_api',
        'module' => 'Core',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'core',
        'description' => 'Expose CRUD API endpoints',
    ]);

    expect(config('core.expose_api'))->toBeTrue();

    $setting->setForcedApprovalUpdate(true);
    $setting->value = false;
    $setting->save();

    expect(config('core.expose_api'))->toBeFalse();
});

it('does not sync non-overlay settings onto runtime config when saved', function (): void {
    config(['site' => ['default_language' => 'en']]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'default_language',
        'value' => 'it',
        'type' => SettingTypeEnum::String,
        'group_name' => 'base',
        'description' => 'Default site language',
    ]);

    expect(config('default_language'))->toBeNull()
        ->and(config('site.default_language'))->toBe('en');
});

it('flushes only the affected settings group', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    $translation_setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'coordinator_translation_group_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'coordinator_erp_group_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'erp',
        'description' => 'test',
    ]);

    $resolver->flush();
    $resolver->group('translations');
    $resolver->group('erp');

    app(SettingsCacheCoordinator::class)->flushSetting($translation_setting);

    expect(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('translations')))->toBeFalse()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('erp')))->toBeTrue()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::nameIndexCacheKey()))->toBeFalse();
});

it('flushes old and new groups when a setting moves groups', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'coordinator_group_move_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    $resolver->flush();
    $resolver->group('base');

    $setting->setForcedApprovalUpdate(true);
    $setting->group_name = 'erp';
    $setting->save();

    expect(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('base')))->toBeFalse()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('erp')))->toBeFalse()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::nameIndexCacheKey()))->toBeFalse();
});

it('forgets derived settings caches when flushing a setting', function (): void {
    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'coordinator_derived_cache_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    Cache::put('filament_settings_distinct_group_name', ['base' => 'base'], 300);
    Cache::forever(PerModelSettingResolver::legacyTableCacheKey(), collect([$setting]));
    Cache::forever(PerModelSettingResolver::cacheKey(), collect([$setting]));

    app(SettingsCacheCoordinator::class)->flushSetting($setting);

    expect(Cache::has('filament_settings_distinct_group_name'))->toBeFalse()
        ->and(Cache::has(PerModelSettingResolver::legacyTableCacheKey()))->toBeFalse()
        ->and(Cache::has(PerModelSettingResolver::cacheKey()))->toBeFalse();
});

it('resets versioning caches when the versioning group is affected', function (): void {
    $versioning_setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'versioning.strategy.coordinator_test',
        'value' => false,
        'type' => SettingTypeEnum::Json,
        'group_name' => 'versioning',
        'description' => 'test',
    ]);

    Cache::forever(CacheManager::key('version_strategies'), collect([$versioning_setting]));

    app(SettingsCacheCoordinator::class)->flushSetting($versioning_setting);

    expect(Cache::has(CacheManager::key('version_strategies')))->toBeFalse();
});

/**
 * A coordinator flush is per group (see "flushes only the affected settings group"), but a save through the model also
 * flushes the label of the settings table, which every cached group and the name index carry.
 */
it('a setting saved through the model invalidates every cached settings group', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    $translation_setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'observer_translation_group_test',
        'value' => false,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'observer_erp_group_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'erp',
        'description' => 'test',
    ]);

    $resolver->flush();
    $resolver->group('translations');
    $resolver->group('erp');

    $translation_setting->setForcedApprovalUpdate(true);
    $translation_setting->value = true;
    $translation_setting->save();

    expect(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('translations')))->toBeFalse()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('erp')))->toBeFalse()
        ->and($resolver->boolean('observer_erp_group_test', false))->toBeTrue();
});
