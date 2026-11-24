<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

/**
 * The only place label strings are built.
 */
final class CacheLabel
{
    private const string ROW_PREFIX = 'row:';

    private const string ROWS_PREFIX = 'rows:';

    private const string USER_PREFIX = 'user:';

    public static function table(string $table): string
    {
        return 'table:' . $table;
    }

    public static function row(string $table, int|string $id): string
    {
        return self::ROW_PREFIX . $table . ':' . $id;
    }

    /**
     * Carried by every entry that holds a row of the table, through a row label or a row table: a flush of the whole
     * table ({@see CacheLabelInvalidator::flushTable()}) reaches the entries row labels would otherwise keep.
     */
    public static function rows(string $table): string
    {
        return self::ROWS_PREFIX . $table;
    }

    public static function user(int|string $id): string
    {
        return self::USER_PREFIX . $id;
    }

    public static function role(int|string $id): string
    {
        return 'role:' . $id;
    }

    public static function all(): string
    {
        return 'crud';
    }

    /**
     * Carried by every entry stored through an index that can lose flushes during an outage ({@see FailoverLabelIndex}),
     * and flushed when that index recovers, so nothing stored before or during the outage is served afterwards.
     */
    public static function stored(): string
    {
        return 'stored';
    }

    public static function acl(): string
    {
        return 'acl';
    }

    public static function docs(): string
    {
        return 'docs';
    }

    public static function translations(): string
    {
        return 'translations';
    }

    public static function inspector(): string
    {
        return 'inspector';
    }

    public static function geocoding(): string
    {
        return 'geocoding';
    }

    /**
     * Model class discovery ({@see \Modules\Core\Helpers\HelpersCache}), flushed when migrations end.
     */
    public static function models(): string
    {
        return 'models';
    }

    /**
     * The entity, preset and presettable lists of {@see \Modules\Core\Services\DynamicContentsService}, flushed when
     * their metadata generation moves.
     */
    public static function dynamicContents(): string
    {
        return 'dynamic_contents';
    }

    public static function isRow(string $label): bool
    {
        return str_starts_with($label, self::ROW_PREFIX);
    }

    public static function isUser(string $label): bool
    {
        return str_starts_with($label, self::USER_PREFIX);
    }

    public static function isRows(string $label): bool
    {
        return str_starts_with($label, self::ROWS_PREFIX);
    }

    /**
     * The table label of a `rows:{table}` label, e.g. `rows:core_users` gives `table:core_users`.
     */
    public static function tableOfRows(string $label): string
    {
        return self::table(mb_substr($label, mb_strlen(self::ROWS_PREFIX)));
    }

    /**
     * The table label of a row label, e.g. `row:core_users:5` gives `table:core_users`.
     */
    public static function tableOfRow(string $label): string
    {
        return self::table(self::rowTable($label));
    }

    /**
     * The table name of a row label, e.g. `row:core_users:5` gives `core_users`.
     */
    public static function rowTable(string $label): string
    {
        $rest = mb_substr($label, mb_strlen(self::ROW_PREFIX));
        $separator = mb_strrpos($rest, ':');

        return $separator === false ? $rest : mb_substr($rest, 0, $separator);
    }

    /**
     * The labels an index with row labels stores an entry under: its labels, the labels found after the query (row
     * labels, and any other label the query revealed, such as the tables of `morphTo` targets), and `rows:{table}` of
     * every table it names in its row tables or row labels.
     *
     * @param  list<string>  $row_labels
     * @return list<string>
     */
    public static function storedUnder(CacheDependencies $dependencies, array $row_labels): array
    {
        $tables = $dependencies->row_tables;

        foreach ($row_labels as $row_label) {
            if (self::isRow($row_label)) {
                $tables[] = self::rowTable($row_label);
            }
        }

        return array_values(array_unique([
            ...$dependencies->labels,
            ...$row_labels,
            ...array_map(self::rows(...), $tables),
        ]));
    }
}
