<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Modules\Core\Contracts\ICacheLabelIndex;
use WeakMap;

/**
 * Labels waiting for the end of a connection's transaction, collected so the whole transaction costs one flush.
 *
 * Every write registers an `afterCommit` callback, as a deferred flush did before; the first callback that runs after
 * the commit flushes every label collected on that connection, deduplicated, and the others find nothing left. A
 * callback per write keeps Laravel's savepoint semantics: the callbacks of a rolled back savepoint are dropped with
 * it, while any callback that survives flushes the whole set.
 *
 * A rollback flushes the set too, once the connection has no transaction left: a read inside the transaction may have
 * cached a value built from rows the rollback removed. A rolled back savepoint flushes nothing yet: its labels stay
 * collected, and the callbacks are registered again on the transaction still open, which flushes them when it ends.
 *
 * Bound as a scoped singleton: the set must outlive the invalidator instances that fill it, and a pending callback
 * keeps the instance it was registered on. Labels left by a write whose callbacks were all dropped are forgotten
 * with the scope (the request or job).
 */
final class PendingLabelFlushes
{
    /**
     * @var array<string, array{index: ICacheLabelIndex, labels: array<string, true>, watched: WeakMap<DatabaseTransactionRecord, true>}> connection and index => labels
     */
    private array $pending = [];

    /**
     * Collects labels to flush on the index once the open transaction of the connection ends.
     *
     * @param  list<string>  $labels
     */
    public function add(Connection $connection, ICacheLabelIndex $index, array $labels): void
    {
        $key = (string) $connection->getName() . ':' . spl_object_id($index);
        $this->pending[$key] ??= ['index' => $index, 'labels' => [], 'watched' => new WeakMap()];

        foreach ($labels as $label) {
            $this->pending[$key]['labels'][$label] = true;
        }

        $this->watch($connection, $key);
    }

    /**
     * Whether the connection still has a transaction whose end its callbacks wait for. In a test wrapped in
     * `RefreshDatabase` the wrapping transaction is not one: the manager runs callbacks at once outside the others.
     */
    private static function inTransaction(Connection $connection): bool
    {
        if ($connection->transactionLevel() === 0) {
            return false;
        }

        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;

        if (! $manager instanceof DatabaseTransactionsManager) {
            return true;
        }

        $name = $connection->getName();

        return $manager->callbackApplicableTransactions()->contains(
            static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name,
        );
    }

    /**
     * Registers the commit callback of a write, and the rollback callback once per transaction record.
     */
    private function watch(Connection $connection, string $key): void
    {
        $connection->afterCommit(fn () => $this->flush($key));

        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;
        $record = $manager instanceof DatabaseTransactionsManager ? $manager->callbackApplicableTransactions()->last() : null;

        if ($record instanceof DatabaseTransactionRecord && isset($this->pending[$key])) {
            if (isset($this->pending[$key]['watched'][$record])) {
                return;
            }

            $this->pending[$key]['watched'][$record] = true;
        }

        $connection->afterRollBack(fn () => $this->rolledBack($connection, $key));
    }

    private function rolledBack(Connection $connection, string $key): void
    {
        if (! isset($this->pending[$key])) {
            return;
        }

        // A savepoint was rolled back: the callbacks it held are gone, the transaction around it flushes the set.
        if (self::inTransaction($connection)) {
            $this->watch($connection, $key);

            return;
        }

        $this->flush($key);
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
