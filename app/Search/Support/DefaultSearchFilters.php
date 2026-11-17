<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Contracts\IExtendableModel;
use Modules\Core\Contracts\ProvidesDefaultSearchFilters;

/**
 * The default search filters of a model ({@see ProvidesDefaultSearchFilters}) joined to the filters of a search.
 *
 * The basic search path calls `where()` on the Scout builder for each default filter, then applies the request
 * filters through {@see \Modules\Core\Search\Services\ScoutSearchConstraintApplier}. The merged tree puts the
 * defaults first and the caller's filters after them, in one AND group, so the same applier gives the builder the
 * same calls in the same order: an engine that keeps one equality per field (Elasticsearch, Typesense) lets an
 * explicit equality of the request on the same field replace the default, one that applies every `where()`
 * (the database engine) ANDs them. Any other condition on the field ANDs with the default on every engine.
 */
final class DefaultSearchFilters
{
    /**
     * The equality filters every search of the model applies at the engine: the ones it declares
     * ({@see ProvidesDefaultSearchFilters}), and, for a host of extenders ({@see IExtendableModel}), its type column
     * `IS NULL`, so the rows extenders own stay out without the host declaring it
     * (spec 2026-11-17-extended-entity-search-design.md, XS11). One entry per field.
     *
     * @return array<string, scalar|null>
     */
    public static function of(Model $model): array
    {
        $defaults = $model instanceof ProvidesDefaultSearchFilters ? $model->defaultSearchFilters() : [];

        if ($model instanceof IExtendableModel) {
            $defaults[$model->extensionTypeColumn()] = null;
        }

        return $defaults;
    }

    /**
     * The filters of the search with the model's default search filters ({@see self::of()}) ahead of them. The
     * filters as they are when the model has none.
     */
    public static function merge(Model $model, ?FiltersGroup $filters): ?FiltersGroup
    {
        $defaults = [];

        foreach (self::of($model) as $field => $value) {
            $defaults[] = new Filter((string) $field, $value, FilterOperator::Equals);
        }

        if ($defaults === []) {
            return $filters;
        }

        return new FiltersGroup(
            $filters instanceof FiltersGroup ? [...$defaults, $filters] : $defaults,
            WhereClause::And,
        );
    }
}
