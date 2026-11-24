<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use MatanYadaev\EloquentSpatial\Traits\HasSpatial;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Overrides\Model;
use Override;

/**
 * Canonical postal / geographic payload shared across modules (e.g. Cms Location, Business Site).
 */
class Place extends Model
{
    use HasSpatial;

    private const float COORDINATE_EPSILON = 1e-7;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Places->value;

    /**
     * Exclude {@see geolocation} from version snapshots: the DB returns binary WKB which breaks JSON
     * encoding on {@see Version}. {@see latitude} / {@see longitude} retain the point.
     *
     * @var list<string>
     */
    #[Override]
    protected array $dontVersionable = [
        'created_at',
        'updated_at',
        'last_login_at',
        'geolocation',
    ];

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        'address',
        'city',
        'province',
        'country',
        'postcode',
        'zone',
        'latitude',
        'longitude',
        'geolocation',
    ];

    /**
     * Geography fields for search documents (shape aligned with former Location-only payloads).
     *
     * @return array{
     *     address: string|null,
     *     city: string|null,
     *     province: string|null,
     *     country: string|null,
     *     postcode: string|null,
     *     zone: string|null,
     *     geocode: array{0: float, 1: float}
     * }
     */
    public function searchDocumentGeographyFields(): array
    {
        return [
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'postcode' => $this->postcode,
            'zone' => $this->zone,
            'geocode' => [
                (float) ($this->latitude ?? 0.0),
                (float) ($this->longitude ?? 0.0),
            ],
        ];
    }

    /**
     * Version snapshots store {@see latitude} / {@see longitude} (JSON-safe) but omit binary {@see geolocation}.
     * On save, rebuild the {@see Point} so rollbacks and plain decimal edits stay consistent with the geometry column.
     */
    protected static function booted(): void
    {
        static::saving(function (Place $place): void {
            $place->syncGeolocationFromDecimalIfNeeded();
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geolocation' => Point::class,
        ];
    }

    /**
     * When decimals differ from the cast geometry (e.g. after {@see Version::revertWithoutSaving} merged snapshot lat/lng with stale WKB), align {@see geolocation} before persistence.
     */
    private function syncGeolocationFromDecimalIfNeeded(): void
    {
        if (! $this->geometryColumnSupportsSpatialBinding()) {
            return;
        }

        $lat = $this->getAttribute('latitude');
        $lng = $this->getAttribute('longitude');

        if ($lat === null || $lng === null) {
            return;
        }

        $lat_float = (float) $lat;
        $lng_float = (float) $lng;

        $current = $this->geolocation;

        if ($current instanceof Point && (abs($current->latitude - $lat_float) < self::COORDINATE_EPSILON && abs($current->longitude - $lng_float) < self::COORDINATE_EPSILON)) {
            return;
        }

        $this->setAttribute('geolocation', new Point($lat_float, $lng_float));
    }

    /**
     * Match {@see Concerns\HasPlace::shouldPersistPlaceGeolocationGeometry}: avoid spatial bindings on SQLite tests.
     */
    private function geometryColumnSupportsSpatialBinding(): bool
    {
        return in_array(DB::connection($this->getConnectionName())->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);
    }
}
