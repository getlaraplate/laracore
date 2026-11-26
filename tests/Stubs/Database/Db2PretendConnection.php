<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Database;

use Closure;
use Easi\DB2\Database\DB2Connection;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use ReflectionProperty;
use Throwable;

/**
 * A Db2 connection that never opens a PDO, for tests that only check the SQL it builds.
 *
 * Laravel's `pretend()` returns from every query callback before the PDO is touched, so the closure below is
 * never called by a pretended query and throws if anything else reaches for it.
 */
final class Db2PretendConnection
{
    private const string CONNECTION_NAME = 'db2_pretend';

    /**
     * The connection mirrors the options of the application's `db2` connection that change the SQL it compiles.
     */
    public static function make(string $driver = 'db2_ibmi_odbc'): DB2Connection
    {
        return new DB2Connection(
            static fn (): PDO => throw new LogicException('No PDO in a pretend connection'),
            '',
            '',
            [
                'driver' => $driver,
                'name' => self::CONNECTION_NAME,
                'schema' => 'laraplate',
                'prefix' => '',
                'quote_reserved_words' => true,
                'ccsid' => config('database.connections.db2.ccsid'),
            ],
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

    /**
     * Run the callback as a migration wrapped in a transaction, and return the SQL it produced.
     *
     * Like {@see capture()}, but the callback runs at transaction level 1, as `Migrator` runs a migration when the
     * grammar supports schema transactions, so `afterCommit()` callbacks are deferred until the callback returns
     * and then fired, in order, as the commit fires them. The transaction is simulated, never opened: the PDO is not
     * touched. A plain {@see DatabaseTransactionsManager} is installed on the connection, so the test's own
     * transaction manager does not change when the callbacks run. When the callback throws, the rollback callbacks
     * run instead and the exception is rethrown.
     *
     * @return list<string>
     */
    public static function captureMigration(Closure $callback, string $driver = 'db2_ibmi_odbc'): array
    {
        return self::capture(static function () use ($callback): void {
            $connection = DB::connection(self::CONNECTION_NAME);
            $manager = new DatabaseTransactionsManager;
            $connection->setTransactionManager($manager);
            $name = $connection->getName();

            self::setTransactionLevel($connection, 1);
            $manager->begin($name, 1);

            try {
                $callback();
            } catch (Throwable $throwable) {
                self::setTransactionLevel($connection, 0);
                $manager->rollback($name, 0);

                throw $throwable;
            }

            self::setTransactionLevel($connection, 0);
            $manager->commit($name, 1, 0);
        }, $driver);
    }

    /**
     * Set the connection's transaction level without opening or committing a transaction on the PDO.
     */
    private static function setTransactionLevel(Connection $connection, int $level): void
    {
        new ReflectionProperty(Connection::class, 'transactions')->setValue($connection, $level);
    }
}
