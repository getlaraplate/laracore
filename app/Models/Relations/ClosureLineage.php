<?php

declare(strict_types=1);

namespace Modules\Core\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
}
