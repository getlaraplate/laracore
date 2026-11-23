<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IResolvesCacheLabelKeys;
use Override;

/**
 * Index for stores that cannot be labelled: every lookup misses and nothing is kept.
 */
final class NullLabelIndex implements ICacheLabelIndex, IResolvesCacheLabelKeys
{
    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        return new CacheLookup($key, false, null, Clock::micros(), $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        return false;
    }

    #[Override]
    public function forget(CacheLookup $lookup): void {}

    #[Override]
    public function flush(string ...$labels): void {}

    #[Override]
    public function pruneStale(): int
    {
        return 0;
    }

    #[Override]
    public function resolveKey(string $key, CacheDependencies $dependencies): string
    {
        return $key;
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return false;
    }
}
