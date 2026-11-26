<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Place;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasPlace;

final class PlaceAffinityModel extends Model
{
    use HasPlace;

    public function persistsSpatialGeometry(): bool
    {
        return $this->shouldPersistPlaceGeolocationGeometry();
    }

    /**
     * @return array<string, mixed>
     */
    public function placeRow(): array
    {
        return $this->buildPlaceRowFromHostAttributes();
    }
}
