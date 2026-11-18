<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Geocoding;

use Modules\Core\Contracts\Geocoding\GeocodingResult;
use Modules\Core\Services\Geocoding\AbstractGeocodingService;
use Override;

/**
 * Geocoder that answers without any network call and counts how often it was asked.
 */
final class CountingGeocodingService extends AbstractGeocodingService
{
    public int $searches = 0;

    #[Override]
    protected function performSearch(
        string $query,
        ?string $city,
        ?string $province,
        ?string $country,
        int $limit,
    ): array|GeocodingResult|null {
        $this->searches++;

        return new GeocodingResult(name: $query, latitude: 45.0, longitude: 9.0);
    }

    #[Override]
    protected function getAddressDetails(array $result): GeocodingResult
    {
        return new GeocodingResult();
    }

    #[Override]
    protected function getSearchUrl(string $search_string): string
    {
        return 'https://geocoder.invalid/?q=' . $search_string;
    }
}
