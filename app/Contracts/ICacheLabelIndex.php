<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLookup;

/**
 * Cache entries grouped under labels, so a whole group can be invalidated at once.
 */
interface ICacheLabelIndex
{
    public function lookup(string $key, CacheDependencies $dependencies): CacheLookup;

    /**
     * Store a value found missing by {@see lookup()}. Returns false, storing nothing usable,
     * when one of its labels was flushed after the lookup started.
     *
     * @param  list<string>  $row_labels  Labels found after the query: row labels, and, on an index with row labels,
     *                                    any other label the query revealed (the tables of `morphTo` targets). The
     *                                    flush of any of them after the lookup refuses the store. An index without
     *                                    row labels ignores them.
     */
    public function store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool;

    public function forget(CacheLookup $lookup): void;

    public function flush(string ...$labels): void;

    /**
     * Drop expired entries and memberships. Returns how many entries were removed.
     */
    public function pruneStale(): int;

    public function supportsRowLabels(): bool;
}
