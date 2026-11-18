<?php

declare(strict_types=1);

namespace Modules\Core\Cache;

use Modules\Core\Cache\Labels\CacheLabelInvalidator;

/**
 * Opts a model into the CRUD response cache. Its writes are invalidated by the label listener like any other model's.
 *
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
    }
}
