<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Reads, from the schema, the chains of foreign keys that delete the rows of a table by cascade. A self-reference
 * (a parent column) and a closure table are not owners.
 */
final class CascadingForeignKeys
{
    private function __construct() {}

    /**
     * Every chain of cascading foreign keys that deletes the table's rows, from the owner table whose row delete
     * starts it: "owner -> table.column -> ... -> table.column", each hop naming the referencing table and its key
     * columns (a composite key joins them with a comma). A chain never passes twice through one table.
     *
     * @return list<string>
     */
    public static function pathsInto(string $table): array
    {
        $paths = self::chainsInto($table, [$table]);
        sort($paths);

        return $paths;
    }

    /**
     * Every table whose row delete reaches the table's rows through a chain of cascading foreign keys.
     *
     * @return list<string>
     */
    public static function ownerTablesOf(string $table): array
    {
        $owners = array_values(array_unique(array_map(
            static fn (string $path): string => explode(' -> ', $path, 2)[0],
            self::pathsInto($table),
        )));
        sort($owners);

        return $owners;
    }

    /**
     * @param  list<string>  $visited
     * @return list<string>
     */
    private static function chainsInto(string $table, array $visited): array
    {
        $chains = [];

        foreach (self::cascadingKeys($table) as $foreign_key) {
            $owner = $foreign_key['foreign_table'];

            if (in_array($owner, $visited, true)) {
                continue;
            }

            $hop = $table . '.' . implode(',', $foreign_key['columns']);
            $chains[] = $owner . ' -> ' . $hop;

            foreach (self::chainsInto($owner, [...$visited, $owner]) as $chain) {
                $chains[] = $chain . ' -> ' . $hop;
            }
        }

        return $chains;
    }

    /**
     * @return list<array{columns: list<string>, foreign_table: string}>
     */
    private static function cascadingKeys(string $table): array
    {
        $keys = [];

        foreach (Schema::getForeignKeys($table) as $foreign_key) {
            $foreign_table = (string) $foreign_key['foreign_table'];

            if (mb_strtolower((string) ($foreign_key['on_delete'] ?? '')) !== 'cascade'
                || $foreign_table === $table
                || str_ends_with($foreign_table, '_closure')) {
                continue;
            }

            $keys[] = [
                'columns' => array_values(array_map(strval(...), $foreign_key['columns'])),
                'foreign_table' => $foreign_table,
            ];
        }

        return $keys;
    }
}
