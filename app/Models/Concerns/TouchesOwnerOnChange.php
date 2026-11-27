<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\OwnerTimestamp;

/**
 * For a row that is part of another record, a translation or a pivot: when it is created, updated or deleted,
 * the owner's `updated_at` moves through {@see OwnerTimestamp}, without the owner's events or `lock_version`.
 * A pivot of Laravel fires these events for `attach`, `detach` (also without ids), `sync`, `toggle` and
 * `updateExistingPivot` when the relation uses a custom pivot class (D30, D37).
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait TouchesOwnerOnChange
{
    /**
     * The owner to touch, as a model of its class and its key, or null when there is none.
     *
     * @return array{0: Model, 1: int|string}|null
     */
    abstract protected function ownerToTouch(): ?array;

    public static function bootTouchesOwnerOnChange(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::$event(static function (Model $model): void {
                if (method_exists($model, 'touchOwner')) {
                    $model->touchOwner();
                }
            });
        }
    }

    public function touchOwner(): void
    {
        $owner = $this->ownerToTouch();

        if ($owner !== null) {
            OwnerTimestamp::touch($owner[0], $owner[1]);
        }
    }

    /**
     * The owner reached through a `belongsTo` relation of this row.
     *
     * @param  BelongsTo<covariant Model, covariant Model>  $relation
     * @return array{0: Model, 1: int|string}|null
     */
    protected function ownerThrough(BelongsTo $relation): ?array
    {
        $key = $this->getAttribute($relation->getForeignKeyName());

        return is_int($key) || is_string($key) ? [$relation->getRelated(), $key] : null;
    }
}
