<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IReportsCacheEvictionPolicy;
use Modules\Core\Contracts\IResolvesCacheLabelKeys;
use Override;
use PDOException;
use Predis\PredisException;
use RedisException;
use Throwable;

/**
 * Guards an index whose store can go down (Redis, alone or as the first store of a failover cache). While the primary
 * throws, every lookup misses and nothing is stored; invalidations issued meanwhile are lost, so the first successful
 * call after an outage flushes every entry stored through this wrapper before anything else: each entry carries
 * {@see CacheLabel::stored()}, whatever it holds (CRUD responses, ACL resolutions, settings, documentation, ...).
 *
 * The outage is recorded in a marker on the host-local `file` cache store, holding the time of the last failed call,
 * so a later request, Octane worker or queue worker on the same host still flushes after recovery. While the primary
 * is down, each process tries it at most once a second: an instance reads the shared marker at most once a second and
 * leaves the primary alone for a second after the failure it records, so a request never pays one connection timeout
 * per call. The pacing is per process, not per host: processes that read the marker before another one's failure try
 * the primary on their own, at most once a second each.
 * Residual: a host that never saw a failure may serve stale entries until some host flushes the label (Redis is
 * shared, so one flush clears all). Only connection failures count as an outage: a Redis or Predis exception, an error
 * reply the primary reports ({@see LabelIndexUnavailableException}, such as a flush refused under `-OOM`), and a
 * database exception Laravel's lost connection detector recognises (a failover cache whose first store is `database`);
 * any other exception is a bug and propagates. A memcached primary throws nothing when its server is down (it answers
 * misses and failed writes): {@see VersionCounterLabelIndex} reads the client's result code after every call and
 * throws on a failed one, so it degrades on reads as on flushes, see "Known limits" in `CACHE_LABELS_DEVELOPER.md`.
 */
final class FailoverLabelIndex implements ICacheLabelIndex, IReportsCacheEvictionPolicy, IResolvesCacheLabelKeys
{
    public const string MARKER_KEY = 'cache-labels:degraded';

    /**
     * How often the marker is read again, and how long the primary is left alone after a failed call.
     */
    private const int INTERVAL_MICROS = 1_000_000;

    /**
     * Microseconds of the last failed call on the primary, as last read or seen; null while the primary serves.
     */
    private ?int $failed_at = null;

    private ?int $checked_at = null;

    public function __construct(private readonly ICacheLabelIndex $primary, private readonly ?Repository $marker = null) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $dependencies = $dependencies->withLabels(CacheLabel::stored());

        return $this->guard(
            fn (): CacheLookup => $this->primary->lookup($key, $dependencies),
            // Started at the beginning of time: any flush recorded since refuses a store of what was computed now.
            static fn (): CacheLookup => new CacheLookup($key, false, null, 0, $dependencies),
        );
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        if ($this->isDegraded()) {
            // The value was computed during the outage and the flush it needs may predate it: drop it.
            $this->guard(static fn (): bool => true, static fn (): bool => false);

            return false;
        }

        return $this->guard(fn (): bool => $this->primary->store($lookup, $value, $ttl, $row_labels), static fn (): bool => false);
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        $this->guard(fn () => $this->primary->forget($lookup), static fn (): null => null);
    }

    #[Override]
    public function flush(string ...$labels): void
    {
        $this->guard(fn () => $this->primary->flush(...$labels), static fn (): null => null);
    }

    #[Override]
    public function pruneStale(): int
    {
        return $this->guard(fn (): int => $this->primary->pruneStale(), static fn (): int => 0);
    }

    /**
     * The key of the primary, with the `stored` label every entry carries here; null while the primary is down or when
     * it cannot tell the key without a lookup.
     */
    #[Override]
    public function resolveKey(string $key, CacheDependencies $dependencies): ?string
    {
        if (! $this->primary instanceof IResolvesCacheLabelKeys) {
            return null;
        }

        $dependencies = $dependencies->withLabels(CacheLabel::stored());

        return $this->guard(fn (): ?string => $this->primary->resolveKey($key, $dependencies), static fn (): null => null);
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return $this->primary->supportsRowLabels();
    }

    /**
     * The policy of the primary; null while it is down or when it cannot tell.
     */
    #[Override]
    public function evictionPolicy(): ?string
    {
        if (! $this->primary instanceof IReportsCacheEvictionPolicy) {
            return null;
        }

        return $this->guard(fn (): ?string => $this->primary->evictionPolicy(), static fn (): null => null);
    }

    /**
     * Whether the primary was found down and has not served since, on this host.
     */
    public function isDegraded(): bool
    {
        $now = Clock::micros();

        if ($this->checked_at === null || $now - $this->checked_at >= self::INTERVAL_MICROS) {
            $this->checked_at = $now;
            $this->failed_at = self::failureTimeOf($this->markerStore()->get(self::MARKER_KEY));
        }

        return $this->failed_at !== null;
    }

    private static function isOutage(Throwable $throwable): bool
    {
        return $throwable instanceof RedisException
            || $throwable instanceof LabelIndexUnavailableException
            || (class_exists(PredisException::class) && $throwable instanceof PredisException)
            || (($throwable instanceof QueryException || $throwable instanceof PDOException) && self::lostConnection($throwable));
    }

    /**
     * Whether a database error is a refused, lost or timed out connection, as Laravel decides it when it reconnects.
     */
    private static function lostConnection(Throwable $throwable): bool
    {
        $detector = app()->bound(LostConnectionDetectorContract::class)
            ? app(LostConnectionDetectorContract::class)
            : new LostConnectionDetector();

        return $detector->causedByLostConnection($throwable);
    }

    /**
     * The failure time a marker holds; a marker written before it held one (`true`) allows a retry at once.
     */
    private static function failureTimeOf(mixed $marker): ?int
    {
        return match (true) {
            is_int($marker) => $marker,
            is_numeric($marker) => (int) $marker,
            $marker === null, $marker === false => null,
            default => 0,
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @param  callable(): T  $fallback
     * @return T
     */
    private function guard(callable $call, callable $fallback): mixed
    {
        $degraded = $this->isDegraded();

        if ($degraded && Clock::micros() - (int) $this->failed_at < self::INTERVAL_MICROS) {
            return $fallback();
        }

        try {
            if ($degraded) {
                $this->primary->flush(CacheLabel::stored());
                $this->markRecovered();
            }

            return $call();
        } catch (Throwable $throwable) {
            if (! self::isOutage($throwable)) {
                throw $throwable;
            }

            if (! $degraded) {
                report($throwable);
            }

            $this->markFailed();

            return $fallback();
        }
    }

    private function markFailed(): void
    {
        $now = Clock::micros();
        $this->markerStore()->forever(self::MARKER_KEY, $now);
        $this->failed_at = $now;
        $this->checked_at = $now;
    }

    private function markRecovered(): void
    {
        $this->markerStore()->forget(self::MARKER_KEY);
        $this->failed_at = null;
        $this->checked_at = Clock::micros();
    }

    private function markerStore(): Repository
    {
        return $this->marker ?? Cache::store('file');
    }
}
