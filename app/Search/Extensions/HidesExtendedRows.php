<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Contracts\IExtendableModel;

/**
 * Hides the rows of a host that an extender owns (`<type column> IS NOT NULL`) from every default read. A host opens
 * to them with `withExtended()` or {@see IExtendableModel::queryWithExtensions()}, which remove only this scope.
 * Generalised from CMS `HidesExtendedContent`. Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`, XS13.
 */
final class HidesExtendedRows implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($model instanceof IExtendableModel) {
            $builder->whereNull($model->qualifyColumn($model->extensionTypeColumn()));
        }
    }
}
