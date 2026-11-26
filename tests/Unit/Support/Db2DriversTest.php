<?php

declare(strict_types=1);

use Modules\Core\Support\Db2Drivers;

it('treats every Db2 driver as the db2 family', function (string $driver): void {
    expect(Db2Drivers::family($driver))->toBe('db2')
        ->and(Db2Drivers::is($driver))->toBeTrue();
})->with([
    'ibmi odbc' => ['db2_ibmi_odbc'],
    'ibmi ibm' => ['db2_ibmi_ibm'],
    'express-c odbc' => ['db2_expressc_odbc'],
]);

it('leaves other drivers unchanged', function (string $driver): void {
    expect(Db2Drivers::family($driver))->toBe($driver)
        ->and(Db2Drivers::is($driver))->toBeFalse()
        ->and(Db2Drivers::isIbmi($driver))->toBeFalse();
})->with([
    'pgsql' => ['pgsql'],
    'oracle' => ['oracle'],
    'z/os is out of scope' => ['db2_zos_odbc'],
]);

it('recognises only the IBM i drivers as IBM i', function (string $driver, bool $expected): void {
    expect(Db2Drivers::isIbmi($driver))->toBe($expected);
})->with([
    ['db2_ibmi_odbc', true],
    ['db2_ibmi_ibm', true],
    ['db2_expressc_odbc', false],
]);
