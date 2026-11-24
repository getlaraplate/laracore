<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\DynamicContentsService;

/**
 * Invalidates the persistent dynamic-content metadata cache when the models that
 * describe it change.
 *
 * {@see DynamicContentsService} caches entities, presets and presettables through the
 * label index for `cache.duration.long`, under the `dynamic_contents` label. Those lists are
 * interdependent (presets are filtered by their entity, presettables join both
 * presets and entities), so any write to an Entity, Preset or the preset↔field
 * pivot must flush every metadata bucket. Presets and entities change rarely, so
 * a full flush on write is cheap and keeps the cache correct.
 */
final class DynamicContentMetadataObserver
{
    public function created(Model $model): void
    {
        $this->flush($model);
    }

    public function updated(Model $model): void
    {
        $this->flush($model);
    }

    public function deleted(Model $model): void
    {
        $this->flush($model);
    }

    public function restored(Model $model): void
    {
        $this->flush($model);
    }

    /**
     * The written model is the owner: the label flush waits for its transaction to end.
     */
    private function flush(Model $model): void
    {
        DynamicContentsService::getInstance()->clearAllCaches($model);
    }
}
