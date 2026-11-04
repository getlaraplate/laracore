<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Services\Crud\Aggregate\AggregateLimits;
use Modules\Core\Services\Crud\Aggregate\AggregateQuery;
use Modules\Core\Services\Crud\Aggregate\AggregateResult;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Tests\Stubs\Crud\AggregateCustomerStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateHiddenCurrencyOrderStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateOrderStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateRestrictedOrderStubModel;
use Modules\Core\Tests\Support\AggregateTestKit;

beforeEach(function (): void {
    config(['core.aggregate.max_measures' => 5]);
    AggregateTestKit::createTables();
    AggregateTestKit::asSuperadmin();
});

/**
 * The figures of a request over the orders the logged-in user may see.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_currency_run(array $request, ?Model $model = null): AggregateResult
{
    return app(CrudService::class)->aggregate(
        AggregateTestKit::listData($model ?? new AggregateOrderStubModel),
        AggregateQuery::fromArray($request, AggregateLimits::fromConfig()),
    );
}

/**
 * The refusal of a request the service must not run.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_currency_refusal(array $request, Model $model): AggregateException
{
    try {
        aggregate_currency_run($request, $model);
    } catch (AggregateException $exception) {
        return $exception;
    }

    throw new RuntimeException('The aggregate request was not refused.');
}

/**
 * Insert orders straight into the table, for the rows whose columns the stub model does not fill (the extra
 * currency columns a test adds, or a table that has lost its `currency` column).
 *
 * @param  list<array<string, mixed>>  $rows
 */
function aggregate_currency_insert(array $rows): void
{
    foreach ($rows as $row) {
        DB::table('aggregate_orders')->insert($row + [
            'status' => 'new',
            'quantity' => 1,
            'ordered_at' => '2026-01-15 12:00:00',
            'is_paid' => false,
        ]);
    }
}

/**
 * Give the orders table a second amount with a currency column of its own, as an ERP movement keeps a document
 * amount and a local one.
 */
function aggregate_currency_add_local_amount(): void
{
    Schema::table('aggregate_orders', function (Blueprint $table): void {
        $table->decimal('amount_local', 15, 4)->nullable();
        $table->char('currency_local', 3)->nullable();
    });
    SchemaInspector::getInstance()->clearTable('aggregate_orders');
}

it('one currency puts it in meta.currency and formats nothing', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10.5', 'currency' => 'EUR'],
        ['total' => '20', 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['count' => 2, 'sum_total' => 30.5])
        ->and($result->meta['currency'])->toBe('EUR')
        ->and($result->meta)->not->toHaveKeys(['currencies', 'warnings']);
});

it('sums over two currencies give null and a warning', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => 'EUR'],
        ['total' => '20', 'currency' => 'USD'],
        ['total' => '5', 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['count' => 3, 'sum_total' => null])
        ->and($result->meta['warnings'])->toBe([[
            'code' => 'MIXED_CURRENCY',
            'field' => 'sum_total',
            'message' => '"sum_total" adds amounts in more than one currency, so it has no value: group by "currency" to get one value per currency.',
        ]])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD'])
        ->and($result->meta)->not->toHaveKey('currency');
});

it('every function over an amount is null when its rows mix currencies', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => 'EUR'],
        ['total' => '20', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run(['measures' => [
        ['fn' => 'sum', 'field' => 'total'],
        ['fn' => 'avg', 'field' => 'total'],
        ['fn' => 'min', 'field' => 'total'],
        ['fn' => 'max', 'field' => 'total'],
    ]]);

    expect($result->total)->toBe(['sum_total' => null, 'avg_total' => null, 'min_total' => null, 'max_total' => null])
        ->and(array_column($result->meta['warnings'], 'field'))->toBe(['sum_total', 'avg_total', 'min_total', 'max_total']);
});

it('a date or a key is no amount', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-10 10:00:00', 'currency' => 'EUR'],
        ['ordered_at' => '2026-02-10 10:00:00', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run(['measures' => [
        ['fn' => 'min', 'field' => 'ordered_at'],
        ['fn' => 'max', 'field' => 'id'],
    ]]);

    expect($result->total)->toBe(['min_ordered_at' => '2026-01-10 10:00:00', 'max_id' => 2])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('count is never affected', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => 'EUR'],
        ['total' => '20', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'count'], ['fn' => 'count', 'field' => 'total']]]);

    expect($result->total)->toBe(['count' => 2, 'count_total' => 2])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('a table with no currency column adds nothing to meta', function (): void {
    Schema::table('aggregate_orders', function (Blueprint $table): void {
        $table->dropColumn('currency');
    });
    SchemaInspector::getInstance()->clearTable('aggregate_orders');
    aggregate_currency_insert([['total' => '10'], ['total' => '20']]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => 30])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('rows with no currency are no currency', function (): void {
    AggregateTestKit::seedOrders([['total' => '10'], ['total' => '20']]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => 30])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('one currency beside rows with no currency is not mixed', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => 'EUR'],
        ['total' => '20'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => 30])
        ->and($result->meta['currency'])->toBe('EUR')
        ->and($result->meta)->not->toHaveKeys(['currencies', 'warnings']);
});

