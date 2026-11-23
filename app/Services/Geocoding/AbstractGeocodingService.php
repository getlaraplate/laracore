<?php

declare(strict_types=1);

namespace Modules\Core\Services\Geocoding;

use Closure;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\LabelledCache;
use Modules\Core\Contracts\Geocoding\GeocodingResult;
use Modules\Core\Contracts\Geocoding\IGeocodingService;
use Override;

abstract class AbstractGeocodingService implements IGeocodingService
{
    public const string BASE_URL = '';

    /**
     * @var array<class-string, static>
     */
    private static array $instances = [];

    /**
     * @return array<int, GeocodingResult>|GeocodingResult|null
     */
    abstract protected function performSearch(
        string $query,
        ?string $city,
        ?string $province,
        ?string $country,
        int $limit,
    ): array|GeocodingResult|null;

    abstract protected function getAddressDetails(array $result): GeocodingResult;

    abstract protected function getSearchUrl(string $search_string): string;

    #[Override]
    public static function getInstance(): static
    {
        $class = static::class;

        return self::$instances[$class] ??= new static();
    }

    public function url(GeocodingResult $result): string
    {
        $search_string = $this->composeSearchString($result);

        return $this->getSearchUrl($search_string);
    }

    #[Override]
    public function search(
        string $query,
        ?string $city = null,
        ?string $province = null,
        ?string $country = null,
        int $limit = 1,
    ): array|GeocodingResult|null {
        $search = function () use ($query, $city, $province, $country, $limit): array|GeocodingResult|null {
            $cache_key = $this->generateCacheKey($query, $city, $province, $country, $limit);
            $ttl_seconds = (int) config('cache.duration.long');

            return $this->rememberGeocodingThroughCache(
                $cache_key,
                $ttl_seconds,
                fn (): array|GeocodingResult|null => $this->performSearch($query, $city, $province, $country, $limit),
            );
        };

        if (app()->environment('testing')) {
            return $search();
        }

        $result = RateLimiter::attempt(
            'nominatim:' . md5($query . '|' . $city . '|' . $province . '|' . $country . '|' . $limit),
            60,
            $search,
            1,
        );

        if ($result === true || $result === false) {
            return null;
        }

        return $result;
    }

    protected function composeSearchString(GeocodingResult $result): string
    {
        if ($result->latitude !== null && $result->longitude !== null) {
            return $result->latitude . ',' . $result->longitude;
        }

        $search_string = '';

        if ($result->address) {
            $search_string .= $result->address;
        }

        if ($result->postcode) {
            $search_string .= ' ' . $result->postcode;
        }

        if ($result->city) {
            $search_string .= $result->city;
        }

        if ($result->province) {
            $search_string .= ', ' . $result->province;
        }

        if ($result->country) {
            $search_string .= ', ' . $result->country;
        }

        return $search_string;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolver
     * @return T
     */
    protected function rememberGeocodingThroughCache(string $cache_key, int $ttl_seconds, Closure $resolver): mixed
    {
        try {
            return resolve(LabelledCache::class)->remember(
                CacheManager::key('geocoding', $cache_key),
                [CacheLabel::geocoding()],
                $ttl_seconds,
                $resolver,
                static fn (mixed $value): bool => $value !== null,
            );
        } catch (Exception $exception) {
            Log::error('Geocoding cache error: ' . $exception->getMessage());

            return $resolver();
        }
    }

    private function generateCacheKey(
        string $query,
        ?string $city,
        ?string $province,
        ?string $country,
        int $limit,
    ): string {
        $params = ['query' => $query, 'city' => $city, 'province' => $province, 'country' => $country, 'limit' => $limit];

        return md5(serialize(array_filter($params)));
    }
}
