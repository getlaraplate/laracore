<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Services\Crud\Aggregate\AggregateLimits;
use Modules\Core\Services\Crud\Aggregate\AggregateQuery;
use Modules\Core\Services\Crud\Aggregate\AggregateResult;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Tests\Stubs\Crud\AggregateCustomerStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateOrderStubModel;
use Modules\Core\Tests\Support\AggregateTestKit;

beforeEach(function (): void {
    // Five measures, so that one request reads every function of a column.
    config(['core.aggregate.max_measures' => 5]);
    AggregateTestKit::createTables();
    AggregateTestKit::asSuperadmin();
});

/**
 * The figures of a request with a period over the orders the logged-in user may see.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_buckets(array $request, ?FiltersGroup $filters = null, ?Model $model = null): AggregateResult
{
    return app(CrudService::class)->aggregate(
        AggregateTestKit::listData($model ?? new AggregateOrderStubModel, $filters),
        AggregateQuery::fromArray($request, AggregateLimits::fromConfig()),
    );
}

/**
 * The refusal of a request with a period the service must not run.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_buckets_refusal(array $request, ?Model $model = null): AggregateException
{
    try {
        aggregate_buckets($request, model: $model);
    } catch (AggregateException $exception) {
        return $exception;
    }

    throw new RuntimeException('The aggregate request was not refused.');
}

/**
 * The logged queries that read the orders, to tell a refusal made before any query from one made after.
 *
 * @return list<string>
 */
function aggregate_buckets_order_reads(?string $connection = null): array
{
    return array_values(array_filter(
        array_column(DB::connection($connection)->getQueryLog(), 'query'),
        static fn (string $sql): bool => str_contains($sql, 'from "aggregate_orders"'),
    ));
}

/**
 * The orders read through a connection that shares the test database but names a driver the aggregate has no day
 * expression for.
 */
function aggregate_buckets_orders_on_driver(string $driver): AggregateOrderStubModel
{
    config(['database.connections.aggregate_other_driver' => ['driver' => $driver, 'database' => ':memory:', 'prefix' => '']]);
    DB::extend('aggregate_other_driver', static fn (array $config): SQLiteConnection => new SQLiteConnection(DB::connection()->getPdo(), ':memory:', '', $config));

    $order = new AggregateOrderStubModel;
    $order->setConnection('aggregate_other_driver');

    return $order;
}

/**
 * Insert an order whose date is stored as the given text, past the cast of the model.
 */
function aggregate_buckets_raw_order(string $ordered_at): void
{
    AggregateOrderStubModel::query()->insert(['status' => 'new', 'quantity' => 1, 'ordered_at' => $ordered_at, 'is_paid' => false]);
}

it('fills empty buckets', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-10 09:00:00', 'total' => '10'],
        ['ordered_at' => '2026-01-20 15:00:00', 'total' => '20'],
        ['ordered_at' => '2026-03-05 11:00:00', 'total' => '7.5'],
    ]);

    $result = aggregate_buckets([
        'measures' => [
            ['fn' => 'count'],
            ['fn' => 'sum', 'field' => 'total'],
            ['fn' => 'avg', 'field' => 'total'],
            ['fn' => 'min', 'field' => 'total'],
            ['fn' => 'max', 'field' => 'total'],
        ],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-03-31'],
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 2, 'sum_total' => 30, 'avg_total' => 15.0, 'min_total' => 10, 'max_total' => 20]],
        ['key' => '2026-02-01', 'label' => null, 'values' => ['count' => 0, 'sum_total' => 0, 'avg_total' => null, 'min_total' => null, 'max_total' => null]],
        ['key' => '2026-03-01', 'label' => null, 'values' => ['count' => 1, 'sum_total' => 7.5, 'avg_total' => 7.5, 'min_total' => 7.5, 'max_total' => 7.5]],
    ]);
});

it('rolls days into Monday weeks', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-02-28 10:00:00'],
        ['ordered_at' => '2026-03-01 10:00:00'],
        ['ordered_at' => '2026-03-02 10:00:00'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'week'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-02-23', 'to' => '2026-03-08'],
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-02-23', 'label' => null, 'values' => ['count' => 2]],
        ['key' => '2026-03-02', 'label' => null, 'values' => ['count' => 1]],
    ]);
});

