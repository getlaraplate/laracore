<?php

declare(strict_types=1);

namespace Modules\Core\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as BaseRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Helpers\ResponseBuilder;
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
     * Cached app name to avoid repeated config calls.
     */
    private static ?string $app_name = null;

    /**
     * The tag names to use for the given tags, each namespaced with the app name ("{app}:{tag}"), without duplicates
     * and sorted.
     *
     * The tags of a tagged entry are part of its key, in their order: sorting them makes the key independent of the
     * order in which a caller composed them, so a read, a forget and a write of one value always meet.
     *
     * The namespace keeps two applications sharing one store apart. It is part of every name rather than a tag of
     * its own: flushing a tag set invalidates every entry carrying any one of its tags, so a tag shared by every
     * entry would turn each flush into a flush of the whole application cache. Without tags it returns the bare app
     * name, the tag of entries that belong to no narrower scope.
     *
     * @param  array<string>|string  $tags
     * @return list<string>
     */
    public function getCacheTags(array|string $tags = []): array
    {
        if (self::$app_name === null) {
            $app_name = config('app.name');
            self::$app_name = is_string($app_name) ? $app_name : '';
        }

        $names = is_string($tags) ? [$tags] : $tags;

        if ($names === []) {
            return [self::$app_name];
        }

        $prefix = self::$app_name . ':';
        $namespaced = array_unique(array_map(static fn (string $tag): string => $prefix . $tag, $names));
        sort($namespaced, SORT_STRING);

        return $namespaced;
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
     * Cache the response of a request, tagged with the tables of its entities and with the user and roles that
     * asked for it, so {@see clearByEntity()}, {@see clearByUser()} and {@see clearByGroup()} can invalidate it.
     * On a store without tag support the response is cached untagged and expires by its ttl only.
     *
     * @template TCacheValue
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     * @param  Closure(): (TCacheValue|ResponseBuilder)  $callback
     * @return TCacheValue|JsonResponse
     */
    public function tryByRequest(Model|string|array|null $entity, Request $request, Closure $callback, ?int $duration = null): mixed
    {
        $scope = $this->resolveRequestScope($entity, $request);

        if ($scope === null) {
            return $this->unwrapCallbackResult($callback());
        }

        $cache = $this->supportsTags() ? $this->tags($scope['tags']) : $this;
        $ttl = $duration ?? $this->resolveDefaultCacheSeconds();

        return $cache->remember($scope['key'], $ttl, fn () => $this->unwrapCallbackResult($callback()));
    }

    /**
     * Clear cache by the specified entity.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>  $entity
     */
    public function clearByEntity(Model|string|array $entity): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        foreach (Arr::wrap($entity) as $model) {
            $resolved_model = $this->resolveModel($model);

            if ($resolved_model === null) {
                continue;
            }

            if (method_exists($resolved_model, 'usesCache') && $resolved_model->usesCache()) {
                $this->tags(self::getCacheTags($this->getTableName($resolved_model)))->flush();
            }
        }
    }

    /**
     * Forget the response {@see tryByRequest()} cached for this request.
     *
     * The tags of a tagged entry are part of its key, so this rebuilds them as tryByRequest did: pass the same
     * entities, in any order, for a request of the same user. A change of the user's roles in between changes the
     * tags and leaves the old entry to its ttl.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     */
    public function clearByRequest(Request $request, Model|string|array|null $entity = null): void
    {
        $scope = $this->resolveRequestScope($entity, $request);

        if ($scope === null) {
            return;
        }

        if ($this->supportsTags()) {
            $this->tags($scope['tags'])->forget($scope['key']);

            return;
        }

        $this->forget($scope['key']);
    }

    /**
     * Invalidate every response cached for the user.
     *
     * Flushing a tag set invalidates the entries carrying any one of its tags, never only those carrying all of them,
     * so the entities cannot narrow the flush: they only decide whether there is anything cached to invalidate.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     */
    public function clearByUser(User $user, Model|string|array|null $entity = null): void
    {
        $this->flushScopeTag('U' . $user->id, $entity);
    }

    /**
     * Invalidate every response cached for the users holding the role.
     *
     * The entities cannot narrow the flush, for the reason given on {@see clearByUser()}.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     */
    public function clearByGroup(Role $role, Model|string|array|null $entity = null): void
    {
        $this->flushScopeTag('R' . $role->id, $entity);
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
     * recursively sorts array by keys.
     *
     * @param  array<int|string, mixed>|string|null  $array
     */
    private static function recursiveKSort(array|string|null &$array): void
    {
        if (is_array($array)) {
            ksort($array);

            foreach ($array as &$value) {
                if (is_array($value) || is_string($value)) {
                    self::recursiveKSort($value);
                }
            }
        }
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

            return parent::remember($key, $this->resolveDurationConfigToSeconds($ttl), $callback);
        }

        if ($ttl === null) {
            $threshold = $this->getThreshold();
            $base_seconds = $this->resolveDefaultCacheSeconds();

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

        return parent::remember($key, $this->resolveDefaultCacheSeconds(), $callback);
    }

    /**
     * The tags and the key under which {@see tryByRequest()} caches the response of a request, or null when one of
     * its entities does not use the cache.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     * @return array{tags: list<string>, key: string}|null
     */
    private function resolveRequestScope(Model|string|array|null $entity, Request $request): ?array
    {
        $tables = [];

        foreach (Arr::wrap($entity) as $model) {
            $resolved_model = $this->resolveModel($model);

            if ($resolved_model === null || ! method_exists($resolved_model, 'usesCache') || ! $resolved_model->usesCache()) {
                return null;
            }

            $tables[] = $this->getTableName($resolved_model);
        }

        $request_user = $request->user();
        $user = $request_user instanceof User ? $request_user : null;
        $user_tags = $this->getKeyPartsFromUser($user);

        return [
            'tags' => self::getCacheTags([...$tables, ...$user_tags]),
            'key' => $this->getKeyFromRequest($request, [...$user_tags, ...$this->getKeyPartsFromToken($user)]),
        ];
    }

    /**
     * Flush one user or role tag, when no entity is given or at least one of them uses the cache.
     *
     * @param  Model|class-string<Model>|array<Model|class-string<Model>>|null  $entity
     */
    private function flushScopeTag(string $tag, Model|string|array|null $entity): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        if ($entity !== null && $entity !== []) {
            $uses_cache = false;

            foreach (Arr::wrap($entity) as $model) {
                $resolved_model = $this->resolveModel($model);

                if ($resolved_model !== null && method_exists($resolved_model, 'usesCache') && $resolved_model->usesCache()) {
                    $uses_cache = true;

                    break;
                }
            }

            if (! $uses_cache) {
                return;
            }
        }

        $this->tags(self::getCacheTags($tag))->flush();
    }

    /**
     * @template TCacheValue
     *
     * @param  TCacheValue|ResponseBuilder  $data
     * @return TCacheValue|JsonResponse
     */
    private function unwrapCallbackResult(mixed $data): mixed
    {
        if ($data instanceof ResponseBuilder) {
            return $data->getResponse();
        }

        return $data;
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

    /**
     * contruct a cache key by request info.
     *
     * @param  array<string>  $user_tags
     */
    private function getKeyFromRequest(Request $request, array $user_tags): string
    {
        $path = $request->getPathInfo();
        $params = $request->query();

        if ($user_tags !== []) {
            self::recursiveKSort($params);
        }

        return base64_encode($path . ($user_tags !== [] ? implode('_', $user_tags) . '_' : '') . serialize($params));
    }

    private function getTableName(string|Model $entity): string
    {
        return is_string($entity) ? $entity : $entity->getTable();
    }

    /**
     * The key part of the personal access token that authenticated the request, if one did: two tokens of one user
     * carry different abilities, so their responses differ (relations left out, the media projection) and must
     * never be served for each other. A session carries no personal token and adds nothing.
     *
     * @return list<string>
     */
    private function getKeyPartsFromToken(?User $user): array
    {
        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return [];
        }

        $key = $token->getKey();

        return ['T' . (is_scalar($key) ? (string) $key : '0')];
    }

    /**
     * compose key parts by user and groups.
     *
     * @return array<string>
     */
    private function getKeyPartsFromUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $tags = ['U' . $user->id];
        $role_tags = $user->roles()->get()->map(static function (Model $role): string {
            $key = $role->getKey();

            return 'R' . (is_scalar($key) ? (string) $key : '0');
        })->all();

        foreach (['groups', 'user_groups', 'user_roles'] as $relation_name) {
            if (! method_exists($user, $relation_name) || ! $user->relationLoaded($relation_name)) {
                continue;
            }

            $related = $user->getRelation($relation_name);

            if (! $related instanceof \Illuminate\Support\Collection) {
                continue;
            }

            foreach ($related as $related_model) {
                if (! $related_model instanceof Model) {
                    continue;
                }

                $key = $related_model->getKey();
                $role_tags[] = 'R' . (is_scalar($key) ? (string) $key : '0');
            }
        }

        sort($role_tags);
        array_push($tags, ...$role_tags);

        return array_map(strval(...), $tags);
    }

    private function getThreshold(): ?int
    {
        $value = config('cache.threshold');

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveDefaultCacheSeconds(): int
    {
        $durations = config('cache.duration');

        if (is_int($durations)) {
            return max(1, $durations);
        }

        if (! is_array($durations)) {
            return 300;
        }

        return $this->resolveDurationConfigToSeconds($durations);
    }

    /**
     * @param  array<int|string, mixed>  $durations
     */
    private function resolveDurationConfigToSeconds(array $durations): int
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
}
