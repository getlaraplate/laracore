<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes;

use function property_exists;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes as BaseSoftDeletes;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Overrides\CustomSoftDeletingScope;
use Modules\Core\Services\PerModelSettingResolver;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait SoftDeletes
{
    use BaseSoftDeletes {
        bootSoftDeletes as private baseBootSoftDeletes;
        initializeSoftDeletes as private baseInitializeSoftDeletes;
        performDeleteOnModel as private basePerformDeleteOnModel;
        restore as private baseRestore;
    }

    /**
     * Initialize the soft deleting trait for an instance.
     */
    public function initializeSoftDeletes(): void
    {
        $this->baseInitializeSoftDeletes();

        // The base trait casts deleted_at; is_deleted needs the same, otherwise a
        // database returns it as 0/1, which a boolean search mapping rejects.
        if (! isset($this->casts[$this->getIsDeletedColumn()])) {
            $this->casts[$this->getIsDeletedColumn()] = 'boolean';
        }

        $guarded = $this->guarded;

        if (is_array($guarded) && ! in_array($this->getIsDeletedColumn(), $guarded, true)) {
            $this->guarded[] = $this->getIsDeletedColumn();
        }

        $hidden = $this->hidden;

        if (is_array($hidden) && ! in_array($this->getDeletedAtColumn(), $hidden, true)) {
            $this->hidden[] = $this->getDeletedAtColumn();
        }

        if (is_array($hidden) && ! in_array($this->getIsDeletedColumn(), $hidden, true)) {
            $this->hidden[] = $this->getIsDeletedColumn();
        }
    }

    /**
     * Get the name of the "is deleted" column.
     */
    public function getIsDeletedColumn(): string
    {
        return 'is_deleted';
    }

    /**
     * Get the fully qualified "is deleted" column.
     */
    public function getQualifiedIsDeletedColumn(): string
    {
        return $this->qualifyColumn($this->getIsDeletedColumn());
    }

    /**
     * Whether this model instance uses soft delete persistence (vs hard delete).
     * Reads optional per-model property {@see $softDeletesEnabled} or settings in group {@code soft_deletes}
     * with name {@code soft_deletes_{table}}. When no setting exists, soft deletes stay enabled.
     */
    public function softDeletesEnabledBySettings(): bool
    {
        $in_code = $this->softDeletesEnabledInCode();

        if ($in_code !== null) {
            return $in_code;
        }

        return app(PerModelSettingResolver::class)->boolean(
            PerModelSettingResolver::nameFor(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $this->getTable()),
            default: true,
        );
    }

    /**
     * The model's own last word on soft deletes, or null when it does not give one.
     *
     * Asked of the model rather than read from outside because the property may be
     * private, as it is on {@see \Modules\Core\Models\License}: a model pins the
     * answer for itself, and only the model can be asked without tripping over the
     * visibility. {@see \Modules\Core\Console\PermissionsRefreshCommand} needs this
     * half alone, without the setting, to decide whether `delete` and `restore` belong
     * in this model's vocabulary.
     */
    public function softDeletesEnabledInCode(): ?bool
    {
        return property_exists($this, 'softDeletesEnabled') ? (bool) $this->softDeletesEnabled : null;
    }

    public function restore(): ?bool
    {
        if (! $this->softDeletesEnabledBySettings()) {
            return false;
        }

        return $this->baseRestore();
    }

    /**
     * Revive a soft-deleted instance in memory so the next save() persists the
     * restoration in a single write, without tripping the "Cannot update a
     * softdeleted model" guard. Both soft-delete columns are cleared because
     * {@see trashed()} inspects is_deleted first, and the saving hook alone does
     * not run early enough for every persistence path (e.g. optimistic locking).
     */
    public function reviveInMemory(): void
    {
        unset($this->attributes[$this->getIsDeletedColumn()], $this->original[$this->getIsDeletedColumn()]);

        $this->{$this->getDeletedAtColumn()} = null;
    }

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * Uses is_deleted / deleted_at from attributes or original so updates work when
     * nullable soft-delete columns were not hydrated after insert (Laravel 12 strict mode).
     */
    public function trashed(): bool
    {
        $is_deleted_column = $this->getIsDeletedColumn();

        if (array_key_exists($is_deleted_column, $this->attributes)) {
            return (bool) $this->attributes[$is_deleted_column];
        }

        $deleted_at_column = $this->getDeletedAtColumn();

        if (array_key_exists($deleted_at_column, $this->attributes)) {
            return $this->attributes[$deleted_at_column] !== null;
        }

        if (array_key_exists($is_deleted_column, $this->original)) {
            return (bool) $this->original[$is_deleted_column];
        }

        if (array_key_exists($deleted_at_column, $this->original)) {
            return $this->original[$deleted_at_column] !== null;
        }

        return false;
    }

    /**
     * Delete the model. With soft deletes switched off, the row really goes, so what follows the
     * deletion is that of a force delete: `deleted` listeners see {@see isForceDeleting()} true (the
     * media library removes the files on it) and `forceDeleted` fires (embeddings and shared analyses
     * are dropped on it). What precedes it is left alone: `deleting` listeners still see a plain
     * delete, so the `delete` permission keeps gating it, as the permission notes promise.
     */
    public function delete(): ?bool
    {
        if ($this->forceDeleting || $this->softDeletesEnabledBySettings()) {
            return parent::delete();
        }

        try {
            $deleted = parent::delete();
        } finally {
            $this->forceDeleting = false;
        }

        if ($deleted === true) {
            $this->fireModelEvent('forceDeleted', false);
        }

        return $deleted;
    }

    /**
     * Boot the soft deleting trait for a model.
     */
    protected static function bootSoftDeletes(): void
    {
        // Do not call baseBootSoftDeletes() (would register SoftDeletingScope) or
        // static::withoutGlobalScope() here: the latter is not a Model API and
        // resolves via __callStatic → query(), which requires a DB connection during
        // composer package:discover / ide-helper when the resolver is not set yet.
        static::addGlobalScope(new CustomSoftDeletingScope());

        static::updating(function (self $model): void {
            throw_if($model->trashed(), AuthorizationException::class, 'Cannot update a softdeleted model');
        });

        static::saving(function (self $model): void {
            $is_deleted_column = $model->getIsDeletedColumn();
            unset($model->attributes[$is_deleted_column], $model->original[$is_deleted_column]);
        });
    }

    protected function performDeleteOnModel(): void
    {
        if (! $this->softDeletesEnabledBySettings()) {
            // Set after the `deleting` listeners ran and reset by delete() once `deleted` has fired.
            $this->forceDeleting = true;
            $this->setKeysForSaveQuery($this->newModelQuery())->forceDelete();
            $this->exists = false;

            return;
        }

        $this->basePerformDeleteOnModel();

        // is_deleted is generated from deleted_at by the database: an instance that read it back, as the revision
        // scope does when it locks the row, would otherwise keep the value from before the delete.
        $is_deleted_column = $this->getIsDeletedColumn();

        if (array_key_exists($is_deleted_column, $this->attributes)) {
            $this->attributes[$is_deleted_column] = true;
            $this->syncOriginalAttribute($is_deleted_column);
        }
    }
}
