<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\ColumnKind;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Support\ColumnKinds;
use Modules\Core\Tests\Stubs\Crud\AggregateCustomerStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateOrderStubModel;
use Modules\Core\Tests\Support\AggregateTestKit;

beforeEach(function (): void {
    AggregateTestKit::createTables();
});

it('a cast decimal column is numeric whatever SQLite reports for it', function (): void {
    expect(ColumnKinds::of(new AggregateOrderStubModel, 'total'))->toBe(ColumnKind::Numeric);
});

it('an integer column is numeric', function (): void {
    expect(ColumnKinds::of(new AggregateOrderStubModel, 'quantity'))->toBe(ColumnKind::Numeric);
});

it('a datetime cast is temporal', function (): void {
    expect(ColumnKinds::of(new AggregateOrderStubModel, 'ordered_at'))->toBe(ColumnKind::Temporal);
});

it('a string and a boolean are other', function (): void {
    $order = new AggregateOrderStubModel;

    expect(ColumnKinds::of($order, 'status'))->toBe(ColumnKind::Other)
        ->and(ColumnKinds::of($order, 'is_paid'))->toBe(ColumnKind::Other);
});

it('the primary key and a foreign key are keys', function (): void {
    $order = new AggregateOrderStubModel;

    expect(ColumnKinds::isKey($order, 'id'))->toBeTrue()
        ->and(ColumnKinds::isKey($order, 'customer_id'))->toBeTrue()
        ->and(ColumnKinds::isKey($order, 'total'))->toBeFalse()
        ->and(ColumnKinds::isKey($order, 'status'))->toBeFalse()
        ->and(ColumnKinds::isKey(new AggregateCustomerStubModel, 'id'))->toBeTrue()
        ->and(ColumnKinds::isKey(new AggregateCustomerStubModel, 'country'))->toBeFalse();
});

it('every column of a composite key is a key', function (): void {
    $order = new AggregateOrderStubModel;
    new ReflectionProperty($order, 'primaryKey')->setValue($order, ['id', 'status']);

    expect(ColumnKinds::isKey($order, 'id'))->toBeTrue()
        ->and(ColumnKinds::isKey($order, 'status'))->toBeTrue()
        ->and(ColumnKinds::isKey($order, 'quantity'))->toBeFalse();
});

it('a numeric key still reports numeric from of() but isKey is true', function (): void {
    $order = new AggregateOrderStubModel;

    expect(ColumnKinds::of($order, 'id'))->toBe(ColumnKind::Numeric)
        ->and(ColumnKinds::isKey($order, 'id'))->toBeTrue()
        ->and(ColumnKinds::of($order, 'customer_id'))->toBe(ColumnKind::Numeric)
        ->and(ColumnKinds::isKey($order, 'customer_id'))->toBeTrue();
});

it('a column with no cast falls back to the schema type', function (): void {
    Schema::table('aggregate_orders', function (Blueprint $table): void {
        $table->float('weight')->nullable();
    });
    SchemaInspector::getInstance()->clearTable('aggregate_orders');

    expect(ColumnKinds::of(new AggregateOrderStubModel, 'weight'))->toBe(ColumnKind::Numeric);
});

it('maps the schema type of each column when its cast says nothing about the kind', function (): void {
    $order = new AggregateOrderStubModel;
    $order->mergeCasts(array_fill_keys(['total', 'quantity', 'ordered_at', 'is_paid'], 'string'));

    expect(ColumnKinds::of($order, 'total'))->toBe(ColumnKind::Numeric)
        ->and(ColumnKinds::of($order, 'quantity'))->toBe(ColumnKind::Numeric)
        ->and(ColumnKinds::of($order, 'ordered_at'))->toBe(ColumnKind::Temporal)
        ->and(ColumnKinds::of($order, 'is_paid'))->toBe(ColumnKind::Other)
        ->and(ColumnKinds::of($order, 'status'))->toBe(ColumnKind::Other)
        ->and(ColumnKinds::of($order, 'currency'))->toBe(ColumnKind::Other);
});

