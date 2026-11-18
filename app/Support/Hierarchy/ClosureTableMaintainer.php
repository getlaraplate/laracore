<?php

declare(strict_types=1);

namespace Modules\Core\Support\Hierarchy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use LogicException;
use Modules\Core\Exceptions\HierarchyCycleException;

/**
 * Owns every set-based statement on a closure table: `{table}_closure` with `ancestor_id`, `descendant_id` and
 * `depth`, one row per ancestor and descendant pair, the node itself included at depth 0.
 *
 * Every method works on the tables through the query builder, never through the model, so no model scope applies:
 * trashed, inactive and expired rows stay in the closure. The SQL is portable: no recursive query, no driver branch.
 * The few raw select fragments name only closure columns, unqualified: the other side of each join is a derived
 * table whose columns are renamed (`node_key`, `subtree_depth`, ...), so the fragments stay unambiguous and need no
 * table alias, which the connection's table prefix would otherwise rewrite.
 * Deletes never read the closure table in their own subquery (MySQL error 1093): the pairs to remove are selected
 * first, then deleted by key in chunks.
 */
final readonly class ClosureTableMaintainer
{
    /**
     * Maximum bindings of one delete statement, below the 999 variables of older SQLite builds.
     */
    private const int DELETE_CHUNK_SIZE = 500;

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
     */
    public function insertNode(int|string $id, int|string|null $parentId): void
    {
        $rows = $this->selfRows()->where($this->keyColumn, $id);

        if ($parentId !== null) {
            $rows->unionAll(
                $this->closure()
                    ->crossJoinSub(
                        $this->connection->table($this->table)
                            ->select($this->keyColumn . ' as node_key')
                            ->where($this->keyColumn, $id),
                        'node',
                    )
                    ->select(['ancestor_id', 'node.node_key as descendant_id'])
                    ->selectRaw('depth + 1 as depth')
                    ->where('descendant_id', $parentId),
            );
        }

        $this->closure()->insertUsing(self::COLUMNS, $rows);
    }

    /**
     * Move a node with its whole subtree under a new parent, or to the roots when the new parent is null.
     *
     * The links inside the subtree stay. The links from the node's old ancestors to every node of the subtree go,
     * and the cross product of the new parent's ancestors-and-self with the subtree comes in.
     *
     * @throws HierarchyCycleException When the new parent is the node itself or one of its descendants
     */
    public function moveSubtree(int|string $id, int|string|null $newParentId): void
    {
        $this->connection->transaction(function () use ($id, $newParentId): void {
            if ($newParentId !== null && $this->wouldCycle($id, $newParentId)) {
                throw HierarchyCycleException::forMove($this->table, $id, $newParentId);
            }

            $this->deletePairs(
                $this->connection->table($this->closureTable . ' as old_ancestor')
                    ->crossJoin($this->closureTable . ' as subtree')
                    ->select(['old_ancestor.ancestor_id', 'subtree.descendant_id'])
                    ->where('old_ancestor.descendant_id', $id)
                    ->where('old_ancestor.depth', '>', 0)
                    ->where('subtree.ancestor_id', $id),
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
        });
    }

    /**
     * Unlink the subtrees of the nodes about to be deleted from everything above them.
     *
     * Removes every `(a, x)` where some doomed `d` has `(a, d)` in the closure and `(d, x)` with depth > 0. The rows
     * whose ancestor or descendant is a doomed node itself go with the node, through the cascading foreign keys.
     * Call it before the delete: afterwards the doomed nodes' rows are already gone and the links cannot be found.
     *
     * @param  Closure(Builder): mixed  $doomedKeys  Receives a query on the model table and adds the conditions
     *                                               selecting the doomed nodes; it may select the key column itself,
     *                                               otherwise the key column is selected
     */
    public function detachSubtreesOf(Closure $doomedKeys): void
    {
        $doomed = $this->connection->table($this->table);
        $doomedKeys($doomed);

        if ($doomed->columns === null) {
            $doomed->select($this->keyColumn);
        }

        $this->connection->transaction(function () use ($doomed): void {
            $this->deletePairs(
                $this->connection->table($this->closureTable . ' as above')
                    ->join($this->closureTable . ' as below', 'below.ancestor_id', '=', 'above.descendant_id')
                    ->select(['above.ancestor_id', 'below.descendant_id'])
                    ->whereIn('above.descendant_id', $doomed)
                    ->where('below.depth', '>', 0),
            );
        });
    }

    /**
     * Rebuild the closure from the parent column, set-based.
     *
     * Every node gets its self row, the roots' being the first level, then each depth is inserted from the previous
     * one: the rows at depth k are the rows at depth k - 1 ending at each node's parent, extended to the node. One statement per level of the deepest
     * branch, none per node. The table is emptied with a delete, not a truncate, so the rebuild stays inside the
     * caller's transaction on every driver.
     */
    public function rebuild(): void
    {
        $this->connection->transaction(function (): void {
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
     * Select the `(ancestor_id, descendant_id)` pairs first, then delete them by key, grouped by ancestor and packed
     * into statements of at most {@see self::DELETE_CHUNK_SIZE} bindings.
     */
    private function deletePairs(Builder $pairs): void
    {
        /** @var array<int|string, list<int|string>> $descendants_by_ancestor */
        $descendants_by_ancestor = [];

        foreach ($pairs->distinct()->get() as $pair) {
            $descendants_by_ancestor[$pair->ancestor_id][] = $pair->descendant_id;
        }

        /** @var list<array{0: int|string, 1: list<int|string>}> $statement */
        $statement = [];
        $bindings = 0;

        foreach ($descendants_by_ancestor as $ancestor_id => $descendant_ids) {
            foreach (array_chunk($descendant_ids, self::DELETE_CHUNK_SIZE - 1) as $chunk) {
                if ($statement !== [] && $bindings + count($chunk) + 1 > self::DELETE_CHUNK_SIZE) {
                    $this->deleteLinks($statement);
                    $statement = [];
                    $bindings = 0;
                }

                $statement[] = [$ancestor_id, $chunk];
                $bindings += count($chunk) + 1;
            }
        }

        if ($statement !== []) {
            $this->deleteLinks($statement);
        }
    }

    /**
     * @param  list<array{0: int|string, 1: list<int|string>}>  $links  Each ancestor with the descendants to unlink from it
     */
    private function deleteLinks(array $links): void
    {
        $this->closure()
            ->where(static function (Builder $query) use ($links): void {
                foreach ($links as [$ancestor_id, $descendant_ids]) {
                    $query->orWhere(static fn (Builder $link): Builder => $link
                        ->where('ancestor_id', $ancestor_id)
                        ->whereIn('descendant_id', $descendant_ids));
                }
            })
            ->delete();
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
