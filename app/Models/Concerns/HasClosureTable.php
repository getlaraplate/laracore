<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;
use Modules\Core\Exceptions\HierarchyCycleException;
use Modules\Core\Models\Relations\ClosureBloodline;
use Modules\Core\Models\Relations\ClosureLineage;
use Modules\Core\Models\Relations\ClosureRootAncestor;
use Modules\Core\Models\Relations\Siblings;
use Modules\Core\SoftDeletes\RowRemoval;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;

/**
 * A hierarchy on a closure table, `{table}_closure` (see {@see \Modules\Core\Helpers\MigrateUtils::closureTable()}),
 * kept in step with the parent column through model events.
 *
 * Relations: `parent`, `children`, `ancestors`, `ancestorsAndSelf`, `descendants`, `descendantsAndSelf`, `bloodline`,
 * `siblings`, `siblingsAndSelf`, `rootAncestor`, `parentAndSelf`, `childrenAndSelf`, all eager-loadable. The nodes
 * the closure relations load come by distance, nearest first, before any order a global scope adds, then by key,
 * and carry a signed `depth`: negative above the node, `0` for the node, positive below. Their closure rows are
 * written only here: the relations refuse pivot writes.
 *
 * Maintenance, each write in a transaction on the model connection:
 * - created: the node's closure rows;
 * - updating with a new parent: refused with {@see HierarchyCycleException} when the parent is the node or one of
 *   its descendants, before anything is saved;
 * - updated with a new parent: the whole subtree moves;
 * - deleting, when the row really goes: the node's children and strict ancestors are read, nothing is written, so
 *   a listener that cancels the delete leaves the closure as it was;
 * - deleted, once the row is gone: the subtree of each child still orphaned by the delete is unlinked from those
 *   ancestors (a child a `deleting` listener re-parented was moved by its own save). The node's own rows go
 *   with the cascading foreign keys, and the children's parent column with the parent foreign key.
 * Soft deletes and restores leave the closure as it is: a trashed node hides only itself.
 *
 * A plain `save()` or `delete()` opens no transaction around the model write, so the write and the closure step
 * are atomic only inside the caller's own transaction (`saveOrFail()`, `DB::transaction()`). Writes that fail
 * between the two, and writes that bypass model events, leave the closure behind; {@see self::rebuildClosure()}
 * repairs it.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasClosureTable
{
    /**
     * What a delete under way read for the closure in `deleting`, written in `deleted`: the node's children and
     * strict ancestors. Null when no delete of the row is under way.
     *
     * @var array{children: list<int|string>, ancestors: list<int|string>}|null
     */
    private ?array $closureDetachment = null;

    /**
     * Rebuild the closure table from the parent column.
     */
    public static function rebuildClosure(): void
    {
        ClosureTableMaintainer::forModel(static::query()->getModel())->rebuild();
    }

    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->getParentKeyName(), $this->getKeyName(), 'parent');
    }

    /**
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->getParentKeyName(), $this->getKeyName());
    }

    /**
     * The ancestors, closest first.
     *
     * @return BelongsToMany<static, $this>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->closureLineage(towardsAncestors: true, andSelf: false, maxDistance: null, relationName: __FUNCTION__);
    }

    /**
     * The node, then its ancestors, closest first.
     *
     * @return BelongsToMany<static, $this>
     */
    public function ancestorsAndSelf(): BelongsToMany
    {
        return $this->closureLineage(towardsAncestors: true, andSelf: true, maxDistance: null, relationName: __FUNCTION__);
    }

    /**
     * The descendants, nearest first.
     *
     * @return BelongsToMany<static, $this>
     */
    public function descendants(): BelongsToMany
    {
        return $this->closureLineage(towardsAncestors: false, andSelf: false, maxDistance: null, relationName: __FUNCTION__);
    }

    /**
     * The node, then its descendants, nearest first.
     *
     * @return BelongsToMany<static, $this>
     */
    public function descendantsAndSelf(): BelongsToMany
    {
        return $this->closureLineage(towardsAncestors: false, andSelf: true, maxDistance: null, relationName: __FUNCTION__);
    }

    /**
     * The node, then its parent.
     *
     * @return BelongsToMany<static, $this>
     */
    public function parentAndSelf(): BelongsToMany
    {
        return $this->closureLineage(towardsAncestors: true, andSelf: true, maxDistance: 1, relationName: __FUNCTION__);
    }

    /**
     * The node, then its children.
     *
     * @return BelongsToMany<static, $this>
     */
    public function childrenAndSelf(): BelongsToMany
    {
        return $this->closureLineage(towardsAncestors: false, andSelf: true, maxDistance: 1, relationName: __FUNCTION__);
    }

    /**
     * The node and its descendants, nearest first, then its ancestors, closest first.
     *
     * @return BelongsToMany<static, $this>
     */
    public function bloodline(): BelongsToMany
    {
        return new ClosureBloodline($this->newRelatedInstance(static::class)->newQuery(), $this, $this->getClosureTable(), __FUNCTION__);
    }

    /**
     * The root of the node's tree; null for a root.
     *
     * @return ClosureRootAncestor<static, $this>
     */
    public function rootAncestor(): ClosureRootAncestor
    {
        return new ClosureRootAncestor(
            $this->closureLineage(towardsAncestors: true, andSelf: false, maxDistance: null, relationName: __FUNCTION__),
        );
    }

    /**
     * The other nodes under the same parent; the other roots for a root.
     *
     * @return HasMany<static, $this>
     */
    public function siblings(): HasMany
    {
        return new Siblings($this->newRelatedInstance(static::class)->newQuery(), $this, $this->getParentKeyName(), andSelf: false);
    }

    /**
     * The nodes under the same parent, the node included; the roots for a root.
     *
     * @return HasMany<static, $this>
     */
    public function siblingsAndSelf(): HasMany
    {
        return new Siblings($this->newRelatedInstance(static::class)->newQuery(), $this, $this->getParentKeyName(), andSelf: true);
    }

    /**
     * The node's distance from its root: 0 for a root.
     */
    public function getDepth(): int
    {
        $depth = $this->closureQuery()->where('descendant_id', $this->getKey())->max('depth');

        return is_numeric($depth) ? (int) $depth : 0;
    }

    public function isRoot(): bool
    {
        return $this->getAttribute($this->getParentKeyName()) === null;
    }

    public function isLeaf(): bool
    {
        return $this->children()->doesntExist();
    }

    public function isDescendantOf(self $ancestor): bool
    {
        return $this->closureQuery()
            ->where('ancestor_id', $ancestor->getKey())
            ->where('descendant_id', $this->getKey())
            ->where('depth', '>', 0)
            ->exists();
    }

    public function isAncestorOf(self $descendant): bool
    {
        return $descendant->isDescendantOf($this);
    }

    public function isSiblingOf(self $sibling): bool
    {
        return $this->getAttribute($this->getParentKeyName()) === $sibling->getAttribute($this->getParentKeyName())
            && $this->getKey() !== $sibling->getKey();
    }

    /**
     * Move the node, with its subtree, under a new parent. False, with nothing changed, when the new parent is the
     * node itself or one of its descendants, or when a listener cancels the save.
     *
     * @throws LogicException When the new parent lives on another connection
     */
    public function moveTo(self $newParent): bool
    {
        throw_if(
            $this->getConnection()->getName() !== $newParent->getConnection()->getName(),
            LogicException::class,
            'Cannot move tree nodes across multiple database connections.',
        );

        if ($newParent->is($this) || $newParent->isDescendantOf($this)) {
            return false;
        }

        return $this->getConnection()->transaction(function () use ($newParent): bool {
            $this->setAttribute($this->getParentKeyName(), $newParent->getKey());

            return $this->save();
        });
    }

    public function getClosureTable(): string
    {
        return $this->getTable() . '_closure';
    }

    public function getParentKeyName(): string
    {
        return 'parent_id';
    }

    protected static function bootHasClosureTable(): void
    {
        static::created(static function (self $node): void {
            $node->inClosureTransaction(static function () use ($node): void {
                ClosureTableMaintainer::forModel($node)->insertNode($node->closureKey(), $node->closureParentKey());
            });
        });

        static::updating(static function (self $node): void {
            if (! $node->isDirty($node->getParentKeyName())) {
                return;
            }

            $node->inClosureTransaction(static function () use ($node): void {
                $node->refuseCycle();
            });
        });

        static::updated(static function (self $node): void {
            if (! $node->wasChanged($node->getParentKeyName())) {
                return;
            }

            $node->inClosureTransaction(static function () use ($node): void {
                ClosureTableMaintainer::forModel($node)->moveSubtree($node->closureKey(), $node->closureParentKey());
            });
        });

        static::deleting(static function (self $node): void {
            $node->closureDetachment = $node->deleteRemovesRow() ? $node->readClosureDetachment() : null;
        });

        static::deleted(static function (self $node): void {
            $detachment = $node->closureDetachment;
            $node->closureDetachment = null;

            if ($detachment === null || $node->exists) {
                return;
            }

            $node->inClosureTransaction(static function () use ($node, $detachment): void {
                ClosureTableMaintainer::forModel($node)->detachChildren(
                    $node->childrenLeftByDelete($detachment['children']),
                    $detachment['ancestors'],
                );
            });
        });
    }

    /**
     * The children read before the delete that the delete left behind: their parent column is null (the parent
     * foreign key cleared it) or still the deleted key. A child a `deleting` listener re-parented with a save was
     * moved, closure included, by that save, and keeps the links it has now.
     *
     * @param  list<int|string>  $childIds
     * @return list<int|string>
     */
    protected function childrenLeftByDelete(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }

        $parent_column = $this->getParentKeyName();

        return self::closureKeys(
            $this->getConnection()->table($this->getTable())
                ->whereIn($this->getKeyName(), $childIds)
                ->where(function (QueryBuilder $orphaned) use ($parent_column): void {
                    $orphaned->whereNull($parent_column)->orWhere($parent_column, $this->closureKey());
                })
                ->pluck($this->getKeyName())
                ->all(),
        );
    }

    /**
     * Whether the delete about to run removes the row, see {@see RowRemoval::inDelete()}.
     */
    protected function deleteRemovesRow(): bool
    {
        return RowRemoval::inDelete($this);
    }

    /**
     * The node's children and strict ancestors, read from its closure rows while they still exist.
     *
     * @return array{children: list<int|string>, ancestors: list<int|string>}
     */
    protected function readClosureDetachment(): array
    {
        $node = $this->closureKey();

        return [
            'children' => self::closureKeys($this->closureQuery()->where('ancestor_id', $node)->where('depth', 1)->pluck('descendant_id')->all()),
            'ancestors' => self::closureKeys($this->closureQuery()->where('descendant_id', $node)->where('depth', '>', 0)->pluck('ancestor_id')->all()),
        ];
    }

    /**
     * @throws HierarchyCycleException When the new parent is the node itself or one of its descendants
     */
    protected function refuseCycle(): void
    {
        $node = $this->closureKey();
        $new_parent = $this->closureParentKey();

        if ($new_parent === null) {
            return;
        }

        $cycles = (string) $node === (string) $new_parent
            || $this->closureQuery()->where('ancestor_id', $node)->where('descendant_id', $new_parent)->exists();

        if ($cycles) {
            throw HierarchyCycleException::forMove($this->getTable(), $node, $new_parent);
        }
    }

    /**
     * @param  Closure(): void  $callback
     */
    protected function inClosureTransaction(Closure $callback): void
    {
        $this->getConnection()->transaction($callback);
    }

    /**
     * The keys among values read from the closure table.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<int|string>
     */
    private static function closureKeys(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => is_int($value) || is_string($value)));
    }

    /**
     * @return ClosureLineage<static, $this>
     */
    private function closureLineage(bool $towardsAncestors, bool $andSelf, ?int $maxDistance, string $relationName): ClosureLineage
    {
        return new ClosureLineage(
            $this->newRelatedInstance(static::class)->newQuery(),
            $this,
            $this->getClosureTable(),
            $towardsAncestors,
            $andSelf,
            $maxDistance,
            $relationName,
        );
    }

    private function closureQuery(): QueryBuilder
    {
        return $this->getConnection()->table($this->getClosureTable());
    }

    /**
     * @throws LogicException When the node has no key yet
     */
    private function closureKey(): int|string
    {
        $key = $this->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException(sprintf('A node of "%s" has no key to place in its closure table.', $this->getTable()));
        }

        return $key;
    }

    /**
     * @throws LogicException When the parent column holds something other than a key
     */
    private function closureParentKey(): int|string|null
    {
        $parent = $this->getAttribute($this->getParentKeyName());

        if ($parent !== null && ! is_int($parent) && ! is_string($parent)) {
            throw new LogicException(sprintf('A node of "%s" has a parent value that is not a key.', $this->getTable()));
        }

        return $parent;
    }
}
