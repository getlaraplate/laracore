<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;

/**
 * The one driver-specific piece of SQL of the aggregate: "truncate a timestamp to its day".
 *
 * A time bucket is cut by grouping on this expression and rolling the days up in PHP, so no other function
 * of the engine depends on the database. Only the sqlite expression runs in CI; the others are checked as
 * strings and against a real server at release.
 */
final class DayTruncation
{
    /**
     * The day expression of each driver, around the wrapped column.
     */
    private const array EXPRESSIONS = [
        'sqlite' => 'date(%s)',
        'mysql' => 'DATE(%s)',
        'mariadb' => 'DATE(%s)',
        'pgsql' => 'CAST(%s AS date)',
        'sqlsrv' => 'CAST(%s AS date)',
        'oracle' => 'TRUNC(%s)',
        Db2Drivers::FAMILY => 'DATE(%s)',
    ];

    /**
     * Whether the driver has a day expression, so that a time bucket can be refused before any query runs.
     *
     * @param  string  $driver  The name of the connection driver, as `Connection::getDriverName()` gives it.
     */
    public static function supports(string $driver): bool
    {
        return isset(self::EXPRESSIONS[Db2Drivers::family($driver)]);
    }

    /**
     * The expression that gives the day of a column, for the driver of the connection.
     *
     * @param  string  $driver  The name of the connection driver, as `Connection::getDriverName()` gives it.
     * @param  string  $wrappedColumn  A column already wrapped by the grammar of the connection, never request text.
     *
     * @throws AggregateException When the driver has no expression ({@see self::unsupported()}).
     */
    public static function expression(string $driver, string $wrappedColumn): string
    {
        if (! self::supports($driver)) {
            throw self::unsupported($driver);
        }

        return sprintf(self::EXPRESSIONS[Db2Drivers::family($driver)], $wrappedColumn);
    }

    /**
     * The refusal of a time bucket on a driver with no day expression: a fault of the deployment, not of the
     * request, so it is reported. The message names the driver, never a column.
     */
    public static function unsupported(string $driver): AggregateException
    {
        return new AggregateException(
            AggregateErrorCode::UnsupportedFunction,
            sprintf('Time buckets are not supported on the "%s" database driver.', $driver),
            'group_by.bucket',
            reportable: true,
        );
    }
}
