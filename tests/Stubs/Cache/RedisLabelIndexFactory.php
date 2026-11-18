<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Cache\RedisStore;
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
        try {
            Redis::connection('cache')->ping();
        } catch (Throwable $throwable) {
            throw new SkippedWithMessageException('Redis is not reachable: ' . $throwable->getMessage());
        }

        $prefix = 'labeltest:' . bin2hex(random_bytes(6)) . ':';
        self::$prefixes[] = $prefix;

        return new RedisLabelIndex(new RedisStore(app('redis'), $prefix, 'cache'));
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
            $connection->eval(
                "local c = '0' repeat local r = redis.call('SCAN', c, 'MATCH', ARGV[1] .. '*', 'COUNT', 1000) c = r[1] for _, k in ipairs(r[2]) do redis.call('DEL', k) end until c == '0'",
                0,
                $client_prefix . $prefix,
            );
        }
    }
}
