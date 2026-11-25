<?php

declare(strict_types=1);

namespace Modules\Core\Models\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Override;

/**
 * The bloodline of a node through its closure table: the node, its descendants and its ancestors, in one query.
 *
 * A closure row joins a node by either end: the node is the descendant when the row starts from one of the
 * starting nodes as ancestor, and the ancestor when it starts from one as descendant. The self row matches the
 * first branch only, so the node comes once.
 *
 * Order: the node and its descendants by distance, nearest first, then its ancestors, closest first. Both come
 * before any order a global scope of the model adds.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends ClosureRelation<TRelatedModel, TDeclaringModel>
 */
final class ClosureBloodline extends ClosureRelation
{
    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(Builder $query, Model $parent, string $closureTable, string $relationName)
    {
        parent::__construct($query, $parent, $closureTable, 'ancestor_id', 'descendant_id', $relationName);

        $closure_table = $this->table;
        $row = $closure_table . '_row';
        $related_key = $this->getQualifiedRelatedKeyName();

        // 1 on the rows that reach an ancestor, 0 on the node's own row and on its descendants' rows: the same
        // closure row read again, with the node on its ancestor side and above the starting node.
        $this->query
            ->orderBy(static function (QueryBuilder $above) use ($closure_table, $row, $related_key): void {
                $above->selectRaw('count(*)')
                    ->from($closure_table . ' as ' . $row)
                    ->whereColumn($row . '.ancestor_id', '=', $closure_table . '.ancestor_id')
                    ->whereColumn($row . '.descendant_id', '=', $closure_table . '.descendant_id')
                    ->whereColumn($row . '.ancestor_id', '=', $related_key)
                    ->where($row . '.depth', '>', 0);
            })
            ->orderBy($closure_table . '.depth');
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    #[Override]
    public function addEagerConstraints(array $models): void
    {
        $this->constrainToStartingNodes($this->getKeys($models, $this->parentKey));
    }

    /**
     * The nodes of the bloodline of each row of the parent query, for `has()`, `whereHas()` and `withCount()`.
     * The model table is aliased when the parent query reads it too.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<int, string>|string|Expression  $columns  `*`, or the count expression of `has()` with an operator and of `withCount()`
     * @return Builder<TRelatedModel>
     */
    #[Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from === $query->getQuery()->from) {
            $hash = $this->getRelationCountHash();
            $query->from($query->getModel()->getTable() . ' as ' . $hash);
            $this->related->setTable($hash);
        }

        $this->performJoin($query);

        $starting_node = $this->getQualifiedParentKeyName();
        $related_key = $this->getQualifiedRelatedKeyName();
        $ancestor = $this->qualifyPivotColumn('ancestor_id');
        $descendant = $this->qualifyPivotColumn('descendant_id');
        $depth = $this->qualifyPivotColumn('depth');

        return $query->select($columns)->where(static function (Builder $rows) use ($starting_node, $related_key, $ancestor, $descendant, $depth): void {
            $rows
                ->where(static function (Builder $below) use ($starting_node, $related_key, $ancestor, $descendant): void {
                    $below->whereColumn($ancestor, '=', $starting_node)->whereColumn($descendant, '=', $related_key);
                })
                ->orWhere(static function (Builder $above) use ($starting_node, $related_key, $ancestor, $descendant, $depth): void {
                    $above->whereColumn($descendant, '=', $starting_node)
                        ->whereColumn($ancestor, '=', $related_key)
                        ->where($depth, '>', 0);
                });
        });
    }

    /**
     * Join the closure rows by either end.
     *
     * @param  Builder<TRelatedModel>|null  $query
     */
    #[Override]
    protected function performJoin($query = null): static
    {
        $query ??= $this->query;

        $query->join($this->table, function (JoinClause $join): void {
            $join->on($this->qualifyPivotColumn('descendant_id'), '=', $this->getQualifiedRelatedKeyName())
                ->orOn($this->qualifyPivotColumn('ancestor_id'), '=', $this->getQualifiedRelatedKeyName());
        });

        return $this;
    }

    #[Override]
    protected function addWhereConstraints(): static
    {
        $this->constrainToStartingNodes([$this->parent->getAttribute($this->parentKey)]);

        return $this;
    }

    /**
     * Keep the rows that start from one of the nodes: below it with the node itself, or strictly above it.
     *
     * @param  array<int, mixed>  $keys
     */
    private function constrainToStartingNodes(array $keys): void
    {
        $related_key = $this->getQualifiedRelatedKeyName();
        $ancestor = $this->qualifyPivotColumn('ancestor_id');
        $descendant = $this->qualifyPivotColumn('descendant_id');
        $depth = $this->qualifyPivotColumn('depth');

        $this->query->where(static function (Builder $query) use ($keys, $related_key, $ancestor, $descendant, $depth): void {
            $query
                ->where(static function (Builder $below) use ($keys, $related_key, $ancestor, $descendant): void {
                    $below->whereIn($ancestor, $keys)->whereColumn($descendant, '=', $related_key);
                })
                ->orWhere(static function (Builder $above) use ($keys, $related_key, $ancestor, $descendant, $depth): void {
                    $above->whereIn($descendant, $keys)
                        ->whereColumn($ancestor, '=', $related_key)
                        ->where($depth, '>', 0);
                });
        });
    }
}