it('rolls days into months, quarters and years', function (string $bucket, array $series): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2025-11-10 10:00:00', 'quantity' => 1],
        ['ordered_at' => '2025-12-31 18:00:00', 'quantity' => 2],
        ['ordered_at' => '2026-01-01 10:00:00', 'quantity' => 3],
        ['ordered_at' => '2026-02-15 12:00:00', 'quantity' => 4],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'quantity']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => $bucket],
        'period' => ['field' => 'ordered_at', 'from' => '2025-11-01', 'to' => '2026-02-28'],
    ]);

    expect($result->series)->toBe($series);
})->with([
    'month' => ['month', [
        ['key' => '2025-11-01', 'label' => null, 'values' => ['count' => 1, 'sum_quantity' => 1]],
        ['key' => '2025-12-01', 'label' => null, 'values' => ['count' => 1, 'sum_quantity' => 2]],
        ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 1, 'sum_quantity' => 3]],
        ['key' => '2026-02-01', 'label' => null, 'values' => ['count' => 1, 'sum_quantity' => 4]],
    ]],
    'quarter' => ['quarter', [
        ['key' => '2025-10-01', 'label' => null, 'values' => ['count' => 2, 'sum_quantity' => 3]],
        ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 2, 'sum_quantity' => 7]],
    ]],
    'year' => ['year', [
        ['key' => '2025-01-01', 'label' => null, 'values' => ['count' => 2, 'sum_quantity' => 3]],
        ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 2, 'sum_quantity' => 7]],
    ]],
]);

it('weights the average by count', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-05 10:00:00', 'total' => '10'],
        ['ordered_at' => '2026-01-06 09:00:00', 'total' => '20'],
        ['ordered_at' => '2026-01-06 12:00:00', 'total' => '20'],
        ['ordered_at' => '2026-01-06 15:00:00', 'total' => '20'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'avg', 'field' => 'total']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ]);

    expect($result->series)->toBe([['key' => '2026-01-01', 'label' => null, 'values' => ['avg_total' => 17.5]]]);
});

it('min and max over days', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-05 09:00:00', 'total' => '12.5'],
        ['ordered_at' => '2026-01-05 14:00:00', 'total' => '30'],
        ['ordered_at' => '2026-01-20 10:00:00', 'total' => '7.25'],
        ['ordered_at' => '2026-01-20 16:00:00', 'total' => '18'],
    ]);

    $result = aggregate_buckets([
        'measures' => [
            ['fn' => 'min', 'field' => 'total'],
            ['fn' => 'max', 'field' => 'total'],
            ['fn' => 'min', 'field' => 'ordered_at'],
            ['fn' => 'max', 'field' => 'ordered_at'],
        ],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ]);

    expect($result->series)->toBe([[
        'key' => '2026-01-01',
        'label' => null,
        'values' => [
            'min_total' => 7.25,
            'max_total' => 30,
            'min_ordered_at' => '2026-01-05 09:00:00',
            'max_ordered_at' => '2026-01-20 16:00:00',
        ],
    ]]);
});

it('series are chronological by default and key is the bucket start date', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-14 10:00:00'],
        ['ordered_at' => '2026-01-02 10:00:00'],
        ['ordered_at' => '2026-01-06 10:00:00'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'week'],
        'period' => ['field' => 'ordered_at', 'from' => '2025-12-29', 'to' => '2026-01-18'],
    ]);

    expect($result->series)->toBe([
        ['key' => '2025-12-29', 'label' => null, 'values' => ['count' => 1]],
        ['key' => '2026-01-05', 'label' => null, 'values' => ['count' => 1]],
        ['key' => '2026-01-12', 'label' => null, 'values' => ['count' => 1]],
    ]);
});

it('a bucketed series follows the sort', function (array $sort, array $keys): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-06 10:00:00'],
        ['ordered_at' => '2026-01-07 10:00:00'],
        ['ordered_at' => '2026-01-06 11:00:00'],
        ['ordered_at' => '2026-01-05 10:00:00'],
        ['ordered_at' => '2026-01-07 11:00:00'],
        ['ordered_at' => '2026-01-06 12:00:00'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'day'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-05', 'to' => '2026-01-08'],
        'sort' => $sort,
    ]);

    expect(array_column($result->series, 'key'))->toBe($keys);
})->with([
    'by key, descending' => [['by' => 'key', 'dir' => 'desc'], ['2026-01-08', '2026-01-07', '2026-01-06', '2026-01-05']],
    'by measure, descending' => [['by' => 'measure', 'dir' => 'desc'], ['2026-01-06', '2026-01-07', '2026-01-05', '2026-01-08']],
    'by measure, ascending' => [['by' => 'measure', 'dir' => 'asc'], ['2026-01-08', '2026-01-05', '2026-01-07', '2026-01-06']],
]);

