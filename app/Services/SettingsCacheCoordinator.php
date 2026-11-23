<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Setting;

/**
 * Central invalidation for all settings-related caches.
 *
 * Call {@see flushAll()} from {@see \Modules\Core\Observers\SettingObserver} and any
 * code that bulk-updates settings outside Eloquent events.
 */
final class SettingsCacheCoordinator
{
    private const string FILAMENT_GROUP_OPTIONS_CACHE_KEY = 'filament_settings_distinct_group_name';

    /**
     * @var array<int, callable(): void>
     */
    private array $invalidators = [];

    /**
     * @var array<string, array<int, callable(): void>>
     */
    private array $group_invalidators = [];

    /**
     * Register an extra invalidator (e.g. module-specific derived caches).
     */
    public function registerInvalidator(callable $invalidator): void
    {
        $this->invalidators[] = $invalidator;
    }

    public function registerGroupInvalidator(string $group_name, callable $invalidator): void
    {
        $this->group_invalidators[$group_name][] = $invalidator;
    }

    public function flushSetting(Setting $setting, bool $sync_runtime_config = false): void
    {
        $groups = [$setting->group_name];
        $original_group = $setting->getOriginal('group_name');

        if (is_string($original_group) && $original_group !== '' && $original_group !== $setting->group_name) {
            $groups[] = $original_group;
        }

        $this->flushGroups($groups);
        $this->flushNameIndex();
        $this->flushDerivedSettingsCaches();

        if ($sync_runtime_config) {
            $this->syncRuntimeConfig($setting);
        }
    }

    /**
     * @param  array<int, string|null>  $group_names
     */
    public function flushGroups(array $group_names): void
    {
        foreach (array_unique(array_filter($group_names)) as $group_name) {
            $this->flushGroup((string) $group_name);
        }
    }

    public function flushGroup(string $group_name): void
    {
        // Unbound, the container builds a throwaway resolver, which still forgets the persistent entry.
        app(PerModelSettingResolver::class)->flushGroup($group_name);

        if ($group_name === 'versioning') {
            $this->flushVersioningCaches();
        }

        foreach ($this->group_invalidators[$group_name] ?? [] as $invalidator) {
            $invalidator();
        }
    }

    /**
     * Drop persistent and in-memory settings caches across the application.
     */
    public function flushAll(): void
    {
        if (app()->bound(PerModelSettingResolver::class)) {
            app(PerModelSettingResolver::class)->flush();
        }

        $this->flushVersioningCaches();
        $this->flushDerivedSettingsCaches();
        (new Setting)->invalidateCache();

        foreach ($this->invalidators as $invalidator) {
            $invalidator();
        }
    }

    private function flushNameIndex(): void
    {
        app(PerModelSettingResolver::class)->flushNameIndex();
    }

    private function flushVersioningCaches(): void
    {
        // Legacy key used by older HasVersions builds; safe to forget until fully removed.
        Cache::forget(CacheManager::key('version_strategies'));

        HasVersions::resetVersionStrategyCache();
    }

    private function flushDerivedSettingsCaches(): void
    {
        Cache::forget(self::FILAMENT_GROUP_OPTIONS_CACHE_KEY);
        Cache::forget(PerModelSettingResolver::cacheKey());
        Cache::forget(PerModelSettingResolver::legacyTableCacheKey());
    }

    private function syncRuntimeConfig(Setting $setting): void
    {
        if (! app()->bound(DatabaseConfigOverlay::class)) {
            return;
        }

        app(DatabaseConfigOverlay::class)->applySetting($setting);
    }
}
