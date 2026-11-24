<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Support\Carbon;

/**
 * Microsecond clock that follows Carbon test time.
 */
final class Clock
{
    public static function micros(): int
    {
        $now = Carbon::now();

        return $now->getTimestamp() * 1_000_000 + $now->micro;
    }
}
