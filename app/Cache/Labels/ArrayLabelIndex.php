<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Modules\Core\Contracts\ICacheLabelIndex;
use Override;

/**
 * Process-local index keeping values, label memberships and flush times in arrays.
 */
final class ArrayLabelIndex implements ICacheLabelIndex
{
    /**
     * @var array<string, array{value: mixed, expires_at: int}>
     */
    private array $values = [];

    /**
     * @var array<string, array<string, int>> label => [key => expires_at]
     */
    private array $memberships = [];

    /**
     * @var array<string, int> label => microseconds of the last flush
     */
    private array $flushed_at = [];

    #[Override]
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup
    {
        $started_at = Clock::micros();
        $entry = $this->values[$key] ?? null;

        if ($entry !== null && $entry['expires_at'] > $started_at) {
            return new CacheLookup($key, true, $entry['value'], $started_at, $dependencies);
        }

        return new CacheLookup($key, false, null, $started_at, $dependencies);
    }

    #[Override]
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool
    {
        $labels = [...$lookup->dependencies->labels, ...$row_labels];

        foreach ($labels as $label) {
            if (($this->flushed_at[$label] ?? -1) >= $lookup->started_at) {
                return false;
            }
        }

        $expires_at = Clock::micros() + $ttl * 1_000_000;
        $this->values[$lookup->key] = ['value' => $value, 'expires_at' => $expires_at];

        foreach ($labels as $label) {
            $this->memberships[$label][$lookup->key] = $expires_at;
        }

        return true;
    }

    #[Override]
    public function forget(CacheLookup $lookup): void
    {
        unset($this->values[$lookup->key]);
    }

    #[Override]
    public function flush(string ...$labels): void
    {
        $now = Clock::micros();

        foreach ($labels as $label) {
            $this->flushed_at[$label] = $now;

            foreach (array_keys($this->memberships[$label] ?? []) as $key) {
                unset($this->values[$key]);
            }

            unset($this->memberships[$label]);
        }
    }

    #[Override]
    public function pruneStale(): int
    {
        $now = Clock::micros();
        $removed = 0;

        foreach ($this->values as $key => $entry) {
            if ($entry['expires_at'] <= $now) {
                unset($this->values[$key]);
                $removed++;
            }
        }

        foreach ($this->memberships as $label => $members) {
            foreach ($members as $key => $expires_at) {
                if ($expires_at <= $now) {
                    unset($this->memberships[$label][$key]);
                }
            }

            if ($this->memberships[$label] === []) {
                unset($this->memberships[$label]);
            }
        }

        return $removed;
    }

    #[Override]
    public function supportsRowLabels(): bool
    {
        return true;
    }
}
