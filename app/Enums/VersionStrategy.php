<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * How a version row stores its change: only the changed columns, or the whole image.
 *
 * Derived from overtrue/laravel-versionable (MIT), see NOTICES/laravel-versionable.md.
 * The case names are kept as in the library so that existing call sites only change their import.
 */
enum VersionStrategy: string
{
    case DIFF = 'DIFF';
    case SNAPSHOT = 'SNAPSHOT';

    /**
     * The strategy a `versioning.strategy.{table}` setting holds, or `false` when versioning is off: `false`, and an
     * empty value (`null`, `''`, which a save of the settings page used to leave), read as off instead of failing on
     * every write of the model. Any other value must be a strategy.
     *
     * @throws \ValueError when the value is no strategy
     */
    public static function fromSetting(mixed $value): self|false
    {
        return match (true) {
            $value instanceof self => $value,
            $value === false, $value === null, $value === '' => false,
            default => self::from(is_scalar($value) ? (string) $value : ''),
        };
    }
}
