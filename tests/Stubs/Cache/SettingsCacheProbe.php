<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Enums\CoreTables;

/**
 * Reads and seeds the settings caches of the resolver in the label index, under the label they are stored with.
 */
final class SettingsCacheProbe
{
    public static function has(string $key): bool
    {
        return self::index()->lookup($key, self::dependencies())->hit;
    }

    public static function get(string $key): mixed
    {
        return self::index()->lookup($key, self::dependencies())->value;
    }

    public static function put(string $key, mixed $value): void
    {
        $index = self::index();
        $index->store($index->lookup($key, self::dependencies()), $value, 3600);
    }

    private static function index(): ICacheLabelIndex
    {
        return resolve(ICacheLabelIndex::class);
    }

    private static function dependencies(): CacheDependencies
    {
        return new CacheDependencies([CacheLabel::table(CoreTables::Settings->value)]);
    }
}
