<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLookup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;

/**
 * Index that only records the labels flushed, one entry per flush() call.
 */
final class RecordingLabelIndex implements ICacheLabelIndex
{
    /**
     * @var list<list<string>>
     */
    public array $flushes = [];

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        return new CacheLookup($key, false, null, 1, $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        return true;
    }

    #[Override]
    public function forget(CacheLookup $lookup): void {}

    #[Override]
    public function flush(string ...$labels): void
    {
        $this->flushes[] = array_values($labels);
    }

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

    /**
     * Every label flushed so far, flattened and unique.
     *
     * @return list<string>
     */
    public function flushedLabels(): array
    {
        return array_values(array_unique(array_merge(...$this->flushes) ?: []));
    }
}
