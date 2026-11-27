<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Modules\Core\Models\Place;

/**
 * The suite runs on SQLite, where {@see Place} skips its geometry sync. These tests point the place at a
 * PostgreSQL connection that is never opened (building the point expression needs no PDO there) and run
 * the `saving` listeners without a write, which exercises the spatial path of the hook.
 */
beforeEach(function (): void {
    config()->set('database.connections.place-spatial', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'database' => 'unused',
        'username' => 'unused',
        'password' => 'unused',
    ]);
});

afterEach(function (): void {
    DB::purge('place-spatial');
});

/**
 * A place already stored with matching decimals and point, as read back from a spatial database.
 */
function storedSpatialPlace(float $latitude, float $longitude): Place
{
    $place = (new Place)->setConnection('place-spatial');
    $place->fill([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'geolocation' => new Point($latitude, $longitude),
    ]);
    $place->exists = true;
    $place->syncOriginal();

    return $place;
}

function runPlaceSavingListeners(Place $place): void
{
    Place::getEventDispatcher()->until('eloquent.saving: ' . Place::class, $place);
}

it('keeps a new point and aligns the decimals to it on a spatial driver', function (): void {
    $place = storedSpatialPlace(1.0, 2.0);

    $place->geolocation = new Point(45.4642, 9.19);
    runPlaceSavingListeners($place);

    expect($place->geolocation)->toBeInstanceOf(Point::class)
        ->and($place->geolocation->latitude)->toBe(45.4642)
        ->and($place->geolocation->longitude)->toBe(9.19)
        ->and($place->latitude)->toBe(45.4642)
        ->and($place->longitude)->toBe(9.19);
});

it('clears the decimals when the point is cleared on a spatial driver', function (): void {
    $place = storedSpatialPlace(1.0, 2.0);

    $place->geolocation = null;
    runPlaceSavingListeners($place);

    expect($place->geolocation)->toBeNull()
        ->and($place->latitude)->toBeNull()
        ->and($place->longitude)->toBeNull();
});

it('rebuilds the point from changed decimals on a spatial driver', function (): void {
    $place = storedSpatialPlace(1.0, 2.0);

    $place->latitude = 10.5;
    $place->longitude = 20.25;
    runPlaceSavingListeners($place);

    expect($place->geolocation)->toBeInstanceOf(Point::class)
        ->and($place->geolocation->latitude)->toBe(10.5)
        ->and($place->geolocation->longitude)->toBe(20.25);
});

it('rebuilds the point from the decimals when both change and disagree on a spatial driver', function (): void {
    $place = storedSpatialPlace(1.0, 2.0);

    $place->latitude = 10.5;
    $place->longitude = 20.25;
    $place->geolocation = new Point(45.4642, 9.19);
    runPlaceSavingListeners($place);

    expect($place->geolocation->latitude)->toBe(10.5)
        ->and($place->geolocation->longitude)->toBe(20.25)
        ->and($place->latitude)->toBe(10.5)
        ->and($place->longitude)->toBe(20.25);
});