it('a blank currency is no currency and cannot hide a mixed cell', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => ''],
        ['total' => '20', 'currency' => 'EUR'],
        ['total' => '30', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => null])
        ->and(array_column($result->meta['warnings'], 'field'))->toBe(['sum_total'])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD'])
        ->and($result->meta)->not->toHaveKey('currency');
});

it('one currency beside blank currencies is not mixed', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => ''],
        ['total' => '20', 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => 30])
        ->and($result->meta['currency'])->toBe('EUR')
        ->and($result->meta)->not->toHaveKeys(['currencies', 'warnings']);
});

it('rows with only blank or null currencies have no currency', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => ''],
        ['total' => '20'],
        ['total' => '5', 'currency' => ''],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => 35])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('a blank currency is no currency in a group', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'new', 'total' => '10', 'currency' => ''],
        ['status' => 'new', 'total' => '20', 'currency' => 'EUR'],
        ['status' => 'paid', 'total' => '1', 'currency' => ''],
        ['status' => 'paid', 'total' => '2', 'currency' => 'EUR'],
        ['status' => 'paid', 'total' => '3', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'status'],
        'sort' => ['by' => 'key', 'dir' => 'asc'],
    ]);

    expect($result->series)->toBe([
        ['key' => 'new', 'label' => null, 'values' => ['sum_total' => 30]],
        ['key' => 'paid', 'label' => null, 'values' => ['sum_total' => null]],
    ])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD']);
});

it('a blank currency is no currency in a bucket', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-05 10:00:00', 'total' => '10', 'currency' => ''],
        ['ordered_at' => '2026-01-20 10:00:00', 'total' => '20', 'currency' => 'EUR'],
        ['ordered_at' => '2026-02-03 10:00:00', 'total' => '1', 'currency' => ''],
        ['ordered_at' => '2026-02-10 10:00:00', 'total' => '2', 'currency' => 'EUR'],
        ['ordered_at' => '2026-02-20 10:00:00', 'total' => '3', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-02-28'],
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-01-01', 'label' => null, 'values' => ['sum_total' => 30]],
        ['key' => '2026-02-01', 'label' => null, 'values' => ['sum_total' => null]],
    ])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD']);
});

it('a group of one currency keeps its value while a mixed group is null', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'new', 'total' => '10', 'currency' => 'EUR'],
        ['status' => 'new', 'total' => '5', 'currency' => 'EUR'],
        ['status' => 'paid', 'total' => '30', 'currency' => 'EUR'],
        ['status' => 'paid', 'total' => '8', 'currency' => 'USD'],
        ['status' => 'shipped', 'total' => '1', 'currency' => 'GBP'],
        ['status' => 'shipped', 'total' => '2', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'status'],
        'sort' => ['by' => 'key', 'dir' => 'asc'],
    ]);

    expect($result->series)->toBe([
        ['key' => 'new', 'label' => null, 'values' => ['sum_total' => 15]],
        ['key' => 'paid', 'label' => null, 'values' => ['sum_total' => null]],
        ['key' => 'shipped', 'label' => null, 'values' => ['sum_total' => null]],
    ])
        ->and($result->total)->toBe(['sum_total' => null])
        ->and($result->meta['warnings'])->toHaveCount(1)
        ->and($result->meta['warnings'][0]['field'])->toBe('sum_total')
        ->and($result->meta['currencies'])->toBe(['EUR', 'GBP', 'USD']);
});

it('a relation group is checked for mixed currencies', function (): void {
    $rossi = AggregateCustomerStubModel::query()->create(['name' => 'Rossi', 'country' => 'IT']);
    $dupont = AggregateCustomerStubModel::query()->create(['name' => 'Dupont', 'country' => 'FR']);
    AggregateTestKit::seedOrders([
        ['customer_id' => $rossi->id, 'total' => '10', 'currency' => 'EUR'],
        ['customer_id' => $rossi->id, 'total' => '20', 'currency' => 'USD'],
        ['customer_id' => $dupont->id, 'total' => '5', 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'customer.country'],
        'sort' => ['by' => 'key', 'dir' => 'asc'],
    ]);

    expect($result->series)->toBe([
        ['key' => 'FR', 'label' => 'FR', 'values' => ['sum_total' => 5]],
        ['key' => 'IT', 'label' => 'IT', 'values' => ['sum_total' => null]],
    ])
        ->and($result->meta['warnings'])->toHaveCount(1);
});

