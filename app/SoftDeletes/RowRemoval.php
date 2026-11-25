<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes;

use Illuminate\Database\Eloquent\Model;

/**
 * Tells, from a `deleting` listener, whether the delete under way removes the row or only moves it to the bin.
 */
final class RowRemoval
{
    private function __construct() {}

    /**
     * Whether the delete about to run removes the row: always without soft deletes; with them, on a force delete
     * and, with Core's soft deletes, when they are switched off for the model. A force delete fires `deleting` too,
     * after `forceDeleting`, and Core's switched-off delete fires `deleting` alone (its force flag is set after the
     * listeners ran): listening to `deleting` and asking here covers both, and tells a soft delete apart.
     */
    public static function inDelete(Model $model): bool
    {
        if (! method_exists($model, 'isForceDeleting')) {
            return true;
        }

        if ($model->isForceDeleting() === true) {
            return true;
        }

        return method_exists($model, 'softDeletesEnabledBySettings') && $model->softDeletesEnabledBySettings() === false;
    }
}
