<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;
use Predis\PredisException;
use RedisException;
use Throwable;

/**
 * Wraps the primary index of a failover cache. While the primary throws, every lookup misses and
 * nothing is stored; invalidations issued meanwhile are lost, so the first successful call after
 * an outage flushes every entry (`crud`) before anything else.
 *
 * The outage is recorded in a marker on the host-local `file` cache store, so a later request,
 * Octane worker or queue worker on the same host still flushes after recovery. Residual: a host
 * that never saw a failure may serve stale entries until some host flushes `crud` (Redis is
 * shared, so one flush clears all). Only connection failures count as an outage; any other
 * exception is a bug and propagates.
 */
final class FailoverLabelIndex implements ICacheLabelIndex
{
    public const string MARKER_KEY = 'cache-labels:degraded';

    private const int MARKER_CHECK_MICROS = 1_000_000;

    private bool $degraded = false;

    private int $checked_at = 0;

    public function __construct(private readonly ICacheLabelIndex $primary, private readonly ?Repository $marker = null) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        return $this->guard(
            fn (): CacheLookup => $this->primary->lookup($key, $dependencies),
            static fn (): CacheLookup => new CacheLookup($key, false, null, Clock::micros(), $dependencies),
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

    #[Override]
    public function supportsRowLabels(): bool
    {
        return $this->primary->supportsRowLabels();
    }

    private static function isOutage(Throwable $throwable): bool
    {
        return $throwable instanceof RedisException
            || (class_exists(PredisException::class) && $throwable instanceof PredisException);
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
        try {
            if ($this->isDegraded()) {
                $this->primary->flush(CacheLabel::all());
                $this->setDegraded(false);
            }

            return $call();
        } catch (Throwable $throwable) {
            if (! self::isOutage($throwable)) {
                throw $throwable;
            }

            if (! $this->isDegraded()) {
                report($throwable);
            }

            $this->setDegraded(true);

            return $fallback();
        }
    }

    private function isDegraded(): bool
    {
        if ($this->degraded) {
            return true;
        }

        $now = Clock::micros();

        if ($now - $this->checked_at >= self::MARKER_CHECK_MICROS) {
            $this->checked_at = $now;
            $this->degraded = (bool) $this->markerStore()->get(self::MARKER_KEY, false);
        }

        return $this->degraded;
    }

    private function setDegraded(bool $degraded): void
    {
        if ($degraded) {
            $this->markerStore()->forever(self::MARKER_KEY, true);
        } else {
            $this->markerStore()->forget(self::MARKER_KEY);
        }

        $this->degraded = $degraded;
        $this->checked_at = Clock::micros();
    }

    private function markerStore(): Repository
    {
        return $this->marker ?? Cache::store('file');
    }
}
