<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Override;
use Throwable;

final class CacheStoreHealthWidget extends Widget
{
    #[Override]
    protected string $view = 'core::filament.widgets.cache-store-health';

    #[Override]
    protected int|string|array $columnSpan = 'full';

    #[Override]
    protected static ?int $sort = 50;

    /**
     * Rendered only by the CacheHealth page, which lists it explicitly; kept out of
     * panel discovery so the dashboard does not pick it up.
     */
    #[Override]
    protected static bool $isDiscovered = false;

    /**
     * Read live on every render: caching the state of the cache would hide the outage it reports.
     * The Redis connections are listed when a store in use runs on Redis.
     *
     * @return array{default: string, failover: bool, stores: list<array{name: string, driver: string, connection: string|null, version: string|null, reachable: bool}>, redis_connections: list<array{names: list<string>, keys: int|null, reachable: bool}>}
     */
    protected function getViewData(): array
    {
        $default = self::stringOrNull(config('cache.default')) ?? 'array';
        $is_failover = config("cache.stores.{$default}.driver") === 'failover';
        $failover_stores = config("cache.stores.{$default}.stores");

        $store_names = $is_failover && is_array($failover_stores)
            ? array_values(array_filter($failover_stores, is_string(...)))
            : [$default];

        $stores = array_map($this->describeStore(...), $store_names);
        $uses_redis = in_array('redis', array_column($stores, 'driver'), true);

        return [
            'default' => $default,
            'failover' => $is_failover,
            'stores' => $stores,
            'redis_connections' => $uses_redis ? $this->redisConnections() : [],
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{name: string, driver: string, connection: string|null, version: string|null, reachable: bool}
     */
    private function describeStore(string $name): array
    {
        $config = config("cache.stores.{$name}");
        $config = is_array($config) ? $config : [];
        $driver = self::stringOrNull($config['driver'] ?? null) ?? $name;

        return [
            'name' => $name,
            'driver' => $driver,
            'connection' => $this->connectionName($driver, $config),
            'version' => $this->serverVersion($name, $driver, $config),
            'reachable' => $this->isReachable($name),
        ];
    }

    /**
     * The backend the store talks to: the Redis or database connection name, the
     * file path or the Memcached servers; null for drivers without one.
     *
     * @param  array<mixed>  $config
     */
    private function connectionName(string $driver, array $config): ?string
    {
        return match ($driver) {
            'redis' => self::stringOrNull($config['connection'] ?? null) ?? 'default',
            'database' => self::stringOrNull($config['connection'] ?? null) ?? self::stringOrNull(config('database.default')),
            'file' => self::stringOrNull($config['path'] ?? null),
            'memcached' => $this->memcachedServers($config['servers'] ?? null),
            default => null,
        };
    }

    private function memcachedServers(mixed $servers): ?string
    {
        if (! is_array($servers)) {
            return null;
        }

        $addresses = [];

        foreach ($servers as $server) {
            if (is_array($server) && is_scalar($server['host'] ?? null) && is_scalar($server['port'] ?? null)) {
                $addresses[] = $server['host'] . ':' . $server['port'];
            }
        }

        return $addresses === [] ? null : implode(', ', $addresses);
    }

    /**
     * The server version behind the store, or null when the driver has no server
     * or the version cannot be read. Reading failures never throw.
     *
     * @param  array<mixed>  $config
     */
    private function serverVersion(string $name, string $driver, array $config): ?string
    {
        try {
            $version = match ($driver) {
                'redis' => $this->redisVersion($this->connectionName($driver, $config) ?? 'default'),
                'database' => DB::connection($this->connectionName($driver, $config))->getServerVersion(),
                'memcached' => $this->memcachedVersion($name),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return self::stringOrNull($version);
    }

    /**
     * Reads `INFO server`, which phpredis returns flat and Predis groups by section.
     */
    private function redisVersion(string $connection): ?string
    {
        $info = Redis::connection($connection)->command('info', ['server']);

        if (! is_array($info)) {
            return null;
        }

        $section = is_array($info['Server'] ?? null) ? $info['Server'] : $info;

        return self::stringOrNull($section['redis_version'] ?? null);
    }

    /**
     * The Redis connections configured in `database.redis`, grouped by the database
     * they point at (url, host, port and index): connections sharing a database share
     * one key total, read once with DBSIZE. A database that cannot be read is unreachable.
     *
     * @return list<array{names: list<string>, keys: int|null, reachable: bool}>
     */
    private function redisConnections(): array
    {
        $connections = config('database.redis');

        if (! is_array($connections)) {
            return [];
        }

        /** @var array<string, list<string>> $groups */
        $groups = [];

        foreach ($connections as $name => $connection) {
            if (! is_string($name) || in_array($name, ['client', 'options', 'clusters'], true) || ! is_array($connection)) {
                continue;
            }

            $target = json_encode([
                $connection['url'] ?? null,
                $connection['host'] ?? null,
                $connection['port'] ?? null,
                $connection['database'] ?? null,
            ]);
            $groups[is_string($target) ? $target : $name][] = $name;
        }

        $rows = [];

        foreach ($groups as $names) {
            $keys = $this->redisKeyCount($names[0]);

            $rows[] = [
                'names' => $names,
                'keys' => $keys,
                'reachable' => $keys !== null,
            ];
        }

        return $rows;
    }

    private function redisKeyCount(string $connection): ?int
    {
        try {
            $keys = Redis::connection($connection)->command('dbsize');
        } catch (Throwable) {
            return null;
        }

        return is_int($keys) ? $keys : null;
    }

    private function memcachedVersion(string $name): ?string
    {
        $store = Cache::store($name)->getStore();

        if (! $store instanceof MemcachedStore) {
            return null;
        }

        foreach ($store->getMemcached()->getVersion() as $version) {
            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        return null;
    }

    private function isReachable(string $name): bool
    {
        try {
            return Cache::store($name)->ping();
        } catch (Throwable) {
            return false;
        }
    }
}
