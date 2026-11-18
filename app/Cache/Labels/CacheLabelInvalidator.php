<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Contracts\ICacheLabelIndex;

/**
 * Turns a write into label flushes. When an owning model is given the flush waits for the commit of
 * the transaction that model's connection has open, and runs at once when there is none.
 */
final readonly class CacheLabelInvalidator
{
    public function __construct(private ICacheLabelIndex $index) {}

    public function flushTable(string $table, ?Model $owner = null): void
    {
        $this->flushLabels($owner, CacheLabel::table($table));
    }

    public function flushRecord(Model $model, bool $with_table = true): void
    {
        $labels = [];

        if ($with_table) {
            $labels[] = CacheLabel::table($model->getTable());
        }

        // A model loaded without its key, such as a partial pivot row, has no row to name.
        $key = array_key_exists($model->getKeyName(), $model->getAttributes()) ? $model->getKey() : null;

        if (is_int($key) || (is_string($key) && $key !== '')) {
            $labels[] = CacheLabel::row($model->getTable(), $key);
        }

        $this->flushLabels($model, ...$labels);
    }

    /**
     * @param  BelongsToMany<*, *, *, *>  $relation
     */
    public function flushPivot(BelongsToMany $relation): void
    {
        $parent = $relation->getParent();

        $this->flushLabels(
            $parent,
            CacheLabel::table($relation->getTable()),
            CacheLabel::table($parent->getTable()),
            CacheLabel::table($relation->getRelated()->getTable()),
        );
    }

    public function flushUser(int|string $id, ?Model $owner = null): void
    {
        $this->flushLabels($owner, CacheLabel::user($id));
    }

    public function flushRole(int|string $id, ?Model $owner = null): void
    {
        $this->flushLabels($owner, CacheLabel::role($id));
    }

    public function flushLabels(?Model $owner, string ...$labels): void
    {
        if ($labels === []) {
            return;
        }

        $flush = fn () => $this->index->flush(...$labels);

        if ($owner === null) {
            $flush();

            return;
        }

        $connection = $owner->getConnection();

        if ($connection->transactionLevel() === 0) {
            $flush();

            return;
        }

        $connection->afterCommit($flush);
    }
}
