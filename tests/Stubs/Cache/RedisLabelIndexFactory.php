<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use Modules\Core\Cache\Labels\RedisLabelIndex;
use PHPUnit\Framework\SkippedWithMessageException;
use Throwable;

/**
 * Builds Redis label indexes on the `cache` connection, each under its own prefix, and removes their keys.
 */
final class RedisLabelIndexFactory
{
    /**
     * @var list<string>
     */
    private static array $prefixes = [];

    /**
     * @throws SkippedWithMessageException when no Redis answers on the `cache` connection.
     */
    public static function make(): RedisLabelIndex
    {
        return new RedisLabelIndex(self::store());
    }

    /**
     * A Redis store on the `cache` connection under a prefix of its own, removed by {@see cleanup()}.
     *
     * @throws SkippedWithMessageException when no Redis answers on the `cache` connection.
     */
    public static function store(): RedisStore
    {
        try {
            Redis::connection('cache')->ping();
        } catch (Throwable $throwable) {
            throw new SkippedWithMessageException('Redis is not reachable: ' . $throwable->getMessage());
        }

        $prefix = 'labeltest:' . bin2hex(random_bytes(6)) . ':';
        self::$prefixes[] = $prefix;

        return new RedisStore(app('redis'), $prefix, 'cache');
    }

    public static function cleanup(): void
    {
        $prefixes = self::$prefixes;
        self::$prefixes = [];

        if ($prefixes === []) {
            return;
        }

        $connection = Redis::connection('cache');
        $client_prefix = method_exists($connection->client(), 'getOption') ? (string) $connection->client()->getOption(\Redis::OPT_PREFIX) : '';

        foreach ($prefixes as $prefix) {
            $cursor = $connection instanceof PhpRedisConnection ? null : 0;

            do {
                $reply = $connection->scan($cursor, ['match' => $client_prefix . $prefix . '*', 'count' => 1000]);

                if ($reply === false) {
                    break;
                }

                [$cursor, $keys] = $reply;

                foreach ($keys as $key) {
                    $connection->del($client_prefix !== '' && str_starts_with($key, $client_prefix) ? mb_substr($key, mb_strlen($client_prefix)) : $key);
                }
            } while ((string) $cursor !== '0');
        }
    }
}