it('the period limits the rows', function (array $group, array $series): void {
    $rossi = AggregateCustomerStubModel::query()->create(['name' => 'Rossi', 'country' => 'IT']);
    $dupont = AggregateCustomerStubModel::query()->create(['name' => 'Dupont', 'country' => 'FR']);
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-09 18:00:00', 'status' => 'paid', 'customer_id' => $rossi->id],
        ['ordered_at' => '2026-01-10 08:00:00', 'status' => 'paid', 'customer_id' => $rossi->id],
        ['ordered_at' => '2026-01-15 12:00:00', 'status' => 'new', 'customer_id' => $dupont->id],
        ['ordered_at' => '2026-01-20 21:30:00', 'status' => 'paid', 'customer_id' => $rossi->id],
        ['ordered_at' => '2026-01-21 06:00:00', 'status' => 'new', 'customer_id' => $dupont->id],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-10', 'to' => '2026-01-20'],
        ...$group,
    ]);

    expect($result->series)->toBe($series)
        ->and($result->total)->toBe(['count' => 3])
        ->and($result->meta['row_count'])->toBe(3);
})->with([
    'no group' => [[], []],
    'a column group' => [['group_by' => ['field' => 'status']], [
        ['key' => 'paid', 'label' => null, 'values' => ['count' => 2]],
        ['key' => 'new', 'label' => null, 'values' => ['count' => 1]],
    ]],
    'a relation column group' => [['group_by' => ['field' => 'customer.country']], [
        ['key' => 'IT', 'label' => 'IT', 'values' => ['count' => 2]],
        ['key' => 'FR', 'label' => 'FR', 'values' => ['count' => 1]],
    ]],
    'a month bucket' => [['group_by' => ['field' => 'ordered_at', 'bucket' => 'month']], [
        ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 3]],
    ]],
]);

it('the period ends at the midnight after its last day across the spring change of the clock', function (string $to, int $count): void {
    config(['app.timezone' => 'Europe/Rome']);
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-03-27 23:59:59'],
        ['ordered_at' => '2026-03-28 00:00:00'],
        ['ordered_at' => '2026-03-28 23:59:59'],
        ['ordered_at' => '2026-03-29 00:00:00'],
        ['ordered_at' => '2026-03-29 23:59:59'],
        ['ordered_at' => '2026-03-30 00:00:00'],
    ]);

    $result = aggregate_buckets(['measures' => [['fn' => 'count']], 'period' => ['field' => 'ordered_at', 'from' => '2026-03-28', 'to' => $to]]);

    expect($result->total)->toBe(['count' => $count]);
})->with([
    'a last day before the change ends at 2026-03-29 00:00:00' => ['2026-03-28', 2],
    'the day of the change, 23 hours long, ends at 2026-03-30 00:00:00' => ['2026-03-29', 4],
]);

it('a stored date the database cannot read as a day fails the request', function (): void {
    AggregateTestKit::seedOrders([['ordered_at' => '2026-01-10 10:00:00']]);
    aggregate_buckets_raw_order('2026-01-15T25:99:00');

    expect(fn (): AggregateResult => aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ]))->toThrow(UnexpectedValueException::class, 'the database gave null');
});

