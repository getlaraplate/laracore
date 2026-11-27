<?php

declare(strict_types=1);

namespace Modules\Core\Locking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Support\PlainTableModel;
use Modules\Core\Support\TableModels;

/**
 * Releases every lock of a table: what follows switching record locking off (the setting,
 * {@see \Modules\Core\Jobs\ReleaseLocksJob}) or removing it (`model:locked-remove`). Nothing is deleted: the lock
 * columns `HasLocks` writes ({@see Locked::lockedAtColumn()}, {@see Locked::lockedByColumn()},
 * {@see Locked::lockedUntilColumn()}) are cleared on every row that carries a lock, lapsed ones included, in one
 * statement, and the whole table is flushed from the cache after it, as for any write that fires no model event.
 */
final readonly class LockedRowsReleaser
{
    public function __construct(private CacheLabelInvalidator $invalidator) {}

    /**
     * How many rows of the table carry a lock; 0 when the table or its lock column does not exist.
     */
    public function countFor(string $table): int
    {
        return $this->lockedRows($this->modelOf($table))?->count() ?? 0;
    }

    /**
     * Clears the lock columns of every locked row of the table. Returns how many rows were released.
     */
    public function release(string $table): int
    {
        $model = $this->modelOf($table);
        $query = $this->lockedRows($model);

        if (! $query instanceof Builder) {
            return 0;
        }

        $locked = new Locked();
        $schema = $model->getConnection()->getSchemaBuilder();
        $values = [];

        foreach ([$locked->lockedAtColumn(), $locked->lockedByColumn(), $locked->lockedUntilColumn()] as $column) {
            if ($column !== '' && $schema->hasColumn($table, $column)) {
                $values[$column] = null;
            }
        }

        $released = $query->update($values);

        // A mass write fires no model event: a cached response shows the lock, so the whole table is flushed.
        $this->invalidator->flushTable($table, $model);

        return $released;
    }

    /**
     * The model of the table, the first that locks when several share it, or a plain model of the table.
     */
    private function modelOf(string $table): Model
    {
        $models = TableModels::for($table);

        foreach ($models as $model) {
            if (class_uses_trait($model, HasLocks::class)) {
                return $model;
            }
        }

        return $models[0] ?? new PlainTableModel($table);
    }

    /**
     * The rows of the model's table that carry a lock, scopes left out; null without the table or its lock column.
     */
    private function lockedRows(Model $model): ?Builder
    {
        $table = $model->getTable();
        $locked_at = new Locked()->lockedAtColumn();
        $schema = $model->getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $locked_at)) {
            return null;
        }

        return $model->newQueryWithoutScopes()->toBase()->whereNotNull($locked_at);
    }
}
