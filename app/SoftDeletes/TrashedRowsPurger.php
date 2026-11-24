<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes as EloquentSoftDeletes;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Locking\Locked;
use Modules\Core\Support\PlainTableModel;
use Modules\Core\Support\TableModels;

/**
 * Deletes for good the rows of a table that sit in the bin: the purge that follows switching soft deletes off (the
 * setting, {@see \Modules\Core\Jobs\PurgeTrashedRowsJob}) or removing them (`model:soft-deletes-remove` and the
 * migration it writes).
 *
 * A row is in the bin when `deleted_at` is set or `is_deleted` is true, whichever of the two columns the table has, so
 * the rows are found whether or not the model still declares soft deletes. Each row is deleted on its own, through its
 * model, in pages by key: a model that still soft deletes is force deleted (its `forceDeleted` event runs, so its
 * media and embeddings leave with it, and a versioned record keeps its snapshot set), one that no longer does is
 * deleted, which is then a hard delete with its own events. The lock guard is lifted for the purge: a row in the bin
 * may still carry a lock, and nobody running the purge holds it.
 */
final readonly class TrashedRowsPurger
{
    private const int CHUNK = 100;

    public function __construct(private CacheLabelInvalidator $invalidator) {}

    /**
     * How many rows of the table are in the bin; 0 when it has neither column or does not exist.
     */
    public function countFor(string $table): int
    {
        $model = $this->modelOf($table);

        return $model instanceof Model ? $this->count($model) : 0;
    }

    /**
     * How many rows of the model's table are in the bin, scopes left out.
     */
    public function count(Model $model): int
    {
        $query = $this->trashedRows($model);

        return $query instanceof Builder ? $query->count() : 0;
    }

    /**
     * Deletes for good every row of the model's table in the bin. Returns how many were deleted.
     */
    public function purge(Model $model): int
    {
        $query = $this->trashedRows($model);

        if (! $query instanceof Builder) {
            return 0;
        }

        // A table no model reads has no events of its own to run and no known key to page by: one statement, then the
        // whole table is flushed from the cache, as for any write that fires no model event.
        if ($model instanceof PlainTableModel) {
            $purged = $query->toBase()->delete();
            $this->invalidator->flushTable($model->getTable(), $model);

            return $purged;
        }

        $force = class_uses_trait($model, EloquentSoftDeletes::class);
        $purged = 0;

        // A row in the bin can still carry a lock nobody running a maintenance purge holds: the guard is lifted, as for
        // the other maintenance writes.
        Locked::withoutGuard(static function () use ($query, $force, &$purged): bool {
            return $query->chunkById(self::CHUNK, static function (iterable $records) use ($force, &$purged): void {
                foreach ($records as $record) {
                    /** @var Model $record */
                    if (method_exists($record, 'setForcedApprovalUpdate')) {
                        // A maintenance purge: it runs at once instead of queueing a request nobody approves.
                        $record->setForcedApprovalUpdate(true);
                    }

                    if ($force ? $record->forceDelete() : $record->delete()) {
                        $purged++;
                    }
                }
            });
        });

        return $purged;
    }

    /**
     * {@see purge()} for a table: through its model when the application has one, with one plain delete otherwise.
     */
    public function purgeTable(string $table): int
    {
        return $this->purge($this->modelOf($table) ?? new PlainTableModel($table));
    }

    /**
     * The model of the table: the first one that still soft deletes, so its events run; any other one otherwise.
     */
    private function modelOf(string $table): ?Model
    {
        $models = TableModels::for($table);

        foreach ($models as $model) {
            if (class_uses_trait($model, EloquentSoftDeletes::class)) {
                return $model;
            }
        }

        return $models[0] ?? null;
    }

    /**
     * The rows of the model's table in the bin, scopes left out; null when the table has neither column.
     *
     * @return Builder<Model>|null
     */
    private function trashedRows(Model $model): ?Builder
    {
        $table = $model->getTable();
        $schema = $model->getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return null;
        }

        $deleted_at = $schema->hasColumn($table, 'deleted_at');
        $is_deleted = $schema->hasColumn($table, 'is_deleted');

        if (! $deleted_at && ! $is_deleted) {
            return null;
        }

        return $model->newQueryWithoutScopes()->where(static function (Builder $query) use ($table, $deleted_at, $is_deleted): void {
            if ($deleted_at) {
                $query->whereNotNull($table . '.deleted_at');
            }

            if ($is_deleted) {
                $query->orWhere($table . '.is_deleted', true);
            }
        });
    }
}
