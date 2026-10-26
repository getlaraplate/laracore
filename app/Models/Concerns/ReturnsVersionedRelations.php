<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Overrides\Relations\BelongsToMany;
use Modules\Core\Overrides\Relations\MorphToMany;

/**
 * Makes the model's many-to-many relations Core's, whose mutators open the revision scope of a versioned relation
 * (D42). Used by `Core\Overrides\Model`; a model outside it uses it when it takes part in a versioned relation.
 *
 * @phpstan-require-extends Model
 */
trait ReturnsVersionedRelations
{
    /**
     * @param  Builder<Model>  $query
     * @return BelongsToMany<Model, $this>
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ) {
        return new BelongsToMany($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * @param  Builder<Model>  $query
     * @return MorphToMany<Model, $this>
     */
    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        $name,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
        $inverse = false,
    ) {
        return new MorphToMany($query, $parent, $name, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName, $inverse);
    }
}
