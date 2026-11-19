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
 * the flush time in a key of its own. Lookups and flush records use the Redis server clock
 * (TIME, microseconds), so several application servers agree on the ordering.
 *
 * A flush record only has to outlive the stores racing it: a store is refused when its lookup
 * started more than the store window ago, so a record expires one window after the flush and
 * the records held are only those of the labels flushed within the last window.
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

    private const string FLUSHED_KEY = 'labelflush:';

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
     * KEYS: entry key, then the n label sets, then the n flush records. ARGV: started_at, value, ttl, window (micros).
     * Returns 1 when stored, 0 when the lookup started more than the window ago or a label was flushed after it.
     */
    private const string LUA_STORE = <<<'LUA'
local now = tonumber(micros())
local started_at = tonumber(ARGV[1])
if now - started_at > tonumber(ARGV[4]) then
    return 0
end
local labels = (#KEYS - 1) / 2
if labels > 0 then
    local flushed = redis.call('MGET', unpack(KEYS, 2 + labels, 1 + 2 * labels))
    for i = 1, labels do
        if flushed[i] and tonumber(flushed[i]) > started_at then
            return 0
        end
    end
end
local ttl = tonumber(ARGV[3])
local expires_at = string.format('%d', now + ttl * 1000000)
redis.call('SET', KEYS[1], ARGV[2], 'EX', ttl)
for i = 2, 1 + labels do
    redis.call('ZADD', KEYS[i], expires_at, KEYS[1])
end
return 1
LUA;

    /**
     * Flushes labels in one call when they hold few members: records the flush time of every label (on the first
     * call only) before deleting anything, then deletes members and removes them from their sets, label after label,
     * up to a budget of members per call, atomically, so a member added by a store that lands between two calls
     * keeps its membership for the next flush.
     * KEYS: the n label sets, then their n flush records. ARGV: budget, 1 to record the flush time, record ttl (ms).
     * Returns the 1-based positions of the labels that may still hold members.
     */
    private const string LUA_FLUSH = <<<'LUA'
local labels = #KEYS / 2
local budget = tonumber(ARGV[1])
if ARGV[2] == '1' then
    local now = micros()
    for i = 1, labels do
        redis.call('SET', KEYS[labels + i], now, 'PX', ARGV[3])
    end
end
local unfinished = {}
for i = 1, labels do
    if budget > 0 then
        local members = redis.call('ZRANGE', KEYS[i], 0, budget - 1)
        if #members > 0 then
            redis.call('DEL', unpack(members))
            redis.call('ZREM', KEYS[i], unpack(members))
            budget = budget - #members
        end
        if budget == 0 and redis.call('EXISTS', KEYS[i]) == 1 then
            unfinished[#unfinished + 1] = i
        end
    else
        unfinished[#unfinished + 1] = i
    end
end
return unfinished
LUA;

    /**
     * @param  int  $store_window  Seconds a computation may take between its lookup and its store; a later store is
     *                             refused. Flush records are kept as long, so they cover every store not refused.
     */
    public function __construct(private RedisStore $store, private int $store_window = 60) {}

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
            ...array_map(fn (string $label): string => $prefix . self::LABEL_KEY . $label, $labels),
            ...array_map(fn (string $label): string => $prefix . self::FLUSHED_KEY . $label, $labels),
        ];

        $stored = $connection->eval(
            self::LUA_MICROS . "\n" . self::LUA_STORE,
            count($keys),
            ...$keys,
            ...[(string) $lookup->started_at, $this->pack($value, $connection), (string) max(1, $ttl), (string) $this->windowMicros()],
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

        // A flush record must outlive every store not refused, which started its lookup at most one window ago.
        $record_ttl = (string) intdiv($this->windowMicros() + 1_000_000, 1000);

        // Redis deletes a sorted set once its last member is removed, so the set needs no delete of its own.
        foreach (array_chunk($labels, self::CHUNK) as $batch) {
            $record = true;

            while ($batch !== []) {
                $unfinished = $connection->eval(
                    self::LUA_MICROS . "\n" . self::LUA_FLUSH,
                    2 * count($batch),
                    ...array_map(fn (string $label): string => $prefix . self::LABEL_KEY . $label, $batch),
                    ...array_map(fn (string $label): string => $prefix . self::FLUSHED_KEY . $label, $batch),
                    ...[(string) self::CHUNK, $record ? '1' : '0', $record_ttl],
                );

                $record = false;
                $batch = array_values(array_map(static fn (int $position): string => $batch[$position - 1], array_filter((array) $unfinished, is_int(...))));
            }
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

        return count($seen);
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return true;
    }

    /**
     * The store window in microseconds, the unit of the Redis clock this index reads.
     */
    private function windowMicros(): int
    {
        return max(1, $this->store_window) * 1_000_000;
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
