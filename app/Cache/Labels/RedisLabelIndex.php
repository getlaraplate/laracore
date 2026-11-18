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
 *
 * Redis Cluster is not supported: the store and flush scripts touch an entry and its label sets, which live in
 * different hash slots.
 */
final readonly class RedisLabelIndex implements ICacheLabelIndex
{
    private const string LABEL_KEY = 'label:';

    private const string FLUSHED_KEY = 'labelflush';

    /**
     * Page size for every PHP-driven loop, so no single Redis command or script does unbounded work.
     */
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
     * Records the flush time of labels before anything is deleted.
     * KEYS: flush hash. ARGV: label names.
     */
    private const string LUA_MARK_FLUSHED = <<<'LUA'
local now = micros()
for i = 1, #ARGV do
    redis.call('HSET', KEYS[1], ARGV[i], now)
end
return 1
LUA;

    /**
     * Deletes one chunk of a label's members and removes the same members from its set, atomically, so a member added
     * by a store that lands between two chunks keeps its membership for the next flush.
     * KEYS: label set. ARGV: chunk size. Returns how many members were removed (0 when the set is empty or gone).
     */
    private const string LUA_FLUSH_CHUNK = <<<'LUA'
local members = redis.call('ZRANGE', KEYS[1], 0, tonumber(ARGV[1]) - 1)
if #members == 0 then
    return 0
end
redis.call('DEL', unpack(members))
redis.call('ZREM', KEYS[1], unpack(members))
return #members
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
        $labels = CacheLabel::storedUnder($lookup->dependencies, $row_labels);
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

        $connection = $this->store->connection();
        $prefix = $this->store->getPrefix();

        $connection->eval(
            self::LUA_MICROS . "\n" . self::LUA_MARK_FLUSHED,
            1,
            $prefix . self::FLUSHED_KEY,
            ...$labels,
        );

        // Redis deletes a sorted set once its last member is removed, so the set needs no delete of its own.
        foreach ($labels as $label) {
            do {
                $removed = (int) $connection->eval(self::LUA_FLUSH_CHUNK, 1, $prefix . self::LABEL_KEY . $label, (string) self::CHUNK);
            } while ($removed > 0);
        }
    }

    #[Override]
    public function pruneStale(): int
    {
        $connection = $this->store->connection();
        $now = $this->serverMicros($connection);
        $client_prefix = $this->connectionPrefix($connection);
        $prefix = $this->store->getPrefix();
        $seen = [];
        $cursor = $this->initialCursor($connection);

        do {
            $reply = $connection->scan($cursor, ['match' => $this->escapeGlob($client_prefix . $prefix) . self::LABEL_KEY . '*', 'count' => self::CHUNK]);

            if ($reply === false) {
                break;
            }

            [$cursor, $sets] = $reply;

            foreach ($this->withoutConnectionPrefix($sets, $connection) as $set) {
                while (($stale = $connection->zrangebyscore($set, '-inf', (string) $now, ['limit' => [0, self::CHUNK]])) !== []) {
                    $connection->zrem($set, ...$stale);

                    foreach ($stale as $member) {
                        $seen[$member] = true;
                    }
                }
            }
        } while ((string) $cursor !== '0');

        $this->pruneFlushTimes($connection, $now, $prefix . self::FLUSHED_KEY);

        return count($seen);
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return true;
    }

    /**
     * Drops flush records older than the longest ttl in use: no lookup can still be racing them.
     */
    private function pruneFlushTimes(Connection $connection, int $now, string $hash): void
    {
        $durations = array_map(intval(...), array_filter((array) config('cache.duration'), is_numeric(...)));
        $cutoff = $now - ($durations === [] ? 86400 : max($durations)) * 1_000_000;
        $cursor = $this->initialCursor($connection);

        do {
            $reply = $connection->hscan($hash, $cursor, ['count' => self::CHUNK]);

            if ($reply === false) {
                break;
            }

            [$cursor, $fields] = $reply;
            $old = [];

            foreach ($fields as $label => $flushed_at) {
                if ($cutoff > (int) $flushed_at) {
                    $old[] = (string) $label;
                }
            }

            if ($old !== []) {
                $connection->hdel($hash, ...$old);
            }
        } while ((string) $cursor !== '0');
    }

    /**
     * Members and scanned keys are real key names, which already include the client prefix
     * that the client would add again to the commands it sends.
     *
     * @param  array<array-key, string>  $keys
     * @return list<string>
     */
    private function withoutConnectionPrefix(array $keys, Connection $connection): array
    {
        $client_prefix = $this->connectionPrefix($connection);

        return array_values(array_map(
            fn (string $key): string => $client_prefix !== '' && str_starts_with($key, $client_prefix) ? mb_substr($key, mb_strlen($client_prefix)) : $key,
            $keys,
        ));
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

    /**
     * phpredis starts a scan from null, Predis from 0.
     */
    private function initialCursor(Connection $connection): ?int
    {
        return $connection instanceof PhpRedisConnection ? null : 0;
    }

    private function escapeGlob(string $value): string
    {
        return (string) preg_replace('/[\\\\*?\[\]]/', '\\\\$0', $value);
    }
}
