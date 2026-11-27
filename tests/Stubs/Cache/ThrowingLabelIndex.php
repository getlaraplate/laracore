<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLookup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;
use RedisException;
use Throwable;

/**
 * Index that records its calls and throws while `$failing` is set.
 */
final class ThrowingLabelIndex implements ICacheLabelIndex
{
    public bool $failing = false;

    public ?Throwable $failure = null;

    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * Every call made, failed ones included.
     */
    public int $attempts = 0;

    /**
     * @var list<CacheDependencies>
     */
    public array $dependencies = [];

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $this->record('lookup');
        $this->dependencies[] = $dependencies;

        return new CacheLookup($key, true, 'cached', 1, $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        $this->record('store');

        return true;
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        $this->record('forget');
    }

    #[Override]
    public function flush(string ...$labels): void
    {
        $this->record('flush:' . implode(',', $labels));
    }

    #[Override]
    public function pruneStale(): int
    {
        $this->record('prune');

        return 3;
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return true;
    }

    private function record(string $call): void
    {
        $this->attempts++;

        if ($this->failing) {
            throw $this->failure ?? new RedisException('primary down');
        }

        $this->calls[] = $call;
    }
}
