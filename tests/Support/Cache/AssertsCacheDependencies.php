<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support\Cache;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use PHPUnit\Framework\Assert;

/**
 * Proves that the labels of a cached read cover every table the read actually queries.
 *
 * The tables are recorded from the SQL the call runs on one connection: every name that follows `from` or `join`,
 * quoted (`"t"`, `` `t` ``, `[t]`) or bare, schema-qualified or not, with the connection's table prefix removed so it
 * matches `Model::getTable()`. Names a `with` clause defines (the recursive expressions of a tree query) and table
 * functions (`from pragma_table_info(...)`) are not tables and are left out. A subquery is read like the rest of the
 * statement, so the tables of `exists (select ... from t)` count.
 *
 * A table is covered when the dependencies carry its `table:` label or list it among their row tables.
 */
trait AssertsCacheDependencies
{
    /**
     * @param  Closure(): mixed  $call  The read whose queries are recorded.
     * @param  string|null  $connection  The connection to listen on; the default connection when null.
     */
    public function assertCacheDependenciesCover(Closure $call, CacheDependencies $dependencies, ?string $connection = null): void
    {
        $uncovered = array_values(array_diff(self::tablesQueriedBy($call, $connection), self::coveredTables($dependencies)));

        Assert::assertSame(
            [],
            $uncovered,
            sprintf('The call queried tables its cache dependencies do not label: %s.', implode(', ', $uncovered)),
        );
    }

    /**
     * @param  Closure(): mixed  $call
     * @return list<string>
     */
    private static function tablesQueriedBy(Closure $call, ?string $connection): array
    {
        $database = app('db')->connection($connection);
        $connection_name = $database->getName();
        $prefix = $database->getTablePrefix();
        $statements = [];
        $recording = true;

        $database->listen(static function (QueryExecuted $query) use (&$statements, &$recording, $connection_name): void {
            if ($recording && $query->connectionName === $connection_name) {
                $statements[] = $query->sql;
            }
        });

        try {
            $call();
        } finally {
            $recording = false;
        }

        $tables = [];

        foreach ($statements as $sql) {
            array_push($tables, ...self::tablesOf($sql, $prefix));
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private static function tablesOf(string $sql, string $prefix): array
    {
        $identifier = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][\w$]*)';
        $qualified = $identifier . '(?:\s*\.\s*' . $identifier . ')*';

        preg_match_all('/\b(?:with(?:\s+recursive)?|,)\s*(' . $identifier . ')\s*(?:\([^()]*\))?\s+as\s*\(/i', $sql, $defined);
        $expressions = array_map(self::unquote(...), $defined[1]);

        preg_match_all('/\b(?:from|join)\s+(' . $qualified . ')(?!\s*\()/i', $sql, $named);
        $tables = [];

        foreach ($named[1] as $name) {
            $parts = preg_split('/\s*\.\s*/', $name) ?: [$name];
            $table = self::unquote((string) end($parts));

            if (in_array($table, $expressions, true)) {
                continue;
            }

            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = mb_substr($table, mb_strlen($prefix));
            }

            $tables[] = $table;
        }

        return $tables;
    }

    private static function unquote(string $identifier): string
    {
        return mb_trim($identifier, '"`[]');
    }

    /**
     * @return list<string>
     */
    private static function coveredTables(CacheDependencies $dependencies): array
    {
        $tables = $dependencies->row_tables;
        $prefix = CacheLabel::table('');

        foreach ($dependencies->labels as $label) {
            if (str_starts_with($label, $prefix)) {
                $tables[] = mb_substr($label, mb_strlen($prefix));
            }
        }

        return $tables;
    }
}