it('reads the cast before the schema type', function (string $cast, ColumnKind $kind): void {
    $order = new AggregateOrderStubModel;
    $order->mergeCasts(['status' => $cast]);

    expect(ColumnKinds::of($order, 'status'))->toBe($kind);
})->with([
    'int' => ['int', ColumnKind::Numeric],
    'integer' => ['integer', ColumnKind::Numeric],
    'float' => ['float', ColumnKind::Numeric],
    'double' => ['double', ColumnKind::Numeric],
    'real' => ['real', ColumnKind::Numeric],
    'decimal' => ['decimal', ColumnKind::Numeric],
    'decimal with scale' => ['decimal:2', ColumnKind::Numeric],
    'date' => ['date', ColumnKind::Temporal],
    'datetime' => ['datetime', ColumnKind::Temporal],
    'immutable date' => ['immutable_date', ColumnKind::Temporal],
    'immutable datetime' => ['immutable_datetime', ColumnKind::Temporal],
    'timestamp' => ['timestamp', ColumnKind::Temporal],
    'date with format' => ['date:Y-m-d', ColumnKind::Temporal],
    'datetime with format' => ['datetime:Y-m-d H:i', ColumnKind::Temporal],
    'immutable datetime with format' => ['immutable_datetime:Y-m-d', ColumnKind::Temporal],
    'string' => ['string', ColumnKind::Other],
    'boolean' => ['boolean', ColumnKind::Other],
    'array' => ['array', ColumnKind::Other],
]);

it('a temporal cast on a column of a numeric schema type is other', function (string $column, string $cast): void {
    $order = new AggregateOrderStubModel;
    $order->mergeCasts([$column => $cast]);

    expect(ColumnKinds::of($order, $column))->toBe(ColumnKind::Other);
})->with([
    'unix seconds cast as timestamp' => ['quantity', 'timestamp'],
    'an integer cast as datetime' => ['quantity', 'datetime'],
    'a decimal cast as immutable date' => ['total', 'immutable_date'],
]);

it('maps the declared type of a column by its name', function (string $declared, ColumnKind $kind): void {
    DB::statement(sprintf('create table aggregate_typed (probe %s)', $declared));
    SchemaInspector::getInstance()->clearTable('aggregate_typed');

    $model = new AggregateOrderStubModel;
    $model->setTable('aggregate_typed');

    expect(ColumnKinds::of($model, 'probe'))->toBe($kind);
})->with([
    'decimal with precision' => ['decimal(15,4)', ColumnKind::Numeric],
    'upper case decimal' => ['DECIMAL(15, 4)', ColumnKind::Numeric],
    'unsigned bigint' => ['bigint unsigned', ColumnKind::Numeric],
    'mediumint' => ['mediumint', ColumnKind::Numeric],
    'double precision' => ['double precision', ColumnKind::Numeric],
    'real' => ['real', ColumnKind::Numeric],
    'money' => ['money', ColumnKind::Numeric],
    'postgres int4' => ['int4', ColumnKind::Numeric],
    'postgres float8' => ['float8', ColumnKind::Numeric],
    'oracle number' => ['number', ColumnKind::Numeric],
    'oracle number with precision' => ['NUMBER(15,4)', ColumnKind::Numeric],
    'oracle binary_double' => ['binary_double', ColumnKind::Numeric],
    'oracle binary_float' => ['binary_float', ColumnKind::Numeric],
    'sql server smallmoney' => ['smallmoney', ColumnKind::Numeric],
    'date' => ['date', ColumnKind::Temporal],
    'datetime' => ['datetime', ColumnKind::Temporal],
    'sql server datetime2' => ['datetime2', ColumnKind::Temporal],
    'sql server datetime2 with precision' => ['datetime2(7)', ColumnKind::Temporal],
    'sql server smalldatetime' => ['smalldatetime', ColumnKind::Temporal],
    'sql server datetimeoffset' => ['datetimeoffset(7)', ColumnKind::Temporal],
    'timestamp' => ['timestamp', ColumnKind::Temporal],
    'timestamptz' => ['timestamptz', ColumnKind::Temporal],
    'timestamp with time zone' => ['timestamp with time zone', ColumnKind::Temporal],
    'timestamp without time zone' => ['timestamp without time zone', ColumnKind::Temporal],
    'a time of day is no date' => ['time', ColumnKind::Other],
    'tinyint(1)' => ['tinyint(1)', ColumnKind::Other],
    'boolean' => ['boolean', ColumnKind::Other],
    'varchar' => ['varchar(255)', ColumnKind::Other],
    'text' => ['text', ColumnKind::Other],
]);

it('exists is false for a name that is not a column and for an accessor', function (): void {
    $order = new AggregateOrderStubModel;

    expect(ColumnKinds::exists($order, 'total'))->toBeTrue()
        ->and(ColumnKinds::exists($order, 'does_not_exist'))->toBeFalse()
        ->and(ColumnKinds::exists($order, 'total_label'))->toBeFalse()
        ->and(ColumnKinds::exists($order, 'customer'))->toBeFalse();
});
