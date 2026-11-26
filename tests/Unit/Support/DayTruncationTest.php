<?php

declare(strict_types=1);

use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Support\DayTruncation;

it('builds the day expression of each driver', function (string $driver, string $expected): void {
    expect(DayTruncation::expression($driver, '"orders"."ordered_at"'))->toBe($expected)
        ->and(DayTruncation::supports($driver))->toBeTrue();
})->with([
    'sqlite' => ['sqlite', 'date("orders"."ordered_at")'],
    'mysql' => ['mysql', 'DATE("orders"."ordered_at")'],
    'mariadb' => ['mariadb', 'DATE("orders"."ordered_at")'],
    'pgsql' => ['pgsql', 'CAST("orders"."ordered_at" AS date)'],
    'sqlsrv' => ['sqlsrv', 'CAST("orders"."ordered_at" AS date)'],
    'oracle' => ['oracle', 'TRUNC("orders"."ordered_at")'],
    'db2_ibmi_odbc' => ['db2_ibmi_odbc', 'DATE("orders"."ordered_at")'],
    'db2_ibmi_ibm' => ['db2_ibmi_ibm', 'DATE("orders"."ordered_at")'],
    'db2_expressc_odbc' => ['db2_expressc_odbc', 'DATE("orders"."ordered_at")'],
]);

it('refuses a bucket on a driver it has no expression for', function (): void {
    expect(DayTruncation::supports('firebird'))->toBeFalse();

    expect(fn (): string => DayTruncation::expression('firebird', '"orders"."ordered_at"'))
        ->toThrow(static fn (AggregateException $exception) => expect($exception->errorCode)->toBe(AggregateErrorCode::UnsupportedFunction)
            ->and($exception->errorCode->value)->toBe('UNSUPPORTED_FUNCTION')
            ->and($exception->field)->toBe('group_by.bucket')
            ->and($exception->reportable)->toBeTrue()
            ->and($exception->getMessage())->toContain('firebird')->not->toContain('ordered_at'));
});
