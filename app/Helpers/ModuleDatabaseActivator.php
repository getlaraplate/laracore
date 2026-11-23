<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Models\Module as ModuleRecord;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Module;
use Override;
use ReflectionClass;
use Throwable;

final class ModuleDatabaseActivator implements ActivatorInterface
{
    /**
     * @var class-string
     */
    public static string $MODEL_NAME = ModuleRecord::class;

    private readonly string $cacheKey;

    private readonly int $cacheLifetime;

    private readonly Repository $configs;

    /**
     * @var array<int, string>
     */
    private array $modulesStatuses;

    public function __construct(Container $app)
    {
        $this->configs = $app->make(Repository::class);
        $this->cacheKey = self::cacheKey();
        $this->cacheLifetime = (int) $this->config('cache-lifetime', 3600);
        $this->modulesStatuses = $this->getModulesStatuses();
    }

    /**
     * Checks whether the modules table exists so the database activator can be used.
     * Uses only the DatabaseManager (Schema) so it works before Eloquent's connection
     * resolver is set. The module rows are created on first read by syncModules().
     */
    public static function checkModulesTable(): bool
    {
        try {
            $cache_key = 'modules_db_activator_checked';

            if (! app()->bound('db')) {
                return false;
            }

            // Cached "table exists" can be stale after migrate:fresh / drop-all; always verify.
            [$modules_table, $connection] = self::modulesTableAndConnection();

            if (Cache::get($cache_key) === true && $connection->getSchemaBuilder()->hasTable($modules_table)) {
                return true;
            }

            if (Cache::has($cache_key)) {
                Cache::forget($cache_key);
            }

            if (! $connection->getSchemaBuilder()->hasTable($modules_table)) {
                return false;
            }

            // A gate re-verified with hasTable() above, kept for an hour like the module statuses, never for good.
            Cache::put($cache_key, true, 3600);

            return true;
        } catch (Throwable $e) {
            Log::error("Error checking modules db activator: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * @return array<int,string>
     *
     * @psalm-return list<string>
     */
    public static function getAllModulesNames(): array
    {
        $modules = glob(config('modules.paths.modules') . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        $parsed_names = [];

        foreach ($modules as $path) {
            $parsed_names[] = Str::afterLast($path, DIRECTORY_SEPARATOR);
        }

        return $parsed_names;
    }

    /**
     * Creates a row, active, for every module on disk that has none. Existing rows are left alone.
     * Query builder only, no Eloquent, so it is safe while the app is still booting.
     */
    public static function syncModules(): void
    {
        [$modules_table, $connection] = self::modulesTableAndConnection();

        $known = $connection->table($modules_table)->pluck('name')->all();
        $missing = array_values(array_diff(self::getAllModulesNames(), $known));

        if ($missing === []) {
            return;
        }

        $now = now();

        $connection->table($modules_table)->insertOrIgnore(array_map(
            static fn (string $name): array => [
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $missing,
        ));

        Log::info("Created module records '{names}'", ['names' => implode(', ', $missing)]);
    }

    public static function flushStatusesCache(): void
    {
        Cache::store(config('modules.cache.driver'))->forget(self::cacheKey());
    }

    #[Override]
    public function reset(): void
    {
        [$modules_table, $connection] = self::modulesTableAndConnection();

        $connection->table($modules_table)->update(['is_active' => true, 'updated_at' => now()]);

        $this->modulesStatuses = self::getAllModulesNames();
        self::flushStatusesCache();
    }

    #[Override]
    public function enable(Module $module): void
    {
        $this->setActiveByName($module->getName(), true);
    }

    #[Override]
    public function disable(Module $module): void
    {
        $this->setActiveByName($module->getName(), false);
    }

    #[Override]
    public function hasStatus(Module|string $module, bool $status): bool
    {
        $name = $module instanceof Module ? $module->getName() : $module;

        return $status === in_array($name, $this->modulesStatuses, true);
    }

    #[Override]
    public function setActive(Module $module, bool $active): void
    {
        $this->setActiveByName($module->getName(), $active);
    }

    #[Override]
    public function setActiveByName(string $name, bool $active): void
    {
        if (! $active && $name === ModuleRecord::CORE) {
            throw new LogicException('The Core module cannot be disabled.');
        }

        [$modules_table, $connection] = self::modulesTableAndConnection();

        $now = now();
        $updated = $connection->table($modules_table)
            ->where('name', $name)
            ->update(['is_active' => $active, 'updated_at' => $now]);

        if ($updated === 0) {
            $connection->table($modules_table)->insertOrIgnore([
                'name' => $name,
                'is_active' => $active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->modulesStatuses = array_values(array_filter(
            $this->modulesStatuses,
            static fn (string $active_name): bool => $active_name !== $name,
        ));

        if ($active) {
            $this->modulesStatuses[] = $name;
        }

        self::flushStatusesCache();
    }

    /**
     * A deleted module loses its row, so it no longer shows up in the panel.
     */
    #[Override]
    public function delete(Module $module): void
    {
        $name = $module->getName();

        if ($name === ModuleRecord::CORE) {
            throw new LogicException('The Core module cannot be deleted.');
        }

        [$modules_table, $connection] = self::modulesTableAndConnection();

        $connection->table($modules_table)->where('name', $name)->delete();

        $this->modulesStatuses = array_values(array_filter(
            $this->modulesStatuses,
            static fn (string $active_name): bool => $active_name !== $name,
        ));
        self::flushStatusesCache();
    }

    private static function cacheKey(): string
    {
        return (string) config('modules.activators.database.cache-key', 'modules_db_activator_statuses');
    }

    /**
     * @return array{string, Connection}
     */
    private static function modulesTableAndConnection(): array
    {
        $model = self::$MODEL_NAME;

        // Runs while providers are still being registered, before Eloquent has an
        // event dispatcher. Booting the model here would make it register its
        // observers against a missing dispatcher, and HasEvents drops them
        // silently, so the model would stay observer-less for the whole request.
        $prototype = new ReflectionClass($model)->newInstanceWithoutConstructor();

        /** @var DatabaseManager $database_manager */
        $database_manager = app()->make('db');

        return [
            $prototype->getTable(),
            $database_manager->connection($prototype->getConnectionName()),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function readStatuses(): array
    {
        [$modules_table, $connection] = self::modulesTableAndConnection();

        if (! $connection->getSchemaBuilder()->hasTable($modules_table)) {
            return self::getAllModulesNames();
        }

        self::syncModules();

        return $connection->table($modules_table)
            ->where('is_active', true)
            ->pluck('name')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function getModulesStatuses(): array
    {
        if (! $this->configs->get('modules.cache.enabled')) {
            return $this->readStatuses();
        }

        $driver = $this->configs->get('modules.cache.driver');

        return Cache::store($driver)->remember($this->cacheKey, $this->cacheLifetime, $this->readStatuses(...));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->configs->get('modules.activators.database.' . $key, $default);
    }
}
