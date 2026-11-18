<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Reads, from the schema, which foreign keys delete the rows of a table by cascade: the ones on the table itself and
 * the chains of them through other tables. A self-reference (a parent column) and a closure table are not owners.
 */
final class CascadingForeignKeys
{
    private function __construct() {}

    /**
     * The table's own keys that cascade from another table, as "table.column" (a composite key lists its columns
     * joined by a comma).
     *
     * @return list<string>
     */
    public static function directOf(string $table): array
    {
        $keys = [];

        foreach (self::cascadingKeys($table) as $foreign_key) {
            $keys[] = $table . '.' . implode(',', $foreign_key['columns']);
        }

        sort($keys);

        return $keys;
    }

    /**
     * Every table whose row delete reaches the table's rows through a chain of cascading foreign keys.
     *
     * @return list<string>
     */
    public static function ownerTablesOf(string $table): array
    {
        $owners = [];
        $queue = [$table];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach (self::cascadingKeys($current) as $foreign_key) {
                $owner = $foreign_key['foreign_table'];

                if ($owner === $table || in_array($owner, $owners, true)) {
                    continue;
                }

                $owners[] = $owner;
                $queue[] = $owner;
            }
        }

        sort($owners);

        return $owners;
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
