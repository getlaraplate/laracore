<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Closure;
use Modules\Core\Enums\VersionStrategy;

/**
 * The version strategy each model class resolved from its per-table setting, kept for the life of the process so
 * the setting is read once per class. It lives here and not in a static of HasVersions because PHP gives every
 * class that declares `use HasVersions` its own copy of a trait static: a flush on the trait cleared a copy no
 * model read, and User, Role and the other models that use the trait themselves kept a strategy a changed
 * setting had replaced, until the worker restarted.
 */
final class VersionStrategyCache
{
    /**
     * @var array<class-string, VersionStrategy|false>
     */
    private static array $strategies = [];

    /**
     * @param  class-string  $modelClass
     * @param  Closure(): (VersionStrategy|false)  $resolve
     */
    public static function remember(string $modelClass, Closure $resolve): VersionStrategy|false
    {
        if (! array_key_exists($modelClass, self::$strategies)) {
            self::$strategies[$modelClass] = $resolve();
        }

        return self::$strategies[$modelClass];
    }

    public static function flush(): void
    {
        self::$strategies = [];
    }
}
