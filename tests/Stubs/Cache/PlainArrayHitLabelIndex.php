<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Modules\Core\Cache\Labels\ArrayLabelIndex;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLookup;
use Modules\Core\Contracts\ICacheLabelIndex;
use Override;

/**
 * Index that answers the first lookup of each key with the given prefix with a plain array, as a persistent driver
 * that rehydrates an Eloquent collection as an array does, and records what is stored. Other keys go to an in-memory
 * index.
 */
final class PlainArrayHitLabelIndex implements ICacheLabelIndex
{
    /**
     * @var list<mixed> The values stored under a key with the prefix.
     */
    public array $stored_values = [];

    /**
     * @var array<string, true>
     */
    private array $answered = [];

    public function __construct(private readonly string $key_prefix, private readonly ICacheLabelIndex $inner = new ArrayLabelIndex()) {}

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        if (! str_starts_with($key, $this->key_prefix) || isset($this->answered[$key])) {
            return $this->inner->lookup($key, $dependencies);
        }

        $this->answered[$key] = true;

        return new CacheLookup($key, true, ['not' => 'a-collection'], 1, $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        if (str_starts_with($lookup->key, $this->key_prefix)) {
            $this->stored_values[] = $value;
        }

        return $this->inner->store($lookup, $value, $ttl, $row_labels);
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        $this->inner->forget($lookup);
    }

    #[Override]
    public function flush(string ...$labels): void
    {
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