it('other is checked for mixed currencies', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'paid', 'total' => '100', 'currency' => 'EUR'],
        ['status' => 'new', 'total' => '6', 'currency' => 'EUR'],
        ['status' => 'shipped', 'total' => '5', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'status'],
        'limit' => 1,
        'other' => true,
    ]);

    expect($result->series)->toBe([['key' => 'paid', 'label' => null, 'values' => ['sum_total' => 100]]])
        ->and($result->other)->toBe(['sum_total' => null, 'groups' => 2])
        ->and($result->meta['warnings'])->toHaveCount(1);
});

it('grouping by currency splits the result into single-currency values', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => 'EUR'],
        ['total' => '5', 'currency' => 'EUR'],
        ['total' => '20', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'currency'],
        'sort' => ['by' => 'key', 'dir' => 'asc'],
    ]);

    expect($result->series)->toBe([
        ['key' => 'EUR', 'label' => null, 'values' => ['count' => 2, 'sum_total' => 15]],
        ['key' => 'USD', 'label' => null, 'values' => ['count' => 1, 'sum_total' => 20]],
    ])
        ->and($result->total)->toBe(['count' => 3, 'sum_total' => null])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('grouping by currency leaves the rest of the groups empty without a warning', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '20', 'currency' => 'USD'],
        ['total' => '10', 'currency' => 'EUR'],
        ['total' => '5', 'currency' => 'GBP'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'currency'],
        'limit' => 1,
        'other' => true,
    ]);

    expect($result->series)->toBe([['key' => 'USD', 'label' => null, 'values' => ['sum_total' => 20]]])
        ->and($result->other)->toBe(['sum_total' => null, 'groups' => 2])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('a bucket with one currency keeps its value while a mixed bucket is null', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-05 10:00:00', 'total' => '10', 'currency' => 'EUR'],
        ['ordered_at' => '2026-01-20 10:00:00', 'total' => '20', 'currency' => 'EUR'],
        ['ordered_at' => '2026-02-03 10:00:00', 'total' => '5', 'currency' => 'EUR'],
        ['ordered_at' => '2026-02-10 10:00:00', 'total' => '7', 'currency' => 'USD'],
        ['ordered_at' => '2026-03-04 10:00:00', 'total' => '1', 'currency' => 'EUR'],
        ['ordered_at' => '2026-03-04 15:00:00', 'total' => '2', 'currency' => 'USD'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total'], ['fn' => 'avg', 'field' => 'total']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-04-30'],
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-01-01', 'label' => null, 'values' => ['count' => 2, 'sum_total' => 30, 'avg_total' => 15.0]],
        ['key' => '2026-02-01', 'label' => null, 'values' => ['count' => 2, 'sum_total' => null, 'avg_total' => null]],
        ['key' => '2026-03-01', 'label' => null, 'values' => ['count' => 2, 'sum_total' => null, 'avg_total' => null]],
        ['key' => '2026-04-01', 'label' => null, 'values' => ['count' => 0, 'sum_total' => 0, 'avg_total' => null]],
    ])
        ->and($result->total)->toBe(['count' => 6, 'sum_total' => null, 'avg_total' => null])
        ->and(array_column($result->meta['warnings'], 'field'))->toBe(['sum_total', 'avg_total'])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD']);
});

it('a bucketed result with one currency names it', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-05 10:00:00', 'total' => '10', 'currency' => 'EUR'],
        ['ordered_at' => '2026-02-10 10:00:00', 'total' => '7', 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-02-28'],
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-01-01', 'label' => null, 'values' => ['sum_total' => 10]],
        ['key' => '2026-02-01', 'label' => null, 'values' => ['sum_total' => 7]],
    ])
        ->and($result->meta['currency'])->toBe('EUR')
        ->and($result->meta)->not->toHaveKeys(['currencies', 'warnings']);
});

it('compare checks the previous period', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:00:00', 'UTC'));
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-12 10:00:00', 'total' => '10', 'currency' => 'EUR'],
        ['ordered_at' => '2026-01-25 10:00:00', 'total' => '5', 'currency' => 'USD'],
        ['ordered_at' => '2026-02-14 10:00:00', 'total' => '20', 'currency' => 'EUR'],
        ['ordered_at' => '2026-02-15 10:00:00', 'total' => '30', 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'period' => ['field' => 'ordered_at', 'preset' => 'last_month'],
        'compare' => 'previous_period',
    ]);

    expect($result->total)->toBe(['sum_total' => 50])
        ->and($result->compare)->toBe(['total' => ['sum_total' => null]])
        ->and($result->meta['warnings'])->toHaveCount(1)
        ->and($result->meta['warnings'][0]['field'])->toBe('sum_total')
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies']);
});

