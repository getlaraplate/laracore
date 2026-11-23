<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\DynamicContentsService;

/**
 * Invalidates the persistent dynamic-content metadata cache when the models that
 * describe it change.
 *
 * {@see DynamicContentsService} caches entities, presets and presettables through
 * {@see \Illuminate\Support\Facades\Cache::memo()} for `cache.duration.long`. Those lists are
 * interdependent (presets are filtered by their entity, presettables join both
 * presets and entities), so any write to an Entity, Preset or the preset↔field
 * pivot must flush every metadata bucket. Presets and entities change rarely, so
 * a full flush on write is cheap and keeps the cache correct.
 */
final class DynamicContentMetadataObserver
{
    public function created(Model $model): void
    {
        $this->flush();
    }

    public function updated(Model $model): void
    {
        $this->flush();
    }

    public function deleted(Model $model): void
    {
        $this->flush();
    }

    public function restored(Model $model): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        DynamicContentsService::getInstance()->clearAllCaches();
    }
}
