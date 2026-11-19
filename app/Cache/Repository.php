<?php

declare(strict_types=1);

namespace Modules\Core\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as BaseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Models\User;
use Override;
use Spatie\Permission\Models\Role;
use Throwable;
use UnitEnum;

/**
 * @template TDuration of Closure|DateInterval|DateTimeInterface|int|array<int|string, mixed>|null
 */
final class Repository extends BaseRepository
{
    /**
     * The default ttl of a cached response, in seconds: `cache.duration` when it is a number, else its `medium`,
     * `short` or `long` entry, else 300.
     */
    public static function resolveDefaultCacheSeconds(): int
    {
        $durations = config('cache.duration');

        if (is_int($durations)) {
            return max(1, $durations);
        }

        if (! is_array($durations)) {
            return 300;
        }

        return self::resolveDurationConfigToSeconds($durations);
    }

    #[Override]
    /**
     * @param  string  $key
     * @param  int|array<int, DateInterval|DateTimeInterface|int|string>|null  $ttl  List of two items uses Laravel {@see parent::flexible()}; other arrays are normalized to seconds (e.g. named durations from config).
     *
     * @template TCacheValue
     *
     * @param  Closure(): TCacheValue  $callback
     * @return TCacheValue
     */
    public function remember($key, $ttl, Closure $callback): mixed // @pest-ignore-type
    {
        return $this->executeRemember($key, $ttl, $callback);
    }

    // #[Override]
    // public function add($key, $value, $ttl = null)
    // {
    //     try {
    //         // Prova il metodo standard
    //         return parent::add($key, $value, $ttl);
    //     } catch (\RedisException $e) {
    //         // try to handle Dragonfly connections without LUA scripts enabled
    //         if (Str::endsWith($e->getFile(), 'PhpRedisConnector.php') && $e->getCode() === 0  && $e->getMessage() === 'Connection refused' && is_null($this->get($key))) {
    //             return $this->put($key, $value, $ttl);
    //         }
    //     }
    // }

    /**
     * Flush everything cached for the whole table of each entity that uses the cache: which of its rows exist and the
     * content of every row, so a detail or a response embedding any row is reached too. It is the explicit "forget this
     * entity" call, for a write that fires no model event or whose rows are unknown. A write through Eloquent models
     * needs no call: the model events flush the table and the row they touched, which keeps the other rows cached.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>  $entity
     */
    public function clearByEntity(Model|string|array $entity): void
    {
        foreach (Arr::wrap($entity) as $model) {
            $resolved_model = $this->resolveModel($model);

            if ($resolved_model === null) {
                continue;
            }

            if (method_exists($resolved_model, 'usesCache') && $resolved_model->usesCache()) {
                app(CacheLabelInvalidator::class)->flushTable($this->getTableName($resolved_model), $resolved_model);
            }
        }
    }

    /**
     * Invalidate every response cached for the user, when no entity is given or at least one of them uses the cache.
     * The flush runs after the commit of a transaction open on the user's connection.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     */
    public function clearByUser(User $user, Model|string|array|null $entity = null): void
    {
        $key = $user->getKey();

        if ((is_int($key) || is_string($key)) && $this->anyUsesCache($entity)) {
            app(CacheLabelInvalidator::class)->flushUser($key, $user);
        }
    }

    /**
     * Invalidate every response cached for the users holding the role, under the same conditions as
     * {@see clearByUser()}.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     */
    public function clearByGroup(Role $role, Model|string|array|null $entity = null): void
    {
        $key = $role->getKey();

        if ((is_int($key) || is_string($key)) && $this->anyUsesCache($entity)) {
            app(CacheLabelInvalidator::class)->flushRole($key, $role);
        }
    }

    /**
     * Lightweight reachability check of the underlying store: a PING on Redis,
     * a short write/read round trip on any other driver.
     */
    public function ping(): bool
    {
        try {
            if ($this->store instanceof RedisStore) {
                $this->store->connection()->ping();

                return true;
            }

            $key = CacheManager::key('health', 'ping');

            return $this->store->put($key, 'ok', 10) && $this->store->get($key) === 'ok';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int|string, mixed>  $durations
     */
    private static function resolveDurationConfigToSeconds(array $durations): int
    {
        foreach (['medium', 'short', 'long'] as $key) {
            if (isset($durations[$key]) && is_numeric($durations[$key])) {
                return max(1, (int) $durations[$key]);
            }
        }

        foreach ($durations as $value) {
            if (is_numeric($value)) {
                return max(1, (int) $value);
            }
        }

        return 300;
    }

    /**
     * @template TCacheValue
     *
     * @param  Closure(): TCacheValue  $callback
     * @return TCacheValue
     */
    private function executeRemember(string|UnitEnum $key, mixed $ttl, Closure $callback): mixed
    {
        if (is_array($ttl)) {
            if (count($ttl) === 2 && is_numeric($ttl[0] ?? null) && is_numeric($ttl[1] ?? null)) {
                return parent::flexible($key, [(int) $ttl[0], (int) $ttl[1]], $callback);
            }

            return parent::remember($key, self::resolveDurationConfigToSeconds($ttl), $callback);
        }

        if ($ttl === null) {
            $threshold = $this->getThreshold();
            $base_seconds = self::resolveDefaultCacheSeconds();

            if ($threshold !== null && $threshold !== 0) {
                return parent::flexible($key, [$threshold, $base_seconds], $callback);
            }

            return parent::remember($key, $base_seconds, $callback);
        }

        if (is_int($ttl) || $ttl instanceof DateInterval || $ttl instanceof DateTimeInterface || $ttl instanceof Closure) {
            return parent::remember($key, $ttl, $callback);
        }

        if (is_numeric($ttl)) {
            return parent::remember($key, (int) $ttl, $callback);
        }

        return parent::remember($key, self::resolveDefaultCacheSeconds(), $callback);
    }

    /**
     * True when no entity is given or at least one of them uses the cache.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     */
    private function anyUsesCache(Model|string|array|null $entity): bool
    {
        if ($entity === null || $entity === []) {
            return true;
        }

        foreach (Arr::wrap($entity) as $model) {
            $resolved_model = $this->resolveModel($model);

            if ($resolved_model !== null && method_exists($resolved_model, 'usesCache') && $resolved_model->usesCache()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Model|class-string<Model>|mixed  $model
     */
    private function resolveModel(mixed $model): ?Model
    {
        if ($model instanceof Model) {
            return $model;
        }

        if (! is_string($model) || $model === '') {
            return null;
        }

        $instance = new $model();

        return $instance instanceof Model ? $instance : null;
    }

    private function getTableName(string|Model $entity): string
    {
        return is_string($entity) ? $entity : $entity->getTable();
    }

    private function getThreshold(): ?int
    {
        $value = config('cache.threshold');

        return is_numeric($value) ? (int) $value : null;
    }
}