it('compare checks the buckets of the previous period', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00', 'UTC'));
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2025-12-20 10:00:00', 'total' => '4', 'currency' => 'EUR'],
        ['ordered_at' => '2026-01-12 10:00:00', 'total' => '10', 'currency' => 'EUR'],
        ['ordered_at' => '2026-01-25 10:00:00', 'total' => '5', 'currency' => 'USD'],
        ['ordered_at' => '2026-02-14 10:00:00', 'total' => '20', 'currency' => 'EUR'],
        ['ordered_at' => '2026-03-02 10:00:00', 'total' => '30', 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
        'period' => ['field' => 'ordered_at', 'last' => ['n' => 2, 'unit' => 'month']],
        'compare' => 'previous_period',
    ]);

    expect($result->series)->toBe([
        ['key' => '2026-02-01', 'label' => null, 'values' => ['sum_total' => 20]],
        ['key' => '2026-03-01', 'label' => null, 'values' => ['sum_total' => 30]],
    ])
        ->and($result->compare)->toBe([
            'total' => ['sum_total' => null],
            'series' => [
                ['key' => '2025-12-01', 'label' => null, 'values' => ['sum_total' => 4]],
                ['key' => '2026-01-01', 'label' => null, 'values' => ['sum_total' => null]],
            ],
        ])
        ->and($result->meta['warnings'])->toHaveCount(1)
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies']);
});

it('each amount takes the currency column paired with it', function (): void {
    aggregate_currency_add_local_amount();
    aggregate_currency_insert([
        ['total' => '10', 'currency' => 'EUR', 'amount_local' => '100', 'currency_local' => 'EUR'],
        ['total' => '20', 'currency' => 'USD', 'amount_local' => '200', 'currency_local' => 'EUR'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total'], ['fn' => 'sum', 'field' => 'amount_local']]]);

    expect($result->total)->toBe(['sum_total' => null, 'sum_amount_local' => 300])
        ->and(array_column($result->meta['warnings'], 'field'))->toBe(['sum_total'])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD'])
        ->and($result->meta)->not->toHaveKey('currency');
});

it('the currencies met come from the columns of every mixed amount', function (): void {
    aggregate_currency_add_local_amount();
    aggregate_currency_insert([
        ['total' => '10', 'currency' => 'EUR', 'amount_local' => '100', 'currency_local' => 'GBP'],
        ['total' => '20', 'currency' => 'USD', 'amount_local' => '200', 'currency_local' => 'CHF'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total'], ['fn' => 'sum', 'field' => 'amount_local']]]);

    expect($result->total)->toBe(['sum_total' => null, 'sum_amount_local' => null])
        ->and(array_column($result->meta['warnings'], 'field'))->toBe(['sum_total', 'sum_amount_local'])
        ->and($result->meta['currencies'])->toBe(['CHF', 'EUR', 'GBP', 'USD']);
});

it('the currencies met are at most twenty', function (): void {
    AggregateTestKit::seedOrders(array_map(
        static fn (int $number): array => ['total' => '1', 'currency' => sprintf('C%02d', $number)],
        range(1, 21),
    ));

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->meta['currencies'])->toHaveCount(20)
        ->and($result->meta['currencies'][0])->toBe('C01')
        ->and($result->meta['currencies'][19])->toBe('C20');
});

it('the currencies met are those of the rows the caller may see', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'new', 'total' => '10', 'currency' => 'EUR'],
        ['status' => 'new', 'total' => '20', 'currency' => 'USD'],
        ['status' => 'paid', 'total' => '5', 'currency' => 'GBP'],
    ]);
    AggregateTestKit::asReader([AggregateOrderStubModel::class => new FiltersGroup([new Filter('status', 'new')])]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => null])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD']);
});

it('the currencies met are those of the rows of the period', function (): void {
    AggregateTestKit::seedOrders([
        ['ordered_at' => '2026-01-10 10:00:00', 'total' => '10', 'currency' => 'EUR'],
        ['ordered_at' => '2026-01-20 10:00:00', 'total' => '20', 'currency' => 'USD'],
        ['ordered_at' => '2026-02-10 10:00:00', 'total' => '5', 'currency' => 'GBP'],
    ]);

    $result = aggregate_currency_run([
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ]);

    expect($result->total)->toBe(['sum_total' => null])
        ->and($result->meta['currencies'])->toBe(['EUR', 'USD']);
});

