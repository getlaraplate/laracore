<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Closure;
use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Names the vector profile an index document must be built for.
 *
 * Outside {@see self::using()} it is the profile of the bound {@see IVectorProfileProvider}.
 * Inside it the override wins as a whole (key, dimensions, similarity), so a switch can index
 * the incoming profile's vectors while the active one is still the previous one.
 */
final class VectorProfileContext
{
    private static ?VectorProfile $override = null;

    /**
     * Profile in force, or null when none is available.
     */
    public static function get(): ?VectorProfile
    {
        return self::$override ?? resolve(IVectorProfileProvider::class)->current();
    }

    /**
     * Model key of the profile in force, or null when there is none (all rows).
     */
    public static function modelKey(): ?string
    {
        return self::get()?->modelKey;
    }

    /**
     * Run the callback with the given profile as the override, restoring the previous
     * override afterwards, also when the callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function using(VectorProfile $profile, Closure $callback): mixed
    {
        $previous = self::$override;
        self::$override = $profile;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }
}
