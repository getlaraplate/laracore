<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Closure;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

/**
 * HACK, for testing only: makes the Elasticsearch client announce a different major version.
 *
 * The client (`elasticsearch/elasticsearch` 9) writes `compatible-with=9` into the `Accept` and
 * `Content-Type` headers of every request, and a server of the previous major (8) refuses it with a
 * 400. This rewrites that number on the way out, so a version 9 client can be tried against a version 8
 * cluster. It changes only the header: the requests are still the ones of the version 9 client, so a
 * call the server does not know still fails, and nothing guarantees that the indexing and the searches
 * behave. It is not a supported configuration and must not be used in production; the supported way is
 * a server and a client of the same major (see `elastic.client.compatible_with`).
 */
final class ElasticsearchCompatibilityHeader
{
    private const string PATTERN = '/compatible-with=\d+/';

    /**
     * The major version in `$value` when it can be used: a positive integer, as a number or as a string
     * of digits (an environment value). Anything else, empty included, means "leave the header alone".
     */
    public static function majorOf(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * The request with `compatible-with=<major>` in its `Accept` and `Content-Type` headers; the other
     * headers, and the headers that do not name a version, are left as they are.
     */
    public static function rewrite(RequestInterface $request, int $major): RequestInterface
    {
        foreach (['Accept', 'Content-Type'] as $name) {
            if (! $request->hasHeader($name)) {
                continue;
            }

            $request = $request->withHeader(
                $name,
                array_map(
                    static fn (string $value): string => (string) preg_replace(self::PATTERN, 'compatible-with=' . $major, $value),
                    $request->getHeader($name),
                ),
            );
        }

        return $request;
    }

    /**
     * A Guzzle middleware that applies {@see self::rewrite()} to every request.
     *
     * @return Closure(callable): Closure
     */
    public static function middleware(int $major): Closure
    {
        return static fn (callable $handler): Closure => static fn (RequestInterface $request, array $options) => $handler(self::rewrite($request, $major), $options);
    }

    /**
     * The `httpClientOptions` of an Elasticsearch connection with the middleware on top of the Guzzle
     * handler (`$handler` replaces the default one, which tests use to avoid the network). The options
     * are returned unchanged when `$major` is null.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function applyTo(array $options, ?int $major, ?callable $handler = null): array
    {
        if ($major === null) {
            return $options;
        }

        $stack = HandlerStack::create($handler);
        $stack->push(self::middleware($major), 'elasticsearch-compatibility-header');

        return [...$options, 'handler' => $stack];
    }

    /**
     * The connection config with the middleware applied when `elastic.client.compatible_with` is set,
     * unchanged otherwise. Every place that builds an Elasticsearch client from the connection config
     * goes through here, so none of them is left announcing the version of the client.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function forConnection(array $config): array
    {
        $major = self::majorOf(config('elastic.client.compatible_with'));

        if ($major === null) {
            return $config;
        }

        $config['httpClientOptions'] = self::applyTo(
            is_array($config['httpClientOptions'] ?? null) ? $config['httpClientOptions'] : [],
            $major,
        );

        return $config;
    }
}
