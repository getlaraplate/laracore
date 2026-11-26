<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Modules\Core\Models\Place;
use TypeError;

/**
 * Transparent read/write of geographic fields through {@see Place}, same pattern as {@see HasTranslations}
 * ({@see getAttribute}, {@see setAttribute}, {@see toArray}): bridged keys never persist on the host table;
 * pending values are flushed into a new {@see Place} on {@see creating} when {@see place_id} is empty.
 *
 * The geography read and written through the place:
 *
 * @property Point|null $geolocation
 * @property string|null $address
 * @property string|null $city
 * @property string|null $province
 * @property string|null $country
 * @property string|null $postcode
 * @property string|null $zone
 * @property float|null $latitude
 * @property float|null $longitude
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasPlace
{
    /**
     * Bridged scalar fields collected before {@see place_id} exists (mass assignment / factory).
     *
     * @var array<string, mixed>
     */
    private array $pending_place_bridged_scalars = [];

    /**
     * Point payload held on the host until {@see creating} creates the related {@see Place}.
     */
    private ?Point $pending_host_geolocation = null;

    public static function bootHasPlace(): void
    {
        static::creating(static function (Model $model): void {
            if (! $model instanceof self) {
                return;
            }

            if (! empty($model->attributes['place_id'] ?? null)) {
                return;
            }

            $place = Place::query()->create($model->buildPlaceRowFromHostAttributes());
            $model->setAttribute('place_id', $place->getKey());
            $model->clear_pending_place_payload();
        });
    }

    /**
     * Bridged geography always comes from Place on serialize / search / path, so every
     * host model eager-loads the relation. Declared here rather than on each model (or
     * special-cased in the CRUD query builder) so any future HasPlace model inherits it;
     * a caller that does not need it can still drop it with `->without('place')`.
     */
    public function initializeHasPlace(): void
    {
        if (! in_array('place', $this->with, true)) {
            $this->with[] = 'place';
        }
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * Bridged geography may live on {@see Place} only; validation rules on the host (e.g. Location) still expect those keys.
     *
     * @return array<string, mixed>
     */
    public function getAttributesForValidation(): array
    {
        $attributes = parent::getAttributesForValidation();

        if (! empty($attributes['place_id'] ?? null)) {
            $place = $this->resolvePlace();

            if ($place instanceof Place) {
                foreach ($this->placeBridgedAttributeKeys() as $key) {
                    $attributes[$key] = $place->getAttribute($key);
                }
                $attributes['geolocation'] = $this->pointFromPlace($place);
            }

            return $attributes;
        }

        return array_merge($attributes, $this->extraPlaceAttributesPendingForValidation());
    }

    public function getAttribute($key): mixed
    {
        if ($key === 'geolocation') {
            if (! (($this->attributes['place_id'] ?? null) === null)) {
                $place = $this->resolvePlace();

                if ($place instanceof Place) {
                    return $this->pointFromPlace($place);
                }
            }

            return $this->pending_host_geolocation;
        }

        if ($this->shouldReadGeographyFromPlace($key)) {
            $place = $this->resolvePlace();

            if ($place !== null) {
                return match ($key) {
                    'latitude' => $place->latitude,
                    'longitude' => $place->longitude,
                    default => $place->getAttribute($key),
                };
            }
        }

        if (in_array($key, $this->placeBridgedAttributeKeys(), true) && empty($this->attributes['place_id'] ?? null) && array_key_exists((string) $key, $this->pending_place_bridged_scalars)) {
            return $this->pending_place_bridged_scalars[$key];
        }

        return parent::getAttribute($key);
    }

    /**
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        if ($key === 'geolocation') {
            return $this->setGeolocationThroughPlace($value);
        }

        if (! in_array($key, $this->placeBridgedAttributeKeys(), true)) {
            return parent::setAttribute($key, $value);
        }

        $mutator = 'set' . Str::studly($key) . 'Attribute';

        if (method_exists($this, $mutator)) {
            $this->{$mutator}($value);

            if (! (($this->attributes['place_id'] ?? null) === null)) {
                $this->syncGeographyToRelatedPlace();
            }

            return $this;
        }

        if (($this->attributes['place_id'] ?? null) === null) {
            $this->pending_place_bridged_scalars[$key] = $value;

            return $this;
        }

        $place = $this->resolvePlace();

        if (! $place instanceof Place) {
            return $this;
        }

        $place->setAttribute($key, $value);
        $place->save();

        if ($this->relationLoaded('place')) {
            $this->setRelation('place', $place->fresh());
        }

        return $this;
    }

    /**
     * Overlay bridged geography from {@see Place} so JSON / API output matches {@see getAttribute} behaviour
     * (mirrors {@see HasTranslations::toArray} merging related row fields into the serialized array).
     *
     * @param  array<string, mixed>|null  $parsed
     * @return array<string, mixed>
     */
    public function toArray(?array $parsed = null): array
    {
        $content = $parsed ?? (method_exists(parent::class, 'toArray') ? parent::toArray() : $this->attributesToArray());

        if (! (($this->attributes['place_id'] ?? null) === null)) {
            $place = $this->resolvePlace();

            if (! $place instanceof Place) {
                return $content;
            }

            foreach ($this->placeBridgedAttributeKeys() as $field) {
                if (in_array($field, $this->hidden, true)) {
                    continue;
                }

                $content[$field] = match ($field) {
                    'latitude' => $place->latitude,
                    'longitude' => $place->longitude,
                    default => $place->getAttribute($field),
                };
            }

            if (! in_array('geolocation', $this->hidden, true)) {
                $content['geolocation'] = $this->pointFromPlace($place);
            }

            return $content;
        }

        foreach ($this->placeBridgedAttributeKeys() as $field) {
            if (in_array($field, $this->hidden, true)) {
                continue;
            }

            if (array_key_exists((string) $field, $this->pending_place_bridged_scalars)) {
                $content[$field] = $this->pending_place_bridged_scalars[$field];
            }
        }

        if ($this->pending_host_geolocation !== null && ! in_array('geolocation', $this->hidden, true)) {
            $content['geolocation'] = $this->pending_host_geolocation;
        }

        return $content;
    }

    protected function shouldReadGeographyFromPlace(string $key): bool
    {
        if (! in_array($key, $this->placeBridgedAttributeKeys(), true)) {
            return false;
        }

        return ! (($this->attributes['place_id'] ?? null) === null);
    }

    /**
     * @return array<int, string>
     */
    protected function placeBridgedAttributeKeys(): array
    {
        return ['address', 'city', 'province', 'country', 'postcode', 'zone', 'latitude', 'longitude'];
    }

    protected function resolvePlace(): ?Place
    {
        // BelongsTo skips the query when the FK is null and caches via setRelation.
        return $this->place;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPlaceRowFromHostAttributes(): array
    {
        $row = [
            'address' => $this->resolveBridgedScalarForPlaceRow('address'),
            'city' => $this->resolveBridgedScalarForPlaceRow('city'),
            'province' => $this->resolveBridgedScalarForPlaceRow('province'),
            'country' => $this->resolveBridgedScalarForPlaceRow('country'),
            'postcode' => $this->resolveBridgedScalarForPlaceRow('postcode'),
            'zone' => $this->resolveBridgedScalarForPlaceRow('zone'),
        ];

        $lat = $this->decimalLatitudeFromHost();

        if ($lat !== null) {
            $row['latitude'] = $lat;
        }

        $lng = $this->decimalLongitudeFromHost();

        if ($lng !== null) {
            $row['longitude'] = $lng;
        }

        $point = $this->resolvePointForPlaceRow();

        if ($point instanceof Point && $this->shouldPersistPlaceGeolocationGeometry()) {
            $row['geolocation'] = $point;
        }

        return $row;
    }

    /**
     * Geometry bindings (e.g. ST_GeomFromText) are not portable to all test drivers (SQLite).
     * Oracle and Db2 are excluded too: they have no spatial column here, so geolocation stays off.
     */
    protected function shouldPersistPlaceGeolocationGeometry(): bool
    {
        return in_array($this->getConnection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /**
     * Persist canonical geography on {@see Place} from the current bridged state.
     */
    protected function syncGeographyToRelatedPlace(): void
    {
        $place_id = $this->attributes['place_id'] ?? null;

        if ($place_id === null) {
            return;
        }

        $place = Place::query()->find($place_id);

        if (! $place instanceof Place) {
            return;
        }

        $payload = $this->buildPlaceRowFromHostAttributes();

        foreach (['latitude', 'longitude'] as $coord) {
            if (($payload[$coord] ?? null) === null) {
                unset($payload[$coord]);
            }
        }

        $place->fill($payload);
        $place->save();

        if ($this->relationLoaded('place')) {
            $this->setRelation('place', $place->fresh());
        }
    }

    protected function decimalLatitudeFromHost(): ?float
    {
        $point = $this->resolvePointForPlaceRow();

        if ($point instanceof Point) {
            return $point->latitude;
        }

        if (! (($this->attributes['place_id'] ?? null) === null)) {
            $place = $this->resolvePlace();

            if ($place !== null && $place->latitude !== null) {
                return (float) $place->latitude;
            }
        }

        return null;
    }

    protected function decimalLongitudeFromHost(): ?float
    {
        $point = $this->resolvePointForPlaceRow();

        if ($point instanceof Point) {
            return $point->longitude;
        }

        if (! (($this->attributes['place_id'] ?? null) === null)) {
            $place = $this->resolvePlace();

            if ($place !== null && $place->longitude !== null) {
                return (float) $place->longitude;
            }
        }

        return null;
    }

    protected function clear_pending_place_payload(): void
    {
        $this->pending_place_bridged_scalars = [];
        $this->pending_host_geolocation = null;
    }

    /**
     * @return $this
     */
    private function setGeolocationThroughPlace(mixed $value): self
    {
        if ($value !== null && ! $value instanceof Point) {
            throw new TypeError(sprintf('Geolocation must be null or an instance of %s.', Point::class));
        }

        if (($this->attributes['place_id'] ?? null) === null) {
            $this->pending_host_geolocation = $value;

            return $this;
        }

        $place = $this->resolvePlace();

        if (! $place instanceof Place) {
            return $this;
        }

        $place->geolocation = $value;
        $place->save();

        if ($this->relationLoaded('place')) {
            $this->setRelation('place', $place->fresh());
        }

        return $this;
    }

    private function resolveBridgedScalarForPlaceRow(string $key): ?string
    {
        if (! (($this->attributes['place_id'] ?? null) === null)) {
            $place = $this->resolvePlace();

            if ($place instanceof Place) {
                $v = $place->getAttribute($key);

                return $v !== null ? (string) $v : null;
            }
        }

        $v = $this->pending_place_bridged_scalars[$key] ?? null;

        if ($v === null) {
            return null;
        }

        return is_scalar($v) ? (string) $v : null;
    }

    private function resolvePointForPlaceRow(): ?Point
    {
        if ($this->pending_host_geolocation instanceof Point) {
            return $this->pending_host_geolocation;
        }

        if (! (($this->attributes['place_id'] ?? null) === null)) {
            $place = $this->resolvePlace();

            return $this->pointFromPlace($place);
        }

        return null;
    }

    /**
     * When geometry column is not populated (e.g. SQLite tests), fall back to decimals.
     */
    private function pointFromPlace(?Place $place): ?Point
    {
        if (! $place instanceof Place) {
            return null;
        }

        if ($place->geolocation instanceof Point) {
            return $place->geolocation;
        }

        if ($place->latitude !== null && $place->longitude !== null) {
            return new Point((float) $place->latitude, (float) $place->longitude);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extraPlaceAttributesPendingForValidation(): array
    {
        $extra = [];

        foreach ($this->placeBridgedAttributeKeys() as $key) {
            if (array_key_exists((string) $key, $this->pending_place_bridged_scalars)) {
                $extra[$key] = $this->pending_place_bridged_scalars[$key];
            }
        }

        if ($this->pending_host_geolocation !== null) {
            $extra['geolocation'] = $this->pending_host_geolocation;
        }

        return $extra;
    }
}
