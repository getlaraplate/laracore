<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Database;

use Closure;
use Easi\DB2\Database\DB2Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/**
 * A Db2 connection that never opens a PDO, for tests that only check the SQL it builds.
 *
 * Laravel's `pretend()` returns from every query callback before the PDO is touched, so the closure below is
 * never called by a pretended query and throws if anything else reaches for it.
 */
final class Db2PretendConnection
{
    private const string CONNECTION_NAME = 'db2_pretend';

    public static function make(string $driver = 'db2_ibmi_odbc'): DB2Connection
    {
        return new DB2Connection(
            static fn (): PDO => throw new LogicException('No PDO in a pretend connection'),
            '',
            '',
            ['driver' => $driver, 'schema' => 'laraplate', 'prefix' => '', 'quote_reserved_words' => true],
        );
    }

    /**
     * Run the callback against the pretend connection as the default one and return the SQL it produced.
     *
     * The connection is registered with the database manager under a private name that is made the default
     * for the callback; the previous default is restored even when the callback throws. The SQL is collected
     * before execution, so it keeps its `?` placeholders (Laravel substitutes the bindings in a pretend log).
     *
     * @return list<string>
     */
    public static function capture(Closure $callback, string $driver = 'db2_ibmi_odbc'): array
    {
        $connection = self::make($driver);
        $queries = [];

        $connection->beforeExecuting(static function (string $query) use (&$queries): void {
            $queries[] = $query;
        });
        $previous = DB::getDefaultConnection();

        config(['database.connections.' . self::CONNECTION_NAME => ['driver' => $driver]]);
        DB::extend(self::CONNECTION_NAME, static fn (): DB2Connection => $connection);
        DB::purge(self::CONNECTION_NAME);
        DB::setDefaultConnection(self::CONNECTION_NAME);

        try {
            $connection->pretend(static function () use ($callback): void {
                $callback();
            });
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge(self::CONNECTION_NAME);
        }

        return $queries;
    }
}
