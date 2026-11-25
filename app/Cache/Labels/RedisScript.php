<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * Runs a Lua script on either Redis client. phpredis takes the keys and arguments as one array after the key
 * count and PhpRedisConnection converts its own call; Predis receives the command as written.
 */
final class RedisScript
{
    public static function run(Connection $connection, string $script, int $numberOfKeys, mixed ...$arguments): mixed
    {
        return $connection instanceof PhpRedisConnection
            ? $connection->eval($script, $numberOfKeys, ...$arguments)
            : $connection->command('eval', [$script, $numberOfKeys, ...$arguments]);
    }
}
