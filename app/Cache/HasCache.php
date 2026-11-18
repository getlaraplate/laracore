<?php

declare(strict_types=1);

namespace Modules\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type HasCacheType HasCache
 */
trait HasCache
{
    public function getCacheKey(): string
    {
        return property_exists($this, 'cacheKey') ? $this->cacheKey : $this->getTable();
    }

    public function usesCache(): bool
    {
        return true;
    }

    public function invalidateCache(): void
    {
        app(CacheLabelInvalidator::class)->flushTable($this->getTable(), $this);

        $this->invalidateLegacyCache();
    }

    /**
     * Flush the Laravel table tag that {@see Repository::tryByRequest()} still stores under.
     * Called by the boot hooks below and by {@see invalidateCache()}. Removed with the tag storage.
     */
    public function invalidateLegacyCache(): void
    {
        if (! Cache::supportsTags()) {
            Cache::forget($this->getCacheKey());

            return;
        }

        $repository = Cache::getFacadeRoot()->store();

        if (method_exists($repository, 'getCacheTags')) {
            Cache::tags($repository->getCacheTags($this->getTable()))->flush();
        } else {
            Cache::forget($this->getCacheKey());
        }
    }

    /**
     * Keeps the Laravel table tag flushed on every save and delete, including a save that changed nothing, which
     * the label listener does not see. Removed together with the tag storage.
     */
    protected static function bootHasCache(): void
    {
        static::saved(function (self $model): void {
            $model->invalidateLegacyCache();
        });

        static::deleted(function (self $model): void {
            $model->invalidateLegacyCache();
        });
    }
}
