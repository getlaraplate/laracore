<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Database\Connection;
use Modules\Core\Contracts\ICacheLabelIndex;

/**
 * Labels waiting for the commit of a connection's transaction, collected so the whole transaction costs one flush.
 *
 * Every write registers an `afterCommit` callback, as a deferred flush did before; the first callback that runs after
 * the commit flushes every label collected on that connection, deduplicated, and the others find nothing left. A
 * callback per write keeps Laravel's savepoint semantics: the callbacks of a rolled back savepoint are dropped with
 * it, while any callback that survives flushes the whole set. Labels of a rolled back write stay collected until the
 * next commit on the connection flushes them: an extra flush costs a miss, never a stale read.
 *
 * Bound as a singleton: the set must outlive the invalidator instances that fill it.
 */
final class PendingLabelFlushes
{
    /**
     * @var array<string, array{index: ICacheLabelIndex, labels: array<string, true>}> connection and index => labels
     */
    private array $pending = [];

    /**
     * Collects labels to flush on the index once the open transaction of the connection commits.
     *
     * @param  list<string>  $labels
     */
    public function add(Connection $connection, ICacheLabelIndex $index, array $labels): void
    {
        $key = (string) $connection->getName() . ':' . spl_object_id($index);
        $this->pending[$key] ??= ['index' => $index, 'labels' => []];

        foreach ($labels as $label) {
            $this->pending[$key]['labels'][$label] = true;
        }

        $connection->afterCommit(fn () => $this->flush($key));
    }

    private function flush(string $key): void
    {
        $pending = $this->pending[$key] ?? null;

        if ($pending === null) {
            return;
        }

        // Taken out first: a flush issued while this one runs collects into a new set.
        unset($this->pending[$key]);
        $pending['index']->flush(...array_map(strval(...), array_keys($pending['labels'])));
    }
}