it('the rows the caller may not see do not mix their currency into the figures', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'new', 'total' => '10', 'currency' => 'EUR'],
        ['status' => 'new', 'total' => '5', 'currency' => 'EUR'],
        ['status' => 'paid', 'total' => '20', 'currency' => 'USD'],
    ]);
    AggregateTestKit::asReader([AggregateOrderStubModel::class => new FiltersGroup([new Filter('status', 'new')])]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['sum_total' => 15])
        ->and($result->meta['currency'])->toBe('EUR')
        ->and($result->meta)->not->toHaveKeys(['currencies', 'warnings']);
});

it('an amount whose currency the caller cannot read is UNREADABLE_FIELD, in every path of the engine', function (array $request, string $field, string $amount): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 09:00:00', 'UTC'));
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => 'EUR'],
        ['total' => '20', 'currency' => 'USD'],
    ]);

    $refusal = aggregate_currency_refusal($request, new AggregateHiddenCurrencyOrderStubModel);

    expect($refusal->toArray())->toBe([
        'code' => 'UNREADABLE_FIELD',
        'message' => sprintf('The currency of "%s" cannot be read by this caller, so the figure cannot be checked for mixed currencies.', $amount),
        'field' => $field,
    ]);
})->with([
    'a sum' => [['measures' => [['fn' => 'sum', 'field' => 'total']]], 'measures.0.field', 'total'],
    'an average' => [['measures' => [['fn' => 'avg', 'field' => 'total']]], 'measures.0.field', 'total'],
    'a minimum' => [['measures' => [['fn' => 'min', 'field' => 'total']]], 'measures.0.field', 'total'],
    'a maximum' => [['measures' => [['fn' => 'max', 'field' => 'total']]], 'measures.0.field', 'total'],
    'an integer amount paired by its whole name' => [['measures' => [['fn' => 'sum', 'field' => 'quantity']]], 'measures.0.field', 'quantity'],
    'the second measure after a count' => [['measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']]], 'measures.1.field', 'total'],
    'a grouped request' => [['measures' => [['fn' => 'sum', 'field' => 'total']], 'group_by' => ['field' => 'status']], 'measures.0.field', 'total'],
    'a grouped request with the rest of the groups' => [
        ['measures' => [['fn' => 'sum', 'field' => 'total']], 'group_by' => ['field' => 'status'], 'limit' => 1, 'other' => true],
        'measures.0.field',
        'total',
    ],
    'a bucketed request' => [
        [
            'measures' => [['fn' => 'sum', 'field' => 'total']],
            'group_by' => ['field' => 'ordered_at', 'bucket' => 'month'],
            'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-02-28'],
        ],
        'measures.0.field',
        'total',
    ],
    'a comparison with the previous period' => [
        ['measures' => [['fn' => 'sum', 'field' => 'total']], 'period' => ['field' => 'ordered_at', 'preset' => 'last_month'], 'compare' => 'previous_period'],
        'measures.0.field',
        'total',
    ],
]);

it('a count, a date and a key are measured when the caller cannot read the currency', function (): void {
    AggregateTestKit::seedOrders([
        ['total' => '10', 'currency' => 'EUR', 'ordered_at' => '2026-01-10 10:00:00'],
        ['total' => '20', 'currency' => 'USD', 'ordered_at' => '2026-02-10 10:00:00'],
    ]);

    $result = aggregate_currency_run(['measures' => [
        ['fn' => 'count'],
        ['fn' => 'count', 'field' => 'total'],
        ['fn' => 'min', 'field' => 'ordered_at'],
        ['fn' => 'max', 'field' => 'id'],
    ]], new AggregateHiddenCurrencyOrderStubModel);

    expect($result->total)->toBe(['count' => 2, 'count_total' => 2, 'min_ordered_at' => '2026-01-10 10:00:00', 'max_id' => 2])
        ->and($result->meta)->not->toHaveKeys(['currency', 'currencies', 'warnings']);
});

it('a caller who may read the currency of an amount still gets it checked', function (): void {
    AggregateTestKit::seedOrders([
        ['quantity' => 2, 'currency' => 'EUR'],
        ['quantity' => 3, 'currency' => 'EUR'],
    ]);

    $result = aggregate_currency_run(['measures' => [['fn' => 'sum', 'field' => 'quantity']]], new AggregateRestrictedOrderStubModel);

    expect($result->total)->toBe(['sum_quantity' => 5])
        ->and($result->meta['currency'])->toBe('EUR');
});
