<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLookup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IReportsCacheEvictionPolicy;
use Override;

/**
 * Index that keeps nothing and reports the eviction policy it is given.
 */
final class EvictionPolicyLabelIndex implements ICacheLabelIndex, IReportsCacheEvictionPolicy
{
    public function __construct(private readonly ?string $policy) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        return new CacheLookup($key, false, null, 1, $dependencies);
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
    public function supportsRowLabels(): bool
    {
        return true;
    }

    #[Override]
    public function evictionPolicy(): ?string
    {
        return $this->policy;
    }
}
