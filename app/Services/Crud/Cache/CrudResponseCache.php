<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud\Cache;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Cache\Labels\CacheScope;
use Modules\Core\Cache\Labels\PendingLabelFlushes;
use Modules\Core\Cache\Repository;
use Modules\Core\Casts\CrudRequestData;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\SelectRequestData;
use Modules\Core\Casts\TreeRequestData;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response cache of CRUD reads: look the response up, compute it only on a miss.
 *
 * A response is cached when the main model uses {@see \Modules\Core\Cache\HasCache} and the dependencies of the
 * request are complete. It is stored under a key built from the request ({@see keyFor()}) with the labels of
 * {@see CrudCacheDependencyResolver}, for the default ttl ({@see Repository::resolveDefaultCacheSeconds()}), and only
 * when its status is 200 and the connection of the model has no transaction open. A hit still runs the permission check of the operation before it is returned. When the
 * index keeps nothing (a failover cache while Redis is down, the null store) every read is computed.
 */
final readonly class CrudResponseCache
{
    public function __construct(
        private ICacheLabelIndex $index,
        private CrudCacheDependencyResolver $resolver,
        private AuthorizationService $auth,
    ) {}

    /**
     * @param  Closure(): CrudResult  $compute  Runs the read, with its own permission checks.
     * @param  Closure(CrudResult): Response  $render
     * @param  Closure(): void  $authorize  The permission checks of the read, run on a hit.
     */
    public function remember(CrudRequestData $request_data, Closure $compute, Closure $render, Closure $authorize): Response
    {
        $model = $request_data->model;

        if (! $request_data instanceof SelectRequestData || ! self::usesCache($model)) {
            return $render($compute());
        }

        $request = $request_data->request;
        $user = $this->auth->resolveUser($request);
        $role_keys = $user instanceof User ? CacheScope::roleKeys($user) : [];

        try {
            $dependencies = $this->resolver->forRequest($model, $request_data, $user, self::readsOneRecord($request_data), $role_keys, $this->index->supportsRowLabels());
        } catch (InvalidArgumentException) {
            // A name that is not a relation: the read refuses it itself, after its permission check, so a caller
            // without the permission is told 403 rather than which names exist.
            return $render($compute());
        }

        if (! $dependencies->complete) {
            return $render($compute());
        }

        $lookup = $this->index->lookup($this->buildKey($request, $user, $role_keys), $dependencies);

        if ($lookup->hit && self::isSnapshot($lookup->value)) {
            $authorize();

            return self::restore($lookup->value);
        }

        $result = $compute();
        $response = $render($result);

        // A read inside a transaction may come from a snapshot older than the lookup, which a flush cannot refuse.
        if ($response->getStatusCode() !== Response::HTTP_OK || PendingLabelFlushes::inTransaction($model->getConnection())) {
            return $response;
        }

        // An index without row labels keeps nothing known after the query: its key was fixed at the lookup.
        $labels_after_query = $this->index->supportsRowLabels()
            ? $this->resolver->labelsAfterQuery($model, $request_data, $dependencies, $result->data)
            : [];

        if ($labels_after_query !== null) {
            $this->index->store($lookup, self::snapshot($response), Repository::resolveDefaultCacheSeconds(), $labels_after_query);
        }

        return $response;
    }

    /**
     * The key of the response to a request: its path, its input with keys sorted, the caller (the anonymous user
     * for a request without one) with its roles, the personal access token that authenticated it, and the locale.
     */
    public function keyFor(Request $request): string
    {
        $user = $this->auth->resolveUser($request);

        return $this->buildKey($request, $user, $user instanceof User ? CacheScope::roleKeys($user) : []);
    }

    private static function usesCache(Model $model): bool
    {
        return method_exists($model, 'usesCache') && $model->usesCache() === true;
    }

    /**
     * True for a read of one record (detail, history, a tree of a given key), whose main table is a row dependency.
     */
    private static function readsOneRecord(SelectRequestData $request_data): bool
    {
        if ($request_data instanceof TreeRequestData) {
            $key = $request_data->primaryKey;

            return $request_data->request->has(is_array($key) ? $key[0] : $key);
        }

        return $request_data instanceof DetailRequestData;
    }

    /**
     * Two tokens of one user carry different abilities, so their responses differ and are never served for each
     * other. A session carries no personal token.
     */
    private static function tokenKey(?User $user): ?string
    {
        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $key = $token->getKey();

        return is_scalar($key) ? (string) $key : '0';
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private static function sortedByKey(array $values): array
    {
        ksort($values);

        foreach ($values as $name => $value) {
            if (is_array($value)) {
                $values[$name] = self::sortedByKey($value);
            }
        }

        return $values;
    }

    /**
     * @return array{status: int, content: string, headers: array<string, list<string|null>>, json: bool}
     */
    private static function snapshot(Response $response): array
    {
        $headers = $response->headers->allPreserveCaseWithoutCookies();
        unset($headers['Date']);

        return [
            'status' => $response->getStatusCode(),
            'content' => (string) $response->getContent(),
            'headers' => $headers,
            'json' => $response instanceof JsonResponse,
        ];
    }

    /**
     * @phpstan-assert-if-true array{status: int, content: string, headers: array<string, list<string|null>>, json: bool} $value
     */
    private static function isSnapshot(mixed $value): bool
    {
        return is_array($value)
            && is_int($value['status'] ?? null)
            && is_string($value['content'] ?? null)
            && is_array($value['headers'] ?? null)
            && is_bool($value['json'] ?? null);
    }

    /**
     * @param  array{status: int, content: string, headers: array<string, list<string|null>>, json: bool}  $snapshot
     */
    private static function restore(array $snapshot): Response
    {
        return $snapshot['json']
            ? JsonResponse::fromJsonString($snapshot['content'], $snapshot['status'], $snapshot['headers'])
            : new Response($snapshot['content'], $snapshot['status'], $snapshot['headers']);
    }

    /**
     * @param  list<string>  $role_keys
     */
    private function buildKey(Request $request, ?User $user, array $role_keys): string
    {
        $roles = array_values(array_unique($role_keys));
        sort($roles, SORT_STRING);

        $user_key = $user?->getKey();
        $input = $request->input();

        return 'crud:' . hash('sha256', serialize([
            'path' => $request->getPathInfo(),
            'input' => is_array($input) ? self::sortedByKey($input) : [],
            'user' => is_scalar($user_key) ? (string) $user_key : null,
            'roles' => $roles,
            'token' => self::tokenKey($user),
            'locale' => App::getLocale(),
        ]));
    }
}
