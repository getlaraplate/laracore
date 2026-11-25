<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;
use Override;

/**
 * @extends SoftDeletingScope<Model>
 */
final class CustomSoftDeletingScope extends SoftDeletingScope
{
    /**
     * The qualified `is_deleted` column of a model using Core's SoftDeletes, the only kind this scope is added to.
     */
    public static function isDeletedColumn(Model $model): string
    {
        $column = method_exists($model, 'getQualifiedIsDeletedColumn') ? $model->getQualifiedIsDeletedColumn() : null;

        if (! is_string($column)) {
            throw new LogicException($model::class . ' is not soft-deleted through Core.');
        }

        return $column;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder<covariant Model>  $builder
     */
    #[Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(self::isDeletedColumn($model), false);
    }

    /**
     * Add the without-trashed extension to the builder.
     *
     * @param  Builder<*>  $builder
     */
    #[Override]
    protected function addWithoutTrashed(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('withoutTrashed', function (Builder $builder) use ($scope): Builder {
            $builder->withoutGlobalScope($scope)->where(CustomSoftDeletingScope::isDeletedColumn($builder->getModel()), false);

            return $builder;
        });
    }

    /**
     * Add the only-trashed extension to the builder.
     *
     * @param  Builder<*>  $builder
     */
    #[Override]
    protected function addOnlyTrashed(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('onlyTrashed', function (Builder $builder) use ($scope): Builder {
            $builder->withoutGlobalScope($scope)->where(CustomSoftDeletingScope::isDeletedColumn($builder->getModel()), true);

            return $builder;
        });
    }
}
