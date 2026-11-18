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
     * Called by the model-write listener for every model using this trait. Removed with the tag storage.
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
}
