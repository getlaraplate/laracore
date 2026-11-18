<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Modules\Core\Contracts\ICacheLabelIndex;
use Override;
use Throwable;

/**
 * Wraps the primary index of a failover cache. While the primary throws, every lookup misses and
 * nothing is stored; invalidations issued meanwhile are lost, so the first successful call after
 * an outage flushes every entry (`crud`) before anything else.
 */
final class FailoverLabelIndex implements ICacheLabelIndex
{
    private bool $degraded = false;

    public function __construct(private readonly ICacheLabelIndex $primary) {}

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
        if ($this->degraded) {
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
            if ($this->degraded) {
                $this->primary->flush(CacheLabel::all());
                $this->degraded = false;
            }

            return $call();
        } catch (Throwable) {
            $this->degraded = true;

            return $fallback();
        }
    }
}
