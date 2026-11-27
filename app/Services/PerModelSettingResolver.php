<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Authorization\RetrievedSelectGuard;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Setting;

/**
 * Singleton gateway for reading rows from the {@see Setting} table.
 *
 * Group lookups read the persistent group cache (or hydrate it from the DB);
 * further lookups reuse in-memory group collections until flushed.
 *
 * The groups and the name index are cached through the label index under the
 * label of the settings table, for `cache.duration.long`: any write to the table
 * through a model, and any flush of the whole table, reaches them, as does the
 * recovery purge of a failover index after an outage.
 */
final class PerModelSettingResolver
{
    /**
     * @var array<string, Collection<string, Setting>>
     */
    private array $loaded_groups = [];

    /**
     * @var array<string, string>|null
     */
    private ?array $name_index = null;

    /**
     * Build the canonical name of a per-model setting.
     *
     * Every writer and reader must go through this method: the name is
     * `{capability}.{table}` (e.g. `soft_deletes.cms_contents`), whatever trailing
     * separator the caller's prefix carries.
     */
    public static function nameFor(string $prefix, string $table): string
    {
        return mb_rtrim($prefix, '_.') . '.' . $table;
    }

    public static function cacheKey(): string
    {
        return CacheManager::key('settings', 'by_name');
    }

    public static function groupCacheKey(string $group_name): string
    {
        return CacheManager::key('settings', 'group', $group_name);
    }

    public static function nameIndexCacheKey(): string
    {
        return CacheManager::key('settings', 'name_index');
    }

    public static function legacyTableCacheKey(): string
    {
        return CacheManager::key('settings');
    }

    /**
     * @return Collection<string, Setting>
     */
    public function collection(): Collection
    {
        $settings = new Collection();

        foreach (array_unique(array_values($this->nameIndex())) as $group_name) {
            $settings = $settings->merge($this->group($group_name));
        }

        return $settings;
    }

    /**
     * @return Collection<string, Setting>
     */
    public function group(string $group_name): Collection
    {
        if (isset($this->loaded_groups[$group_name])) {
            return $this->loaded_groups[$group_name];
        }

        // A payload that is not a collection (an array from Redis-after-fork corruption or an older writer) is read
        // again from the database and stored over.
        $group = self::cache()->remember(
            self::groupCacheKey($group_name),
            self::cacheLabels(),
            self::cacheTtl(),
            static fn (): Collection => RetrievedSelectGuard::without(
                Setting::class,
                static fn (): Collection => Setting::query()
                    ->where('group_name', $group_name)
                    ->get()
                    ->keyBy('name'),
            ),
            static fn (mixed $group): bool => $group instanceof Collection,
        );

        return $this->loaded_groups[$group_name] = $group;
    }

    /**
     * Resolve a setting value by name.
     * When no row exists, returns {@see $default}.
     */
    public function value(string $name, mixed $default = null): mixed
    {
        $group_name = $this->nameIndex()[$name] ?? null;

        if ($group_name === null) {
            return $default;
        }

        $setting = $this->group($group_name)->get($name);

        if ($setting === null) {
            return $default;
        }

        return $setting->value ?? $default;
    }

    /**
     * Resolve a boolean setting by name.
     * When no row exists, returns {@see $default}.
     */
    public function boolean(string $name, bool $default): bool
    {
        $stored = $this->value($name, null);

        if ($stored === null) {
            return $default;
        }

        return (bool) $stored;
    }

    public function int(string $name, int $default): int
    {
        $stored = $this->value($name, null);

        if ($stored === null) {
            return $default;
        }

        return (int) $stored;
    }

    public function float(string $name, float $default): float
    {
        $stored = $this->value($name, null);

        if ($stored === null) {
            return $default;
        }

        return (float) $stored;
    }

    public function string(string $name, string $default): string
    {
        $stored = $this->value($name, null);

        if ($stored === null) {
            return $default;
        }

        return (string) $stored;
    }

    /**
     * Drop persistent and in-memory caches so the next lookup reloads from the database.
     */
    public function flush(): void
    {
        Cache::forget(self::cacheKey());
        Cache::forget(self::legacyTableCacheKey());
        $this->flushNameIndex();

        foreach (array_keys($this->loaded_groups) as $group_name) {
            self::cache()->forget(self::groupCacheKey($group_name), self::cacheLabels());
        }

        $this->loaded_groups = [];
    }

    public function flushGroup(string $group_name): void
    {
        self::cache()->forget(self::groupCacheKey($group_name), self::cacheLabels());
        unset($this->loaded_groups[$group_name]);
    }

    /**
     * @param  array<int, string>  $group_names
     */
    public function flushGroups(array $group_names): void
    {
        foreach (array_unique(array_filter($group_names)) as $group_name) {
            $this->flushGroup((string) $group_name);
        }
    }

    public function flushNameIndex(): void
    {
        self::cache()->forget(self::nameIndexCacheKey(), self::cacheLabels());
        $this->name_index = null;
    }

    private static function cache(): LabelledCache
    {
        return resolve(LabelledCache::class);
    }

    /**
     * @return list<string>
     */
    private static function cacheLabels(): array
    {
        return [CacheLabel::table(CoreTables::Settings->value)];
    }

    private static function cacheTtl(): int
    {
        return (int) config('cache.duration.long');
    }

    /**
     * @return array<string, string>
     */
    private function nameIndex(): array
    {
        if ($this->name_index !== null) {
            return $this->name_index;
        }

        // A payload that is not an array (a collection from Redis-after-fork corruption or an older writer) is read
        // again from the database and stored over.
        $name_index = self::cache()->remember(
            self::nameIndexCacheKey(),
            self::cacheLabels(),
            self::cacheTtl(),
            static fn (): array => Setting::query()
                ->pluck('group_name', 'name')
                ->toArray(),
            is_array(...),
        );

        return $this->name_index = $name_index;
    }
}
