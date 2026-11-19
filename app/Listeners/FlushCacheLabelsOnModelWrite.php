<?php

declare(strict_types=1);

namespace Modules\Core\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Models\Modification;

/**
 * Flushes the cache labels of a model after it was created, updated, deleted, restored or force deleted.
 * A creation only changes the table label, as no cached response can hold a row that did not exist.
 */
final readonly class FlushCacheLabelsOnModelWrite
{
    public function __construct(private CacheLabelInvalidator $invalidator) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model) {
            return;
        }

        if (str_starts_with($event, 'eloquent.created:')) {
            $this->invalidator->flushLabels($model, CacheLabel::table($model->getTable()));
        } else {
            $this->invalidator->flushRecord($model);
        }

        if ($model instanceof Modification) {
            $this->flushModifiable($model);
        }
    }

    /**
     * A pending request changes what is read of its record without writing the record: a preview embeds it, and a
     * model that hides pending deletions drops the record from the lists of whoever cannot approve. So a request
     * created, decided or withdrawn flushes the table and the row of the record it is about. A pending create has no
     * record yet.
     */
    private function flushModifiable(Modification $modification): void
    {
        $key = $modification->modifiable_id;
        $type = $modification->modifiable_type;

        if ((! is_int($key) && ! is_string($key)) || $key === '' || ! is_string($type)) {
            return;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true)) {
            return;
        }

        $table = new $class()->getTable();

        $this->invalidator->flushLabels($modification, CacheLabel::table($table), CacheLabel::row($table, $key));
    }
}