it('a day the database gives outside the period is left out of the series', function (): void {
    AggregateTestKit::seedOrders([['ordered_at' => '2026-01-10 10:00:00']]);
    aggregate_buckets_raw_order('2026-01-31 23:30:00-02:00');

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ]);

    expect($result->series)->toBe([['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 1]]])
        ->and($result->total)->toBe(['count' => 2]);
});

it('a period longer than max_period_days is PERIOD_TOO_LONG', function (): void {
    config(['core.aggregate.max_period_days' => 30]);
    AggregateTestKit::seedOrders([['ordered_at' => '2026-01-15 12:00:00']]);
    DB::enableQueryLog();

    $refusal = aggregate_buckets_refusal([
        'measures' => [['fn' => 'count']],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ]);

    expect($refusal->toArray())->toBe([
        'code' => 'PERIOD_TOO_LONG',
        'message' => 'The period has 31 days, more than 30: shorten it.',
        'field' => 'period',
    ])
        ->and(aggregate_buckets_order_reads())->toBe([]);
});

it('a bucket count above max_points is PERIOD_TOO_LONG', function (): void {
    config(['core.aggregate.max_points' => 400]);
    AggregateTestKit::seedOrders([['ordered_at' => '2026-01-15 12:00:00']]);
    DB::enableQueryLog();

    $refusal = aggregate_buckets_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'day'],
        'period' => ['field' => 'ordered_at', 'from' => '2025-01-01', 'to' => '2026-05-15'],
    ]);

    expect($refusal->toArray())->toBe([
        'code' => 'PERIOD_TOO_LONG',
        'message' => 'The period gives 500 day buckets, more than 400: use a coarser bucket, such as week.',
        'field' => 'group_by.bucket',
    ])
        ->and(aggregate_buckets_order_reads())->toBe([]);
});

it('a bucket on a driver with no day expression is UNSUPPORTED_FUNCTION before any query', function (): void {
    AggregateTestKit::seedOrders([['ordered_at' => '2026-01-15 12:00:00']]);
    $order = aggregate_buckets_orders_on_driver('firebird');
    DB::enableQueryLog();
    DB::connection('aggregate_other_driver')->enableQueryLog();

    $refusal = aggregate_buckets_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ], $order);

    expect($refusal->toArray())->toBe([
        'code' => 'UNSUPPORTED_FUNCTION',
        'message' => 'Time buckets are not supported on the "firebird" database driver.',
        'field' => 'group_by.bucket',
    ])
        ->and($refusal->reportable)->toBeTrue()
        ->and(aggregate_buckets_order_reads('aggregate_other_driver'))->toBe([])
        ->and(aggregate_buckets_order_reads())->toBe([]);
});

it('a previous period with more buckets than max_points is PERIOD_TOO_LONG', function (): void {
    config(['core.aggregate.max_points' => 30]);

    $refusal = aggregate_buckets_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'day'],
        'period' => ['field' => 'ordered_at', 'from' => '2025-02-01', 'to' => '2025-02-28'],
        'compare' => 'previous_period',
    ]);

    expect($refusal->toArray())->toBe([
        'code' => 'PERIOD_TOO_LONG',
        'message' => 'The previous period gives 31 day buckets, more than 30: use a coarser bucket, such as week.',
        'field' => 'group_by.bucket',
    ]);
});

it('a period or bucket field that is not temporal is INVALID_FIELD', function (array $request, string $field): void {
    $refusal = aggregate_buckets_refusal(['measures' => [['fn' => 'count']], ...$request]);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::InvalidField)
        ->and($refusal->field)->toBe($field);
})->with([
    'a numeric period field' => [['period' => ['field' => 'total', 'preset' => 'this_month']], 'period.field'],
    'a text period field' => [['period' => ['field' => 'status', 'preset' => 'this_month']], 'period.field'],
    'a text bucket field' => [[
        'group_by' => ['field' => 'status', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'preset' => 'this_month'],
    ], 'group_by.field'],
    'a relation bucket field' => [[
        'group_by' => ['field' => 'customer.name', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'preset' => 'this_month'],
    ], 'group_by.field'],
]);

it('an integer column cast as a timestamp is neither a date nor a number', function (array $request, array $error): void {
    $order = new AggregateOrderStubModel;
    $order->mergeCasts(['quantity' => 'timestamp']);

    expect(fn (): AggregateResult => aggregate_buckets($request, model: $order))
        ->toThrow(function (AggregateException $exception) use ($error): void {
            expect([$exception->errorCode->value, $exception->field])->toBe($error);
        });
})->with([
    'a period on it' => [
        ['measures' => [['fn' => 'count']], 'period' => ['field' => 'quantity', 'preset' => 'this_month']],
        ['INVALID_FIELD', 'period.field'],
    ],
    'a bucket on it' => [[
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'quantity', 'bucket' => 'month'],
        'period' => ['field' => 'quantity', 'preset' => 'this_month'],
    ], ['INVALID_FIELD', 'group_by.field']],
    'min of it' => [['measures' => [['fn' => 'min', 'field' => 'quantity']]], ['NOT_MEASURABLE', 'measures.0.field']],
    'max of it' => [['measures' => [['fn' => 'max', 'field' => 'quantity']]], ['NOT_MEASURABLE', 'measures.0.field']],
    'sum of it' => [['measures' => [['fn' => 'sum', 'field' => 'quantity']]], ['NOT_MEASURABLE', 'measures.0.field']],
]);

