<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IResolvesCacheLabelKeys;
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
 * the records held are only those of the labels flushed within the last window. Hosts may run with different windows:
 * each process announces its own in one key ({@see WINDOW_KEY}) before its first lookup and then once per window, the
 * key keeping the largest window announced in the last two windows of its host, and a flush keeps its records for the
 * larger of its own window and the one announced, so a host with a longer window is covered by the flushes of every
 * other host.
 *
 * All keys live under the store prefix. Nothing is stored without an expiry.
 * A cached null reads back as a miss on phpredis with a native serializer.
 *
 * Redis Cluster is not supported: the store and flush scripts touch an entry and its label sets, which live in
 * different hash slots.
 */
final class RedisLabelIndex implements ICacheLabelIndex, IResolvesCacheLabelKeys
{
    private const string LABEL_KEY = 'label:';

    private const string FLUSHED_KEY = 'labelflush:';

    /**
     * The largest store window in use, in milliseconds, announced by the processes that look values up.
     */
    private const string WINDOW_KEY = 'labelwindow';

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
     * Announces the store window of a process and returns the server time, as {@see LUA_MICROS} gives it. The window
     * key keeps the largest window announced: a lower one leaves it alone, an equal one extends it, a higher or a
     * missing one replaces it. It expires after two windows, so a window no process announces any more is dropped,
     * while a process announcing once per window keeps it alive for every store it may still make.
     * KEYS: the window key. ARGV: the window (ms).
     */
    private const string LUA_ANNOUNCE = <<<'LUA'
local own = tonumber(ARGV[1])
local recorded = redis.call('GET', KEYS[1])
if not recorded or tonumber(recorded) < own then
    redis.call('SET', KEYS[1], ARGV[1], 'PX', string.format('%d', 2 * own))
elseif tonumber(recorded) == own then
    redis.call('PEXPIRE', KEYS[1], string.format('%d', 2 * own))
end
return micros()
LUA;

    /**
     * Flushes labels in one call when they hold few members: deletes members and removes them from their sets, label
     * after label, up to a budget of members per call, then records the flush time of every label (on the first call
     * only), atomically, so a member added by a store that lands between two calls keeps its membership for the next
     * flush. The deletes come first: under `maxmemory` with `noeviction` Redis refuses a script whose first write is a
     * `SET`, while deletes are allowed and let the script write on; inside one atomic script the order does not change
     * what a racing store sees. A flush record lives one second longer than the larger of the flusher's window and the
     * window announced ({@see LUA_ANNOUNCE}).
     * KEYS: the n label sets, then their n flush records, then the window key. ARGV: budget, 1 to record the flush time,
     * the store window of the flusher (ms).
     * Returns the 1-based positions of the labels that may still hold members.
     */
    private const string LUA_FLUSH = <<<'LUA'
local labels = (#KEYS - 1) / 2
local budget = tonumber(ARGV[1])
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
if ARGV[2] == '1' then
    local now = micros()
    local window = tonumber(ARGV[3])
    local announced = redis.call('GET', KEYS[#KEYS])
    if announced and tonumber(announced) > window then
        window = tonumber(announced)
    end
    local ttl = string.format('%d', window + 1000)
    for i = 1, labels do
        redis.call('SET', KEYS[labels + i], now, 'PX', ttl)
    end
end
return unfinished
LUA;

    /**
     * Monotonic time (ns) of this process's last announcement of its window, null before the first one.
     */
    private ?int $announced_at = null;

    /**
     * @param  int  $store_window  Seconds a computation may take between its lookup and its store; a later store is
     *                             refused. Flush records are kept as long, or as long as the largest window another
     *                             host announced, so they cover every store not refused.
     */
    public function __construct(private readonly RedisStore $store, private readonly int $store_window = 60) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $connection = $this->store->connection();
        $started_at = $this->announcementDue() ? $this->announce($connection) : $this->serverMicros($connection);
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

        // A flush record must outlive every store not refused, which started its lookup at most one window ago: the
        // script keeps it for the largest window announced, one second more.
        $window = (string) intdiv($this->windowMicros(), 1000);
        $window_key = $prefix . self::WINDOW_KEY;

        // Redis deletes a sorted set once its last member is removed, so the set needs no delete of its own.
        foreach (array_chunk($labels, self::CHUNK) as $batch) {
            $record = true;

            while ($batch !== []) {
                $unfinished = $connection->eval(
                    self::LUA_MICROS . "\n" . self::LUA_FLUSH,
                    2 * count($batch) + 1,
                    ...array_map(fn (string $label): string => $prefix . self::LABEL_KEY . $label, $batch),
                    ...array_map(fn (string $label): string => $prefix . self::FLUSHED_KEY . $label, $batch),
                    ...[$window_key, (string) self::CHUNK, $record ? '1' : '0', $window],
                );

                // phpredis answers an error reply (an `-OOM`, a script error) with false where Predis throws: the
                // flush did not happen, so it must not pass for done.
                if (! is_array($unfinished)) {
                    throw LabelIndexUnavailableException::errorReply('flush', $unfinished, $this->lastError($connection));
                }

                $record = false;
                $batch = array_values(array_map(static fn (int $position): string => $batch[$position - 1], array_filter($unfinished, is_int(...))));
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
    public function resolveKey(string $key, CacheDependencies $dependencies): string
    {
        return $key;
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return true;
    }

    /**
     * Whether this process has not announced its window yet, or announced it one window ago or more.
     */
    private function announcementDue(): bool
    {
        return $this->announced_at === null || hrtime(true) - $this->announced_at >= $this->windowMicros() * 1000;
    }

    /**
     * Announces the store window of this process ({@see LUA_ANNOUNCE}) and returns the server time, taken after the
     * announcement: a flush that follows the lookup already sees the window.
     *
     * @throws LabelIndexUnavailableException when Redis answers the script with an error reply.
     */
    private function announce(Connection $connection): int
    {
        $announced_at = hrtime(true);
        $now = $connection->eval(
            self::LUA_MICROS . "\n" . self::LUA_ANNOUNCE,
            1,
            $this->store->getPrefix() . self::WINDOW_KEY,
            (string) intdiv($this->windowMicros(), 1000),
        );

        if (! is_numeric($now)) {
            throw LabelIndexUnavailableException::errorReply('announce', $now, $this->lastError($connection));
        }

        $this->announced_at = $announced_at;

        return (int) $now;
    }

    /**
     * The error message phpredis kept for the last failed command, empty when there is none or the client is Predis.
     */
    private function lastError(Connection $connection): string
    {
        $client = $connection->client();

        if (! $client instanceof Redis) {
            return '';
        }

        $error = $client->getLastError();
        $client->clearLastError();

        return is_string($error) ? $error : '';
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
