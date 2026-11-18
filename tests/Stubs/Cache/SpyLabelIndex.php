<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLookup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;

/**
 * Index that delegates to another one (an in-memory index by default) and records every lookup, store and flush.
 */
final class SpyLabelIndex implements ICacheLabelIndex
{
    /**
     * @var list<CacheLookup>
     */
    public array $lookups = [];

    /**
     * @var list<array{lookup: CacheLookup, value: mixed, ttl: int, row_labels: list<string>, stored: bool}>
     */
    public array $stores = [];

    /**
     * @var list<list<string>>
     */
    public array $flushes = [];

    public function __construct(private readonly ICacheLabelIndex $inner = new ArrayLabelIndex()) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $lookup = $this->inner->lookup($key, $dependencies);
        $this->lookups[] = $lookup;

        return $lookup;
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        $stored = $this->inner->store($lookup, $value, $ttl, $row_labels);
        $this->stores[] = ['lookup' => $lookup, 'value' => $value, 'ttl' => $ttl, 'row_labels' => $row_labels, 'stored' => $stored];

        return $stored;
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        $this->inner->forget($lookup);
    }

    #[Override]
    public function flush(string ...$labels): void
    {
        $this->flushes[] = array_values($labels);
        $this->inner->flush(...$labels);
    }

    #[Override]
    public function pruneStale(): int
    {
        return $this->inner->pruneStale();
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return $this->inner->supportsRowLabels();
    }
}