it('a bucket on a field other than the period field is INVALID_FIELD', function (): void {
    Schema::table('aggregate_orders', function (Blueprint $table): void {
        $table->dateTime('shipped_at')->nullable();
    });
    SchemaInspector::getInstance()->clearTable('aggregate_orders');

    $refusal = aggregate_buckets_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'shipped_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'preset' => 'this_month'],
    ]);

    expect($refusal->toArray())->toBe([
        'code' => 'INVALID_FIELD',
        'message' => 'A time bucket cuts the period: group by "ordered_at", the field of the period.',
        'field' => 'group_by.field',
    ]);
});

it('meta carries resolved_period and bucket', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'UTC'));
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-02-10 10:00:00'],
        ['ordered_at' => '2025-12-20 10:00:00'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'last' => ['n' => 3, 'unit' => 'month']],
    ]);

    expect($result->toArray())->toBe([
        'data' => [
            'series' => [
                ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 0]],
                ['key' => '2026-02-01', 'label' => null, 'values' => ['count' => 1]],
                ['key' => '2026-03-01', 'label' => null, 'values' => ['count' => 0]],
            ],
            'total' => ['count' => 1],
        ],
        'meta' => [
            'resolved_period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-03-20', 'timezone' => 'Europe/Rome'],
            'bucket' => 'month',
            'row_count' => 1,
            'group_count' => 3,
            'truncated' => false,
            'generated_at' => '2026-03-20T10:00:00Z',
        ],
    ]);
});

it('compare adds the previous period total', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:00:00', 'UTC'));
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2025-12-31 12:00:00', 'total' => '100'],
        ['ordered_at' => '2026-01-12 10:00:00', 'total' => '10'],
        ['ordered_at' => '2026-01-25 10:00:00', 'total' => '5.5'],
        ['ordered_at' => '2026-02-14 10:00:00', 'total' => '20'],
        ['ordered_at' => '2026-03-02 10:00:00', 'total' => '1000'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']],
        'period' => ['field' => 'ordered_at', 'preset' => 'last_month'],
        'compare' => 'previous_period',
    ]);

    expect($result->toArray())->toBe([
        'data' => [
            'series' => [],
            'total' => ['count' => 1, 'sum_total' => 20],
            'compare' => ['total' => ['count' => 2, 'sum_total' => 15.5]],
        ],
        'meta' => [
            'resolved_period' => ['field' => 'ordered_at', 'from' => '2026-02-01', 'to' => '2026-02-28', 'timezone' => 'Europe/Rome'],
            'row_count' => 1,
            'group_count' => 0,
            'truncated' => false,
            'generated_at' => '2026-03-10T09:00:00Z',
        ],
    ]);
});

it('compare adds a series aligned by position for a bucketed request', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-15 10:00:00'],
        ['ordered_at' => '2026-03-03 10:00:00'],
        ['ordered_at' => '2026-03-03 15:00:00'],
        ['ordered_at' => '2026-04-10 10:00:00'],
        ['ordered_at' => '2026-06-20 10:00:00'],
        ['ordered_at' => '2026-06-21 10:00:00'],
        ['ordered_at' => '2026-06-22 10:00:00'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-04-01', 'to' => '2026-06-30'],
        'compare' => 'previous_period',
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-04-01', 'label' => null, 'values' => ['count' => 1]],
        ['key' => '2026-05-01', 'label' => null, 'values' => ['count' => 0]],
        ['key' => '2026-06-01', 'label' => null, 'values' => ['count' => 3]],
    ])
        ->and($result->compare)->toBe([
            'total' => ['count' => 3],
            'series' => [
                ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 1]],
                ['key' => '2026-02-01', 'label' => null, 'values' => ['count' => 0]],
                ['key' => '2026-03-01', 'label' => null, 'values' => ['count' => 2]],
            ],
        ]);
});

