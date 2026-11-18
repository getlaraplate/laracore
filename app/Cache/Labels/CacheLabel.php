<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

/**
 * The only place label strings are built.
 */
final class CacheLabel
{
    private const string ROW_PREFIX = 'row:';

    public static function table(string $table): string
    {
        return 'table:' . $table;
    }

    public static function row(string $table, int|string $id): string
    {
        return self::ROW_PREFIX . $table . ':' . $id;
    }

    public static function user(int|string $id): string
    {
        return 'user:' . $id;
    }

    public static function role(int|string $id): string
    {
        return 'role:' . $id;
    }

    public static function all(): string
    {
        return 'crud';
    }

    public static function acl(): string
    {
        return 'acl';
    }

    public static function isRow(string $label): bool
    {
        return str_starts_with($label, self::ROW_PREFIX);
    }

    /**
     * The table label of a row label, e.g. `row:core_users:5` gives `table:core_users`.
     */
    public static function tableOfRow(string $label): string
    {
        $rest = mb_substr($label, mb_strlen(self::ROW_PREFIX));
        $separator = mb_strrpos($rest, ':');

        return self::table($separator === false ? $rest : mb_substr($rest, 0, $separator));
    }
}
