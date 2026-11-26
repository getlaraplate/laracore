<?php

declare(strict_types=1);

namespace Modules\Core\Support\Hierarchy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use LogicException;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Exceptions\HierarchyCycleException;
use Modules\Core\Exceptions\HierarchyWriteConflictException;

/**
 * Owns every set-based statement on a closure table: `{table}_closure` with `ancestor_id`, `descendant_id` and
 * `depth`, one row per ancestor and descendant pair, the node itself included at depth 0.
 *
 * Every method works on the tables through the query builder, never through the model, so no model scope applies:
 * trashed, inactive and expired rows stay in the closure. The SQL is portable: no recursive query, no driver branch.
 * The few raw select fragments name only closure columns, unqualified: the other side of each join is a derived
 * table whose columns are renamed (`node_key`, `subtree_depth`, ...), so the fragments stay unambiguous and need no
 * table alias, which the connection's table prefix would otherwise rewrite.
 * Deletes never read the closure table in their own subquery (MySQL error 1093): the keys to unlink are selected
 * first, then deleted by key in chunks. Memory stays bounded: a subtree is read by keyset pages, never whole, and
 * the only lists held in full are a node's ancestors (at most its depth) and the doomed nodes that have descendants.
 *
 * Every write runs in a transaction that first takes the hierarchy's lock (see {@see self::lock()}), so the writers
 * of one closure table run one at a time and each reads what the previous one committed. Row locks on the closure
 * rows alone would not do: under PostgreSQL's READ COMMITTED a statement does not see rows another transaction
 * inserted while it waited, and two moves can each pass their cycle check before the other commits.
 */