it('compare.series stays chronological whatever the sort of the series', function (array $sort, array $keys): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-15 10:00:00'],
        ['ordered_at' => '2026-03-03 10:00:00'],
        ['ordered_at' => '2026-03-03 15:00:00'],
        ['ordered_at' => '2026-04-10 10:00:00'],
        ['ordered_at' => '2026-06-20 10:00:00'],
        ['ordered_at' => '2026-06-21 10:00:00'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-04-01', 'to' => '2026-06-30'],
        'sort' => $sort,
        'compare' => 'previous_period',
    ]);

    expect(array_column($result->series, 'key'))->toBe($keys)
        ->and($result->compare['series'] ?? null)->toBe([
            ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 1]],
            ['key' => '2026-02-01', 'label' => null, 'values' => ['count' => 0]],
            ['key' => '2026-03-01', 'label' => null, 'values' => ['count' => 2]],
        ]);
})->with([
    'by measure, descending' => [['by' => 'measure', 'dir' => 'desc'], ['2026-06-01', '2026-04-01', '2026-05-01']],
    'by key, descending' => [['by' => 'key', 'dir' => 'desc'], ['2026-06-01', '2026-05-01', '2026-04-01']],
]);

it('compare.series stays chronological when the previous period has fewer buckets', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-08 10:00:00'],
        ['ordered_at' => '2026-01-13 10:00:00'],
        ['ordered_at' => '2026-01-14 10:00:00'],
        ['ordered_at' => '2026-01-17 10:00:00'],
        ['ordered_at' => '2026-01-20 10:00:00'],
        ['ordered_at' => '2026-01-26 10:00:00'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'week'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-17', 'to' => '2026-01-26'],
        'sort' => ['by' => 'key', 'dir' => 'desc'],
        'compare' => 'previous_period',
    ]);

    expect(array_column($result->series, 'key'))->toBe(['2026-01-26', '2026-01-19', '2026-01-12'])
        ->and($result->compare['series'] ?? null)->toBe([
            ['key' => '2026-01-05', 'label' => null, 'values' => ['count' => 1]],
            ['key' => '2026-01-12', 'label' => null, 'values' => ['count' => 2]],
        ]);
});

it('compare without a bucket gives only a total', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-10 10:00:00', 'status' => 'paid'],
        ['ordered_at' => '2026-01-11 10:00:00', 'status' => 'new'],
        ['ordered_at' => '2026-02-10 10:00:00', 'status' => 'paid'],
        ['ordered_at' => '2026-02-11 10:00:00', 'status' => 'paid'],
        ['ordered_at' => '2026-02-12 10:00:00', 'status' => 'new'],
    ]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'status'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-02-01', 'to' => '2026-02-28'],
        'compare' => 'previous_period',
    ]);

    expect($result->series)->toBe([
        ['key' => 'paid', 'label' => null, 'values' => ['count' => 2]],
        ['key' => 'new', 'label' => null, 'values' => ['count' => 1]],
    ])
        ->and($result->compare)->toBe(['total' => ['count' => 2]]);
});

it('acl narrows the buckets', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-05 09:00:00', 'status' => 'paid'],
        ['ordered_at' => '2026-01-05 10:00:00', 'status' => 'new'],
        ['ordered_at' => '2026-01-05 11:00:00', 'status' => 'paid'],
        ['ordered_at' => '2026-01-06 10:00:00', 'status' => 'new'],
        ['ordered_at' => '2026-01-07 10:00:00', 'status' => 'paid'],
    ]);
    AggregateTestKit::asReader([AggregateOrderStubModel::class => new FiltersGroup([new Filter('status', 'paid')])]);

    $result = aggregate_buckets([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'day'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-05', 'to' => '2026-01-07'],
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-01-05', 'label' => null, 'values' => ['count' => 2]],
        ['key' => '2026-01-06', 'label' => null, 'values' => ['count' => 0]],
        ['key' => '2026-01-07', 'label' => null, 'values' => ['count' => 1]],
    ])
        ->and($result->total)->toBe(['count' => 3]);
});
