<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Search\Contracts\IReportsVectorDimensions;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\VectorAvailability;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Support\VectorProfileContext;

/**
 * Default guard: vector search is available unless there is no vector profile, or the engine's index holds
 * vectors of a dimension other than the profile's. Modules that know more (a model switch in progress) decorate it.
 */
final class VectorSearchAvailability implements IVectorSearchAvailability
{
    private const string CACHE_PREFIX = 'core.search.vector.dimensions_check.';

    private const string KEYS_KEY = 'core.search.vector.dimensions_check_keys';

    private const int TTL_SECONDS = 60;

    public function check(Model $model): VectorAvailability
    {
        $profile = VectorProfileContext::get();

        if (! $profile instanceof VectorProfile) {
            return VectorAvailability::no('disabled');
        }

        $indexed = $this->indexedDimensions($model);

        if ($indexed !== null && $indexed !== $profile->dimensions) {
            return VectorAvailability::no('dimension_mismatch');
        }

        return VectorAvailability::yes();
    }

    /**
     * Drops the cached dimension checks, so the next check asks the engine again.
     */
    public function forget(): void
    {
        $keys = Cache::get(self::KEYS_KEY, []);

        foreach (is_array($keys) ? $keys : [] as $key) {
            if (is_string($key)) {
                Cache::forget($key);
            }
        }

        Cache::forget(self::KEYS_KEY);
    }

    private function indexedDimensions(Model $model): ?int
    {
        $engine = method_exists($model, 'searchableUsing') ? $model->searchableUsing() : null;

        if (! $engine instanceof IReportsVectorDimensions) {
            return null;
        }

        $key = self::CACHE_PREFIX . $model::class;
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('dimensions', $cached)) {
            return $cached['dimensions'];
        }

        $dimensions = $engine->indexedVectorDimensions($model);
        Cache::put($key, ['dimensions' => $dimensions], self::TTL_SECONDS);

        $keys = Cache::get(self::KEYS_KEY, []);
        $keys = is_array($keys) ? $keys : [];

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
        }

        Cache::put(self::KEYS_KEY, $keys, self::TTL_SECONDS * 60);

        return $dimensions;
    }
}