final readonly class ClosureTableMaintainer
{
    /**
     * Ancestor keys per delete statement. With {@see self::DESCENDANT_CHUNK_SIZE} a statement binds at most 500
     * values, below the 999 variables of older SQLite builds.
     */
    private const int ANCESTOR_CHUNK_SIZE = 100;

    /**
     * Subtree keys read per page and bound per delete statement.
     */
    private const int DESCENDANT_CHUNK_SIZE = 400;

    /**
     * The closure table's columns, in the order every insert selects them.
     */
    private const array COLUMNS = ['ancestor_id', 'descendant_id', 'depth'];

    public function __construct(
        private ConnectionInterface $connection,
        private string $table,
        private string $closureTable,
        private string $keyColumn = 'id',
        private string $parentColumn = 'parent_id',
    ) {}

    /**
     * A maintainer on the model's connection, table, closure table, key and parent columns.
     *
     * @throws LogicException When the model does not use {@see \Modules\Core\Models\Concerns\HasClosureTable}
     */
    public static function forModel(Model $model): self
    {
        if (! method_exists($model, 'getClosureTable') || ! method_exists($model, 'getParentKeyName')) {
            throw new LogicException(sprintf('Model [%s] has no closure table: it must use HasClosureTable.', $model::class));
        }

        return new self(
            $model->getConnection(),
            $model->getTable(),
            $model->getClosureTable(),
            $model->getKeyName(),
            $model->getParentKeyName(),
        );
    }

    /**
     * Insert the closure rows of a new leaf node: its self row and one row per ancestor-or-self of its parent.
     *
     * A parent the closure does not know (a row a bulk insert added without its closure rows) is linked first from
     * the parent column, with its own unlinked ancestors (see {@see self::linkFromParentColumn()}), so the node never
     * lands under a parent that is missing from the closure. That costs nothing when the parent is known: the insert
     * reports how many rows it wrote, and only a lone self row means the parent was missing.
     *
     * @throws LogicException When the parent column of the unlinked ancestors loops or names a missing row
     */
    public function insertNode(int|string $id, int|string|null $parentId): void
    {
        $this->writeLocked(function () use ($id, $parentId): void {
            if ($this->insertLinks($id, $parentId) > 1 || $parentId === null) {
                return;
            }

            $this->linkFromParentColumn($parentId, null);
            $this->closure()->insertUsing(self::COLUMNS, $this->parentLinks($id, $parentId));
        });
    }

    /**
     * Move a node with its whole subtree under a new parent, or to the roots when the new parent is null.
     *
     * The links inside the subtree stay. The links from the node's old ancestors to every node of the subtree go,
     * and the cross product of the new parent's ancestors-and-self with the subtree comes in.
     *
     * A new parent the closure does not know is linked first from the parent column, as {@see self::insertNode()}
     * does; the cycle check runs after that link, so it also sees a parent the closure did not know yet.
     *
     * @throws HierarchyCycleException When the new parent is the node itself or one of its descendants
     * @throws LogicException When the parent column of an unlinked new parent loops or names a missing row
     */
    public function moveSubtree(int|string $id, int|string|null $newParentId): void
    {
        $this->writeLocked(function () use ($id, $newParentId): void {
            $this->moveLockedSubtree($id, $newParentId);
        });
    }

    /**
     * Unlink the subtrees of the nodes about to be deleted from everything above them, for a bulk delete (a query
     * deleting many rows, which fires no model event). A single model delete uses {@see self::detachChildren()}
     * after the row is gone instead, so a delete cancelled by a listener changes nothing; an owner's hard delete, which
     * removes the rows through a cascading foreign key, uses {@see self::childrenLeftBy()} before it and
     * {@see self::rootParentless()} after it, for the same reason.
     *
     * Removes every `(a, x)` where some doomed `d` has `(a, d)` in the closure and `(d, x)` with depth > 0. The rows
     * whose ancestor or descendant is a doomed node itself go with the node, through the cascading foreign keys.
     * Call it before the bulk delete: afterwards the doomed nodes' rows are already gone and the links cannot be
     * found.
     *
     * Only the doomed nodes with descendants are visited, one at a time: their ancestors-and-self are unlinked from
     * their strict descendants. The order does not matter: a link a node finds already gone was removed by another
     * doomed node on the same path, together with every pair that link would have produced.
     *
     * `$doomedKeys` receives a query on the model table. Either it adds the conditions to that query and returns it
     * or nothing, or it returns another query, used instead (an Eloquent one is reduced with `toBase()`). The query
     * may select the key column; otherwise the key column is selected.
     *
     * @param  Closure(Builder): (Builder|EloquentBuilder<Model>|null)  $doomedKeys
     *
     * @throws LogicException When the closure returns something other than a query builder or null
     */
    public function detachSubtreesOf(Closure $doomedKeys): void
    {
        $doomed = $this->doomedQuery($doomedKeys);

        $this->writeLocked(function () use ($doomed): void {
            $doomed_with_descendants = $this->keys(
                $this->closure()->whereIn('ancestor_id', $doomed)->where('depth', '>', 0)->distinct(),
                'ancestor_id',
            );

            foreach ($doomed_with_descendants as $doomed_id) {
                $this->unlinkFromAncestors(
                    $doomed_id,
                    $this->keys($this->closure()->where('descendant_id', $doomed_id), 'ancestor_id'),
                    includeNode: false,
                );
            }
        });
    }

    /**
     * Unlink the subtrees of the given nodes, each node included, from the given ancestors: what the children of a
     * deleted node need once the node is gone. The caller reads both lists before the delete, while the deleted
     * node's rows still link them (its children at depth 1, its strict ancestors), and calls this after it, when
     * the delete is certain; the deleted node's own rows went with the cascading foreign keys.
     *
     * Each subtree is read by keyset pages and deleted against the ancestors in chunks, as a move does.
     *
     * @param  list<int|string>  $childIds
     * @param  list<int|string>  $ancestorIds
     */
    public function detachChildren(array $childIds, array $ancestorIds): void
    {
        if ($childIds === [] || $ancestorIds === []) {
            return;
        }

        $this->writeLocked(function () use ($childIds, $ancestorIds): void {
            foreach ($childIds as $child_id) {
                $this->unlinkFromAncestors($child_id, $ancestorIds, includeNode: true);
            }
        });
    }

    /**
     * The nodes a delete of the doomed nodes leaves without their parent: the children of a doomed node that are
     * not doomed themselves, by key. Only reads, so the caller reads them before a delete that removes the doomed
     * rows by a cascading foreign key (an owner's hard delete, which fires no event on them) and roots them with
     * {@see self::rootParentless()} once the delete is certain.
     *
     * `$doomedKeys` is read as in {@see self::detachSubtreesOf()}.
     *
     * @param  Closure(Builder): (Builder|EloquentBuilder<Model>|null)  $doomedKeys
     *
     * @throws LogicException When the closure returns something other than a query builder or null
     *
     * @return list<int|string>
     */
    public function childrenLeftBy(Closure $doomedKeys): array
    {
        $doomed = $this->doomedQuery($doomedKeys);

        return $this->nodeKeys(
            $this->connection->table($this->table)
                ->whereIn($this->parentColumn, $doomed)
                ->whereNotIn($this->keyColumn, $doomed)
                ->orderBy($this->keyColumn),
        );
    }

    /**
     * Move to the roots, each with its subtree, the listed nodes whose parent column is null now: the children a
     * delete left behind once the parent foreign key cleared their parent (see {@see self::childrenLeftBy()}). Their
     * subtrees are unlinked from the ancestors the closure still gives them, as {@see self::moveSubtree()} does with
     * a null parent. A listed node that has a parent keeps its links: whatever gave it that parent moved it already.
     *
     * The keys are read in chunks of {@see self::DESCENDANT_CHUNK_SIZE}, inside one transaction.
     *
     * @param  list<int|string>  $ids
     */
    public function rootParentless(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->writeLocked(function () use ($ids): void {
            foreach (array_chunk($ids, self::DESCENDANT_CHUNK_SIZE) as $chunk) {
                $parentless = $this->nodeKeys(
                    $this->connection->table($this->table)
                        ->whereIn($this->keyColumn, $chunk)
                        ->whereNull($this->parentColumn)
                        ->orderBy($this->keyColumn),
                );

                foreach ($parentless as $id) {
                    $this->moveLockedSubtree($id, null);
                }
            }
        });
    }

    /**
     * Rebuild the closure from the parent column, set-based.
     *
     * Every node gets its self row, the roots' being the first level, then each depth is inserted from the previous
     * one: the rows at depth k are the rows at depth k - 1 ending at each node's parent, extended to the node. One
     * statement per level of the deepest branch, none per node. The table is emptied with a delete, not a truncate,
     * so the rebuild stays inside the caller's transaction on every driver.
     */
    public function rebuild(): void
    {
        $this->writeLocked(function (): void {
            $this->closure()->delete();

            $this->closure()->insertUsing(self::COLUMNS, $this->selfRows());

            for ($depth = 1; ; $depth++) {
                $inserted = $this->closure()->insertUsing(
                    self::COLUMNS,
                    $this->closure()
                        ->joinSub(
                            $this->connection->table($this->table)
                                ->select([$this->keyColumn . ' as node_key', $this->parentColumn . ' as node_parent']),
                            'node',
                            'node.node_parent',
                            '=',
                            'descendant_id',
                        )
                        ->select(['ancestor_id', 'node.node_key as descendant_id'])
                        ->selectRaw('depth + 1 as depth')
                        ->where('depth', $depth - 1),
                );

                if ($inserted === 0) {
                    return;
                }
            }
        });
    }

    /**
     * Take the hierarchy's lock for the rest of the current transaction: the row of this closure table in
     * `core_hierarchy_locks`, created on the first write, whose version is bumped by every write. A writer of the
     * same hierarchy waits on it until this transaction ends.
     *
     * The row is first read with a locking read, which waits for the current holder and returns the last committed
     * version without taking a snapshot. A plain read then shows the version as the transaction's snapshot sees it.
     * On MySQL and MariaDB under REPEATABLE READ the snapshot is taken at the transaction's first plain read: when
     * that came before the lock and another writer committed in between, the two reads differ and nothing is
     * written ({@see HierarchyWriteConflictException}, retryable). When the lock is the first thing the transaction
     * reads, the snapshot starts after the wait and the reads agree. Drivers that read the latest commit per
     * statement (PostgreSQL, SQL Server and Oracle under READ COMMITTED, SQLite) always see them agree.
     *
     * Each write of this class takes it itself. Call it first in a transaction that reads the hierarchy and writes
     * from what it read, as {@see \Modules\Core\Models\Concerns\HasClosureTable} does before reading what a delete
     * will detach.
     *
     * @throws LogicException When no transaction is open: the lock would end with the statement; or when the lock
     *                        row cannot be read back after it was created
     * @throws HierarchyWriteConflictException When the transaction's snapshot predates the last write
     */
    public function lock(): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException(sprintf('The hierarchy lock of "%s" must be taken inside a transaction.', $this->closureTable));
        }

        $held = $this->lockedVersion();

        if ($held === null) {
            $this->createLockRow();
            $held = $this->lockedVersion() ?? throw new LogicException(sprintf('The hierarchy lock of "%s" could not be created.', $this->closureTable));
        }

        if ($held !== $this->lockVersion()) {
            throw HierarchyWriteConflictException::staleSnapshot($this->closureTable);
        }

        $this->lockRow()->update(['version' => $held + 1]);
    }

    /**
     * Run a write in a transaction that takes the hierarchy's lock first.
     *
     * When this opens the outermost transaction, nothing in it has read yet, so no snapshot can predate the lock:
     * one unconditional bump takes it. Inside a caller's transaction the version check of {@see self::lock()} runs.
     *
     * @param  Closure(): void  $write
     */
    private function writeLocked(Closure $write): void
    {
        $outermost = $this->connection->transactionLevel() === 0;

        $this->connection->transaction(function () use ($outermost, $write): void {
            if ($outermost) {
                $this->bumpLock();
            } else {
                $this->lock();
            }

            $write();
        });
    }

    /**
     * Take the lock with one unconditional bump, creating the row on the first write.
     */
    private function bumpLock(): void
    {
        if ($this->lockRow()->increment('version') === 1) {
            return;
        }

        $this->createLockRow();
        $this->lockRow()->increment('version');
    }

    /**
     * Insert the lock row of this closure table, in a savepoint so a row another writer inserted first fails alone.
     */
    private function createLockRow(): void
    {
        try {
            $this->connection->transaction(function (): void {
                $this->connection->table(CoreTables::HierarchyLocks->value)->insert(['name' => $this->closureTable, 'version' => 0]);
            });
        } catch (QueryException) {
            // Another writer inserted it first: the caller reads it back.
        }
    }

    /**
     * The lock row's last committed version, read with a locking read that waits for the current holder; null
     * when the row does not exist yet. The query has no row limit: Oracle cannot lock a limited query.
     */
    private function lockedVersion(): ?int
    {
        $version = array_first($this->lockRow()->lockForUpdate()->pluck('version')->all());

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * The lock row's version as the transaction's snapshot shows it; null when the row does not exist (yet, or for
     * it).
     */
    private function lockVersion(): ?int
    {
        $version = $this->lockRow()->value('version');

        return is_numeric($version) ? (int) $version : null;
    }

    private function lockRow(): Builder
    {
        return $this->connection->table(CoreTables::HierarchyLocks->value)->where('name', $this->closureTable);
    }

    /**
     * The body of {@see self::moveSubtree()}, for a caller that holds the lock.
     *
     * @throws HierarchyCycleException When the new parent is the node itself or one of its descendants
     */
    private function moveLockedSubtree(int|string $id, int|string|null $newParentId): void
    {
        if ($newParentId !== null) {
            if (! $this->hasSelfRow($newParentId)) {
                $this->linkFromParentColumn($newParentId, $id);
            }

            if ($this->wouldCycle($id, $newParentId)) {
                throw HierarchyCycleException::forMove($this->table, $id, $newParentId);
            }
        }

        $this->unlinkFromAncestors(
            $id,
            $this->keys($this->closure()->where('descendant_id', $id)->where('depth', '>', 0), 'ancestor_id'),
            includeNode: true,
        );

        if ($newParentId === null) {
            return;
        }

        $this->closure()->insertUsing(
            self::COLUMNS,
            $this->closure()
                ->crossJoinSub(
                    $this->closure()
                        ->select(['descendant_id as subtree_key', 'depth as subtree_depth'])
                        ->where('ancestor_id', $id),
                    'subtree',
                )
                ->select(['ancestor_id', 'subtree.subtree_key as descendant_id'])
                ->selectRaw('depth + subtree_depth + 1 as depth')
                ->where('descendant_id', $newParentId),
        );
    }

    /**
     * Give a node the closure does not know its rows, with every unlinked ancestor above it: the chain is read up the
     * parent column to the first node that has a self row (or past a root), then linked top-down, each node under
     * its parent as {@see self::insertNode()} links a leaf. Rows a chain node holds as a descendant without its self
     * row are dropped first, so the inserts never meet a half-written node. One read per unlinked node, none when
     * the closure is whole.
     *
     * Only this chain is repaired: other rows a bulk write left without closure rows stay so until they are written
     * through the model or {@see self::rebuild()} runs.
     *
     * @throws HierarchyCycleException When the chain climbs to the node being moved under it
     * @throws LogicException When the parent column loops or names a row that does not exist
     */
    private function linkFromParentColumn(int|string $nodeId, int|string|null $movingId): void
    {
        /** @var list<array{0: int|string, 1: int|string|null}> $unlinked */
        $unlinked = [];
        $visited = [];
        $current = $nodeId;

        while ($current !== null && ! $this->hasSelfRow($current)) {
            if ($movingId !== null && (string) $current === (string) $movingId) {
                throw HierarchyCycleException::forMove($this->table, $movingId, $nodeId);
            }

            if (isset($visited[(string) $current])) {
                throw new LogicException(sprintf('The parent column of "%s" loops through [%s]; fix it, then rebuild the closure.', $this->table, $current));
            }

            $visited[(string) $current] = true;
            $parent = $this->parentOf($current);
            $unlinked[] = [$current, $parent];
            $current = $parent;
        }

        foreach (array_reverse($unlinked) as [$node, $parent]) {
            $this->closure()->where('descendant_id', $node)->delete();
            $this->insertLinks($node, $parent);
        }
    }

    /**
     * The parent key of a node, read from the model table.
     *
     * @throws LogicException When the node has no row, or its parent value is not a key
     */
    private function parentOf(int|string $id): int|string|null
    {
        $row = $this->connection->table($this->table)->where($this->keyColumn, $id)->first([$this->parentColumn]);

        if (! is_object($row)) {
            throw new LogicException(sprintf('The table "%s" has no row [%s] to link into "%s".', $this->table, $id, $this->closureTable));
        }

        $parent = $row->{$this->parentColumn} ?? null;

        if ($parent === null || is_int($parent) || is_string($parent)) {
            return $parent;
        }

        throw new LogicException(sprintf('The table "%s" holds a non-key %s value.', $this->table, $this->parentColumn));
    }

    /**
     * Insert a node's self row and its links under its parent's ancestors-and-self, returning the rows written.
     */
    private function insertLinks(int|string $id, int|string|null $parentId): int
    {
        $rows = $this->selfRows()->where($this->keyColumn, $id);

        if ($parentId !== null) {
            $rows->unionAll($this->parentLinks($id, $parentId));
        }

        return $this->closure()->insertUsing(self::COLUMNS, $rows);
    }

    /**
     * The rows linking a node to every ancestor-or-self of its parent, one level deeper; none when the parent has no
     * self row, so a parent holding stray rows without its own still counts as missing.
     */
    private function parentLinks(int|string $id, int|string $parentId): Builder
    {
        return $this->closure()
            ->crossJoinSub(
                $this->connection->table($this->table)
                    ->select($this->keyColumn . ' as node_key')
                    ->where($this->keyColumn, $id),
                'node',
            )
            ->select(['ancestor_id', 'node.node_key as descendant_id'])
            ->selectRaw('depth + 1 as depth')
            ->where('descendant_id', $parentId)
            ->whereExists($this->closure()->select('depth')->where('ancestor_id', $parentId)->where('descendant_id', $parentId));
    }

    private function wouldCycle(int|string $id, int|string $newParentId): bool
    {
        if ((string) $id === (string) $newParentId) {
            return true;
        }

        return $this->closure()
            ->where('ancestor_id', $id)
            ->where('descendant_id', $newParentId)
            ->exists();
    }

    /**
     * The values of one key column of the closure.
     *
     * @throws LogicException When a value is not a key
     *
     * @return list<int|string>
     */
    private function keys(Builder $query, string $column): array
    {
        $keys = [];

        foreach ($query->pluck($column) as $key) {
            if (! is_int($key) && ! is_string($key)) {
                throw new LogicException(sprintf('The closure table "%s" holds a non-key %s value.', $this->closureTable, $column));
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * The key column's values of a query on the model table.
     *
     * @throws LogicException When a value is not a key
     *
     * @return list<int|string>
     */
    private function nodeKeys(Builder $query): array
    {
        $keys = [];

        foreach ($query->pluck($this->keyColumn) as $key) {
            if (! is_int($key) && ! is_string($key)) {
                throw new LogicException(sprintf('The table "%s" holds a non-key %s value.', $this->table, $this->keyColumn));
            }

            $keys[] = $key;
        }

        return $keys;
    }

    private function hasSelfRow(int|string $id): bool
    {
        return $this->closure()
            ->where('ancestor_id', $id)
            ->where('descendant_id', $id)
            ->exists();
    }

    /**
     * The query selecting the doomed keys, from what the caller's closure did or returned.
     *
     * @param  Closure(Builder): mixed  $doomedKeys
     */
    private function doomedQuery(Closure $doomedKeys): Builder
    {
        $query = $this->connection->table($this->table);
        $returned = $doomedKeys($query);

        $doomed = match (true) {
            $returned === null => $query,
            $returned instanceof Builder => $returned,
            $returned instanceof EloquentBuilder => $returned->toBase(),
            default => throw new LogicException(sprintf(
                'The doomed keys closure for "%s" must return a query builder or nothing, %s returned.',
                $this->table,
                get_debug_type($returned),
            )),
        };

        if ($doomed->columns === null) {
            $doomed->select($this->keyColumn);
        }

        return $doomed;
    }

    /**
     * Delete the links from the given ancestors to the subtree of a node, the node itself included or not.
     *
     * The subtree is read by keyset pages of {@see self::DESCENDANT_CHUNK_SIZE} keys, so deleting rows of pages
     * already read cannot shift the next one; each page is deleted against the ancestors in chunks of
     * {@see self::ANCESTOR_CHUNK_SIZE}.
     *
     * @param  list<int|string>  $ancestorIds
     */
    private function unlinkFromAncestors(int|string $nodeId, array $ancestorIds, bool $includeNode): void
    {
        if ($ancestorIds === []) {
            return;
        }

        $subtree = $this->closure()->select('descendant_id')->where('ancestor_id', $nodeId);

        if (! $includeNode) {
            $subtree->where('depth', '>', 0);
        }

        $subtree->chunkById(self::DESCENDANT_CHUNK_SIZE, function (Collection $rows) use ($ancestorIds): void {
            $descendant_ids = $rows->pluck('descendant_id')->all();

            foreach (array_chunk($ancestorIds, self::ANCESTOR_CHUNK_SIZE) as $ancestor_chunk) {
                $this->closure()
                    ->whereIn('ancestor_id', $ancestor_chunk)
                    ->whereIn('descendant_id', $descendant_ids)
                    ->delete();
            }
        }, 'descendant_id');
    }

    private function closure(): Builder
    {
        return $this->connection->table($this->closureTable);
    }

    /**
     * The self row, depth 0, of every node of the model table.
     */
    private function selfRows(): Builder
    {
        return $this->connection->table($this->table)
            ->select([$this->keyColumn . ' as ancestor_id', $this->keyColumn . ' as descendant_id'])
            ->selectRaw('0 as depth');
    }
}
