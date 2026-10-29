<?php

declare(strict_types=1);

namespace Modules\Core\Performance;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * Framework boot time: from LARAVEL_START to the instant every service provider has booted.
 *
 * Measuring up to the moment a command starts would count as boot whatever ran before it
 * in the same process: `db:seed` under `migrate:fresh --seed` starts after every migration.
 */
final class BootTime
{
    /**
     * Container key holding the microtime at which the application finished booting.
     */
    public const string BOOTED_AT_BINDING = 'core.booted_at';

    public static function recordBootedAt(Application $app): void
    {
        $app->booted(static function (Application $app): void {
            $app->instance(self::BOOTED_AT_BINDING, microtime(true));
        });
    }

    /**
     * @param  float|null  $startedAt  process start microtime; LARAVEL_START when null
     */
    public static function seconds(Container $container, ?float $startedAt = null): float
    {
        $startedAt ??= defined('LARAVEL_START') ? (float) LARAVEL_START : 0.0;

        if ($startedAt <= 0.0) {
            return 0.0;
        }

        $bootedAt = $container->bound(self::BOOTED_AT_BINDING)
            ? (float) $container->make(self::BOOTED_AT_BINDING)
            : microtime(true);

        return max(0.0, $bootedAt - $startedAt);
    }
}
