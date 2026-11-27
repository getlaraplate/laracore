<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Modules\Core\Contracts\ProvidesDefaultSearchFilters;

/**
 * Applies the default search filters of a model (`ProvidesDefaultSearchFilters`) to a Scout builder.
 *
 * Every Scout search of the generic search paths goes through here: Basic mode (`CrudService`) and every
 * strategy of the orchestrated modes (`EnsembleSearchService`). Call it last, after the request
 * constraints, so a request filter can never widen a default:
 *
 * - an engine that ANDs the `wheres` list (the database engine's keyword path, Scout's Typesense filter)
 *   gets both constraints, and a request filter that contradicts the default finds nothing;
 * - an engine that reads `wheres` as a `field => value` map (`ScoutWheres::map()`: Elasticsearch, the
 *   database engine's vector candidates, the Typesense vector search) keeps the later value, which is the
 *   default.
 *
 * A contradictory request filter may therefore return nothing on one engine and the defaulted set on
 * another, but never a row the default excludes.
 */
final class DefaultSearchFilters
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public static function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof ProvidesDefaultSearchFilters) {
            return;
        }

        foreach ($model->defaultSearchFilters() as $field => $value) {
            $builder->where($field, $value);
        }
    }
}
