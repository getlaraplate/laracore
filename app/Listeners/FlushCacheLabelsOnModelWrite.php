<?php

declare(strict_types=1);

namespace Modules\Core\Listeners;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;

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
    }
}
