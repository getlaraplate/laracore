<?php

declare(strict_types=1);

namespace Modules\Core\Support\Hierarchy;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
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
 * Deletes never read the closure table in their own subquery (MySQL error 1093): the keys to unlink are selected
 * first, then deleted by key in chunks. Memory stays bounded: a subtree is read by keyset pages, never whole, and
 * the only lists held in full are a node's ancestors (at most its depth) and the doomed nodes that have descendants.
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
     * @throws LogicException When the new parent has no self row in the closure (the closure needs a rebuild)
     */
    public function moveSubtree(int|string $id, int|string|null $newParentId): void
    {
        $this->connection->transaction(function () use ($id, $newParentId): void {
            if ($newParentId !== null) {
                if ($this->wouldCycle($id, $newParentId)) {
                    throw HierarchyCycleException::forMove($this->table, $id, $newParentId);
                }

                if (! $this->hasSelfRow($newParentId)) {
                    throw new LogicException(sprintf(
                        'Cannot move node [%s] of "%s" under [%s]: the new parent has no self row in "%s"; rebuild the closure first.',
                        $id,
                        $this->table,
                        $newParentId,
                        $this->closureTable,
                    ));
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
        });
    }

    /**
     * Unlink the subtrees of the nodes about to be deleted from everything above them, for a bulk delete (a query
     * deleting many rows, which fires no model event). A single model delete uses {@see self::detachChildren()}
     * after the row is gone instead, so a delete cancelled by a listener changes nothing.
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

        $this->connection->transaction(function () use ($doomed): void {
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

        $this->connection->transaction(function () use ($childIds, $ancestorIds): void {
            foreach ($childIds as $child_id) {
                $this->unlinkFromAncestors($child_id, $ancestorIds, includeNode: true);
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
