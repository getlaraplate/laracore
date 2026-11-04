<?php

declare(strict_types=1);

namespace Modules\Core\Locking\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Locking\Exceptions\MissingLockVersionException;
use Modules\Core\Locking\Exceptions\StaleModelLockingException;

/**
 * Derived from reshadman/laravel-optimistic-locking (MIT), see NOTICES/laravel-optimistic-locking.md.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasOptimisticLocking
{
    /**
     * Indicates that models uses locking or not?
     *
     * @var bool
     */
    protected $lock = true;

    /**
     * Name of the lock version column.
     *
     * Public so tooling (e.g. the model:lock-refresh command) can resolve the
     * column name from a model instance, mirroring HasLocks' public accessors.
     */
    public static function lockVersionColumn(): string
    {
        return config('core.locking.lock_version_column');
    }

    /**
     * Current lock version value.
     *
     * Null when the model was hydrated without the version column, e.g. through
     * a partial select, or when it has not been persisted yet.
     */
    public function currentLockVersion(): ?int
    {
        $version = $this->getAttribute(static::lockVersionColumn());

        return $version === null ? null : (int) $version;
    }

    /**
     * Enables optimistic locking for this model instance.
     *
     * @return $this
     */
    public function enableLocking()
    {
        $this->lock = true;

        return $this;
    }

    /**
     * Hooks model events to add lock version if not set.
     */
    protected static function bootHasOptimisticLocking(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute(static::lockVersionColumn()) === null) {
                $model->{static::lockVersionColumn()} = 1;
            }
        });
    }

    /**
     * Perform a model update operation respecting optimistic locking.
     * If the lock fails it will throw a "StaleModelLockingException".
     */
    protected function performUpdate(Builder $query): bool
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $versionColumn = static::lockVersionColumn();
            $beforeUpdateVersion = $this->currentLockVersion();

            // Refuse the update rather than writing without the guard: a silent
            // overwrite is exactly what optimistic locking exists to prevent.
            if ($beforeUpdateVersion === null) {
                throw MissingLockVersionException::forModel($this, $versionColumn);
            }

            $this->setKeysForSaveQuery($query);

            // If model locking is enabled, the lock version check constraint is
            // added to the update query, as every update on the model increments the version
            // by exactly "1" we will increment the value by one for update, then.
            if ($this->lockingEnabled()) {
                $query->where($versionColumn, '=', $beforeUpdateVersion);
            }

            $this->setAttribute($versionColumn, $newVersion = $beforeUpdateVersion + 1);
            $dirty[$versionColumn] = $newVersion;

            // If there is no record affected by our update query,
            // It means that the record has been updated by another process,
            // Or has been deleted, as we treat "delete" as an act of update
            // we throw the exception in this situation anyway.
            $affected = $query->update($dirty);

            if ($affected === 0) {
                $this->setAttribute($versionColumn, $beforeUpdateVersion);

                throw new StaleModelLockingException('Model has been changed during update.');
            }

            $this->fireModelEvent('updated', false);

            $this->syncChanges();
        }

        return true;
    }

    /**
     * Indicates that optimistic locking is enabled for this model
     * instance or not.
     */
    protected function lockingEnabled(): bool
    {
        return $this->lock ?? true;
    }

    /**
     * Disables optimistic locking for this model instance.
     *
     * @return $this
     */
    protected function disableLocking()
    {
        $this->lock = false;

        return $this;
    }
}
