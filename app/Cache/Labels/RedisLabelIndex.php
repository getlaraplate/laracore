<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;
use Redis;

/**
 * Membership index on Redis. Every cached key is a member of one sorted set per label
 * (score: its expiry in microseconds); flushing a label deletes its members and records
 * the flush time in a hash. Lookups and flush records use the Redis server clock (TIME,
 * microseconds), so several application servers agree on the ordering.
 *
 * All keys live under the store prefix. Nothing is stored without an expiry.
 * A cached null reads back as a miss on phpredis with a native serializer.
 */
final readonly class RedisLabelIndex implements ICacheLabelIndex
{
    private const string LABEL_KEY = 'label:';

    private const string FLUSHED_KEY = 'labelflush';

    private const int CHUNK = 1000;

    /**
     * Returns the server time as an integer string of microseconds.
     */
    private const string LUA_MICROS = <<<'LUA'
if redis.replicate_commands then redis.replicate_commands() end
local function micros()
    local t = redis.call('TIME')
    return string.format('%d', t[1] * 1000000 + t[2])
end
LUA;

    /**
     * KEYS: entry key, flush hash, label sets. ARGV: started_at, value, ttl, label names.
     * Returns 1 when stored, 0 when a label was flushed after started_at.
     */
    private const string LUA_STORE = <<<'LUA'
local labels = #KEYS - 2
if labels > 0 then
    local flushed = redis.call('HMGET', KEYS[2], unpack(ARGV, 4, 3 + labels))
    for i = 1, labels do
        if flushed[i] and tonumber(flushed[i]) > tonumber(ARGV[1]) then
            return 0
        end
    end
end
local ttl = tonumber(ARGV[3])
local expires_at = string.format('%d', tonumber(micros()) + ttl * 1000000)
redis.call('SET', KEYS[1], ARGV[2], 'EX', ttl)
for i = 3, #KEYS do
    redis.call('ZADD', KEYS[i], expires_at, KEYS[1])
end
return 1
LUA;

    /**
     * KEYS: flush hash, label sets. ARGV: chunk size, label names.
     */
    private const string LUA_FLUSH = <<<'LUA'
local now = micros()
local chunk = tonumber(ARGV[1])
for i = 2, #KEYS do
    redis.call('HSET', KEYS[1], ARGV[i], now)
    local offset = 0
    while true do
        local members = redis.call('ZRANGE', KEYS[i], offset, offset + chunk - 1)
        if #members > 0 then
            redis.call('DEL', unpack(members))
        end
        if #members < chunk then
            break
        end
        offset = offset + chunk
    end
    redis.call('DEL', KEYS[i])
end
return 1
LUA;

    /**
     * ARGV: full key prefix as Redis sees it, glob-escaped prefix, longest ttl in seconds.
     * Returns the number of distinct stale entries dropped from the label sets.
     */
    private const string LUA_PRUNE = <<<'LUA'
local now = tonumber(micros())
local seen = {}
local removed = 0
local cursor = '0'
repeat
    local reply = redis.call('SCAN', cursor, 'MATCH', ARGV[2] .. 'label:*', 'COUNT', 1000)
    cursor = reply[1]
    for _, set in ipairs(reply[2]) do
        local stale = redis.call('ZRANGEBYSCORE', set, '-inf', now)
        for _, member in ipairs(stale) do
            if not seen[member] then
                seen[member] = true
                removed = removed + 1
            end
        end
        if #stale > 0 then
            redis.call('ZREMRANGEBYSCORE', set, '-inf', now)
        end
    end
until cursor == '0'
local hash = ARGV[1] .. 'labelflush'
local cutoff = now - tonumber(ARGV[3]) * 1000000
local flushed = redis.call('HGETALL', hash)
for i = 1, #flushed, 2 do
    if tonumber(flushed[i + 1]) < cutoff then
        redis.call('HDEL', hash, flushed[i])
    end
end
return removed
LUA;

    public function __construct(private RedisStore $store) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $connection = $this->store->connection();
        $started_at = $this->serverMicros($connection);
        $raw = $connection->get($this->store->getPrefix() . $key);

        if ($raw === null || $raw === false) {
            return new CacheLookup($key, false, null, $started_at, $dependencies);
        }

        return new CacheLookup($key, true, $this->unserialize($raw, $connection), $started_at, $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        $connection = $this->store->connection();
        $labels = array_values(array_unique([...$lookup->dependencies->labels, ...$row_labels]));
        $prefix = $this->store->getPrefix();

        $keys = [
            $prefix . $lookup->key,
            $prefix . self::FLUSHED_KEY,
            ...array_map(fn (string $label): string => $prefix . self::LABEL_KEY . $label, $labels),
        ];

        $stored = $connection->eval(
            self::LUA_MICROS . "\n" . self::LUA_STORE,
            count($keys),
            ...$keys,
            ...[(string) $lookup->started_at, $this->pack($value, $connection), (string) max(1, $ttl), ...$labels],
        );

        return (int) $stored === 1;
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        $this->store->connection()->del($this->store->getPrefix() . $lookup->key);
    }

    #[Override]
    public function flush(string ...$labels): void
    {
        $labels = array_values(array_unique($labels));

        if ($labels === []) {
            return;
        }

        $prefix = $this->store->getPrefix();
        $keys = [
            $prefix . self::FLUSHED_KEY,
            ...array_map(fn (string $label): string => $prefix . self::LABEL_KEY . $label, $labels),
        ];

        $this->store->connection()->eval(
            self::LUA_MICROS . "\n" . self::LUA_FLUSH,
            count($keys),
            ...$keys,
            ...[(string) self::CHUNK, ...$labels],
        );
    }

    #[Override]
    public function pruneStale(): int
    {
        $connection = $this->store->connection();
        $prefix = $this->connectionPrefix($connection) . $this->store->getPrefix();
        $durations = array_filter((array) config('cache.duration'), is_int(...));
        $longest = $durations === [] ? 86400 : max($durations);

        return (int) $connection->eval(
            self::LUA_MICROS . "\n" . self::LUA_PRUNE,
            0,
            $prefix,
            $this->escapeGlob($prefix),
            (string) $longest,
        );
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return true;
    }

    /**
     * Redis TIME in microseconds, the ordering token of this index.
     */
    private function serverMicros(Connection $connection): int
    {
        /** @var array{0: int|string, 1: int|string} $time */
        $time = $connection->command('time');

        return (int) $time[0] * 1_000_000 + (int) $time[1];
    }

    /**
     * Same encoding as {@see RedisStore} for values passed through eval.
     */
    private function pack(mixed $value, Connection $connection): mixed
    {
        if ($connection instanceof PhpRedisConnection) {
            if ($connection->serialized()) {
                return $connection->pack([$value])[0];
            }

            if ($connection->compressed()) {
                return $connection->pack([$this->serialize($value)])[0];
            }
        }

        return $this->serialize($value);
    }

    private function serialize(mixed $value): mixed
    {
        return is_numeric($value) && is_finite((float) $value) ? $value : serialize($value);
    }

    private function unserialize(mixed $raw, Connection $connection): mixed
    {
        if ($connection instanceof PhpRedisConnection && $connection->serialized()) {
            return $raw;
        }

        return is_numeric($raw) ? $raw : unserialize((string) $raw);
    }

    /**
     * Prefix the client itself prepends to keys (phpredis OPT_PREFIX, Predis prefix option),
     * which Lua scripts building key names by hand must add explicitly.
     */
    private function connectionPrefix(Connection $connection): string
    {
        $client = $connection->client();

        if ($connection instanceof PhpRedisConnection) {
            return (string) $client->getOption(Redis::OPT_PREFIX);
        }

        if (method_exists($client, 'getOptions')) {
            return (string) $client->getOptions()->prefix?->getPrefix();
        }

        return '';
    }

    private function escapeGlob(string $value): string
    {
        return (string) preg_replace('/[\\\\*?\[\]]/', '\\\\$0', $value);
    }
}
