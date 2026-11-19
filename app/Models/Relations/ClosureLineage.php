<?php

declare(strict_types=1);

namespace Modules\Core\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Override;

/**
 * The ancestors or the descendants of a node through its closure table, the node itself included or not, down to
 * a maximum distance or not: `ancestors`, `descendants`, their `AndSelf` forms, `parentAndSelf` and
 * `childrenAndSelf` are all this relation.
 *
 * The nodes come by distance from the node, nearest first. The order is set when the relation is built, so it
 * comes before any order a global scope of the model adds: siblings at the same distance follow the scope's order.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends ClosureRelation<TRelatedModel, TDeclaringModel>
 */
final class ClosureLineage extends ClosureRelation
{
    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @param  bool  $towardsAncestors  Walk up to the ancestors rather than down to the descendants
     * @param  bool  $andSelf  Include the node itself, at depth 0
     * @param  int|null  $maxDistance  Stop this many levels away from the node; null walks the whole line
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $closureTable,
        bool $towardsAncestors,
        bool $andSelf,
        ?int $maxDistance,
        string $relationName,
    ) {
        parent::__construct(
            $query,
            $parent,
            $closureTable,
            $towardsAncestors ? 'descendant_id' : 'ancestor_id',
            $towardsAncestors ? 'ancestor_id' : 'descendant_id',
            $relationName,
        );

        $depth = $this->qualifyPivotColumn('depth');

        if (! $andSelf) {
            $this->query->where($depth, '>', 0);
        }

        if ($maxDistance !== null) {
            $this->query->where($depth, '<=', $maxDistance);
        }

        $this->query->orderBy($depth);
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    #[Override]
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->reachFromClosureRows($this->getKeys($models, $this->parentKey));
    }

    /**
     * Restate the starting nodes on the key of the nodes, as the keys their closure rows reach. The result is the
     * same; the planner gets a way in by key. Without it, a planner that prices a long list of starting keys above
     * a low-cardinality index of the model table (SQLite on `is_deleted`) walks every node, runs the global scopes'
     * subqueries on each, and only then probes the closure rows. One starting node needs no help.
     *
     * @param  array<int, mixed>  $keys
     */
    private function reachFromClosureRows(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $closure_table = $this->table;
        $reach = $closure_table . '_reach';
        $where_in = $this->whereInMethod($this->parent, $this->parentKey);
        $from = $reach . '.' . $this->foreignPivotKey;
        $to = $reach . '.' . $this->relatedPivotKey;

        $this->query->whereIn(
            $this->getQualifiedRelatedKeyName(),
            static function (QueryBuilder $reached) use ($closure_table, $reach, $from, $to, $keys, $where_in): void {
                $reached->select($to)->from($closure_table . ' as ' . $reach)->{$where_in}($from, $keys);
            },
        );
    }
}
