<?php

declare(strict_types=1);

namespace Modules\Core\Support;

/**
 * The Db2 driver names of the `easi-power/laravel-db2` fork, seen as one family.
 *
 * Laraplate branches on the database family, not on the transport (ODBC or the IBM extension) or the platform,
 * so every Db2 driver maps to the single family name `db2`. Db2 for z/OS is out of scope and is not a member.
 *
 * The `db2_expressc_odbc` driver (Db2 for LUW) is a member, but every Db2 arm in Laraplate emits Db2 for i SQL
 * (QSYS2 catalog, IBM i trigger and index syntax) until phase 2 adds what LUW needs.
 */
final class Db2Drivers
{
    public const string FAMILY = 'db2';

    /**
     * @var list<string>
     */
    public const array DRIVERS = ['db2_ibmi_odbc', 'db2_ibmi_ibm', 'db2_expressc_odbc'];

    /**
     * @var list<string>
     */
    private const array IBMI_DRIVERS = ['db2_ibmi_odbc', 'db2_ibmi_ibm'];

    public static function is(string $driver): bool
    {
        return in_array($driver, self::DRIVERS, true);
    }

    public static function isIbmi(string $driver): bool
    {
        return in_array($driver, self::IBMI_DRIVERS, true);
    }

    /**
     * The family name of a Db2 driver, any other driver unchanged.
     */
    public static function family(string $driver): string
    {
        return self::is($driver) ? self::FAMILY : $driver;
    }
}
