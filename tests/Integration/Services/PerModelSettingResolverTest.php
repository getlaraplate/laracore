<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Cache\SettingsCacheProbe;

beforeEach(function (): void {
    app(PerModelSettingResolver::class)->flush();
});

it('loads settings once and reuses them for subsequent lookups', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'soft_deletes.enabled.test_table',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'moderation',
        'description' => 'test',
    ]);

    expect($resolver->boolean('soft_deletes.enabled.test_table', false))->toBeTrue()
        ->and($resolver->boolean('soft_deletes.enabled.test_table', false))->toBeTrue();
});

it('returns default when setting is missing', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    expect($resolver->boolean('missing_setting_key', true))->toBeTrue()
        ->and($resolver->boolean('missing_setting_key', false))->toBeFalse();
});

it('reloads from database after flush', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    $setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.auto.test_table',
        'value' => false,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    expect($resolver->boolean('translations.auto.test_table', true))->toBeFalse();

    Setting::query()->whereKey($setting->id)->update(['value' => true]);
    $resolver->flush();

    expect($resolver->boolean('translations.auto.test_table', false))->toBeTrue();
});

it('resolves typed values', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'typed_pagination_test',
        'value' => 15,
        'type' => SettingTypeEnum::Integer,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    $resolver->flush();

    expect($resolver->int('typed_pagination_test', 25))->toBe(15)
        ->and($resolver->value('typed_pagination_test', 0))->toBe(15);
});

it('filters settings by group name', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'erp_group_test_flag',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'erp',
        'description' => 'test',
    ]);

    $resolver->flush();

    expect($resolver->group('erp')->has('erp_group_test_flag'))->toBeTrue()
        ->and($resolver->group('base')->has('erp_group_test_flag'))->toBeFalse();
});

it('resolves settings regardless of group name', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'custom_flag_test_table',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'custom_group',
        'description' => 'test',
    ]);

    $resolver->flush();

    expect($resolver->boolean('custom_flag_test_table', false))->toBeTrue();
});

it('stores each settings group under a group-specific cache key', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translation_group_cache_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    $resolver->flush();

    expect(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('translations')))->toBeFalse();

    $group = $resolver->group('translations');

    expect($group->has('translation_group_cache_test'))->toBeTrue()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('translations')))->toBeTrue();
});

it('resolves name based reads through the settings name index', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'indexed_setting_test',
        'value' => 'from-index',
        'type' => SettingTypeEnum::String,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    $resolver->flush();

    expect(SettingsCacheProbe::has(PerModelSettingResolver::nameIndexCacheKey()))->toBeFalse()
        ->and($resolver->string('indexed_setting_test', 'fallback'))->toBe('from-index')
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::nameIndexCacheKey()))->toBeTrue()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('base')))->toBeTrue();
});

it('flushes one group without clearing another loaded group', function (): void {
    $resolver = app(PerModelSettingResolver::class);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations_group_flush_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'erp_group_flush_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'erp',
        'description' => 'test',
    ]);

    $resolver->flush();
    $resolver->group('translations');
    $resolver->group('erp');

    expect(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('translations')))->toBeTrue()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('erp')))->toBeTrue();

    $resolver->flushGroup('translations');

    expect(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('translations')))->toBeFalse()
        ->and(SettingsCacheProbe::has(PerModelSettingResolver::groupCacheKey('erp')))->toBeTrue();
});

it('reloads a group from the database when the cache payload is not a collection', function (): void {
    $group = 'corrupt_group_' . uniqid();
    $name = 'corrupt_setting_' . uniqid();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => $name,
        'value' => 'from-db',
        'type' => SettingTypeEnum::String,
        'group_name' => $group,
        'description' => 'test',
    ]);

    $resolver = app(PerModelSettingResolver::class);
    $resolver->flush();

    SettingsCacheProbe::put(PerModelSettingResolver::groupCacheKey($group), [
        $name => ['name' => $name, 'value' => 'stale-array'],
    ]);

    $loaded = $resolver->group($group);

    expect($loaded)->toBeInstanceOf(Illuminate\Support\Collection::class)
        ->and($loaded->get($name))->toBeInstanceOf(Setting::class)
        ->and($resolver->string($name, 'missing'))->toBe('from-db');
});

it('rebuilds the name index from the database when the cached payload is not an array', function (): void {
    $name = 'name_index_corrupt_' . uniqid();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => $name,
        'value' => 'from-db',
        'type' => SettingTypeEnum::String,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    $resolver = app(PerModelSettingResolver::class);
    $resolver->flush();

    SettingsCacheProbe::put(
        PerModelSettingResolver::nameIndexCacheKey(),
        new Illuminate\Support\Collection([$name => 'base']),
    );

    expect($resolver->string($name, 'missing'))->toBe('from-db')
        ->and(SettingsCacheProbe::get(PerModelSettingResolver::nameIndexCacheKey()))->toBeArray();
});

it('caches the groups and the name index through the label index under the settings table, for the long duration', function (): void {
    // Saving a setting resolves the setting model's own version strategy, which reads the name index too;
    // the spy is bound afterwards so it records only what the resolver under test stores.
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'labelled_settings_test',
        'value' => 'v',
        'type' => SettingTypeEnum::String,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    $index = new Modules\Core\Tests\Stubs\Cache\SpyLabelIndex();
    app()->instance(Modules\Core\Contracts\ICacheLabelIndex::class, $index);
    $resolver = new PerModelSettingResolver();

    $resolver->string('labelled_settings_test', 'missing');

    $stored = array_map(static fn (array $store): array => [
        $store['lookup']->key,
        $store['lookup']->dependencies->labels,
        $store['ttl'],
    ], $index->stores);

    expect($stored)->toBe([
        [PerModelSettingResolver::nameIndexCacheKey(), ['table:core_settings'], (int) config('cache.duration.long')],
        [PerModelSettingResolver::groupCacheKey('base'), ['table:core_settings'], (int) config('cache.duration.long')],
    ]);
});

it('reloads every group after a settings write that flushes the table label', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'labelled_settings_flush_test',
        'value' => 'before',
        'type' => SettingTypeEnum::String,
        'group_name' => 'base',
        'description' => 'test',
    ]);

    expect(new PerModelSettingResolver()->string('labelled_settings_flush_test', 'missing'))->toBe('before');

    // A write that fires no model event and no coordinator call: only the label flush reaches the cached group.
    Setting::query()->where('name', 'labelled_settings_flush_test')->update(['value' => json_encode('after')]);
    resolve(Modules\Core\Cache\Labels\CacheLabelInvalidator::class)->flushTable('core_settings');

    expect(new PerModelSettingResolver()->string('labelled_settings_flush_test', 'missing'))->toBe('after');
});

it('reads the settings on behalf of the application, whatever the acting user may select', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'restricted_reader_setting_test',
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'moderation',
        'description' => 'test',
    ]);

    // As `permission:refresh` does: the select permission of the settings exists, granted to nobody.
    Permission::query()->firstOrCreate(['name' => PermissionName::forClass(Setting::class, 'select'), 'guard_name' => 'web']);
    $this->actingAs(User::factory()->create());

    expect(fn (): mixed => Setting::query()->where('name', 'restricted_reader_setting_test')->first())
        ->toThrow(AuthorizationException::class, 'User cannot select core_settings')
        ->and(new PerModelSettingResolver()->boolean('restricted_reader_setting_test', false))->toBeTrue();
});
