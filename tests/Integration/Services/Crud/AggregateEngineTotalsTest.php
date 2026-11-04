<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\Aggregate\AggregateLimits;
use Modules\Core\Services\Crud\Aggregate\AggregateMeasure;
use Modules\Core\Services\Crud\Aggregate\AggregateQuery;
use Modules\Core\Services\Crud\Aggregate\AggregateResult;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Crud\AggregateOrderStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateRestrictedOrderStubModel;
use Modules\Core\Tests\Stubs\Crud\DynamicOnlyStubModel;
use Modules\Core\Tests\Support\AggregateTestKit;

beforeEach(function (): void {
    // Five measures, so that one request reads every function of a column.
    config(['core.aggregate.max_measures' => 5]);
    AggregateTestKit::createTables();
    AggregateTestKit::asSuperadmin();
});

/**
 * The totals of an ungrouped request over the orders the logged-in user may see.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_totals(array $request, ?FiltersGroup $filters = null, ?Model $model = null): AggregateResult
{
    return app(CrudService::class)->aggregate(
        AggregateTestKit::listData($model ?? new AggregateOrderStubModel, $filters),
        AggregateQuery::fromArray($request, AggregateLimits::fromConfig()),
    );
}

/**
 * The refusal of a request the service must not run.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_totals_refusal(array $request, ?Model $model = null): AggregateException
{
    try {
        aggregate_totals($request, model: $model);
    } catch (AggregateException $exception) {
        return $exception;
    }

    throw new RuntimeException('The aggregate request was not refused.');
}

/**
 * The refusal of a count over the rows the filters keep, which the service must not run.
 */
function aggregate_totals_filter_refusal(FiltersGroup $filters, Model $model): AggregateException
{
    try {
        aggregate_totals(['measures' => [['fn' => 'count']]], $filters, $model);
    } catch (AggregateException $exception) {
        return $exception;
    }

    throw new RuntimeException('The aggregate request was not refused.');
}

it('counts the rows', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 08:30:00', 'UTC'));
    AggregateTestKit::seedOrders([['status' => 'new'], ['status' => 'paid'], ['status' => 'paid']]);

    $result = aggregate_totals(['measures' => [['fn' => 'count']]]);

    expect($result->toArray())->toBe([
        'data' => ['series' => [], 'total' => ['count' => 3]],
        'meta' => ['row_count' => 3, 'group_count' => 0, 'truncated' => false, 'generated_at' => '2026-10-21T08:30:00Z'],
    ]);
});

it('sums, averages, takes min and max of a decimal column', function (): void {
    AggregateTestKit::seedOrders([['total' => '10.5'], ['total' => '20.25'], ['total' => '30.75'], ['total' => null]]);

    $result = aggregate_totals(['measures' => [
        ['fn' => 'sum', 'field' => 'total'],
        ['fn' => 'avg', 'field' => 'total'],
        ['fn' => 'min', 'field' => 'total'],
        ['fn' => 'max', 'field' => 'total'],
    ]]);

    expect($result->total)->toBe(['sum_total' => 61.5, 'avg_total' => 20.5, 'min_total' => 10.5, 'max_total' => 30.75])
        ->and($result->meta['row_count'])->toBe(4);
});

it('count over a field counts only non-null values', function (): void {
    AggregateTestKit::seedOrders([['total' => '10.5'], ['total' => null], ['total' => '20.25']]);

    $result = aggregate_totals(['measures' => [['fn' => 'count'], ['fn' => 'count', 'field' => 'total']]]);

    expect($result->total)->toBe(['count' => 3, 'count_total' => 2]);
});

it('takes min and max of a date-time column as the stored text', function (): void {
    AggregateTestKit::seedOrders([['ordered_at' => '2026-03-02 08:15:00'], ['ordered_at' => '2026-01-15 12:00:00']]);

    $result = aggregate_totals(['measures' => [['fn' => 'min', 'field' => 'ordered_at'], ['fn' => 'max', 'field' => 'ordered_at']]]);

    expect($result->total)->toBe(['min_ordered_at' => '2026-01-15 12:00:00', 'max_ordered_at' => '2026-03-02 08:15:00']);
});

it('an empty table gives zero counts and null sum, avg, min, max', function (): void {
    $result = aggregate_totals(['measures' => [
        ['fn' => 'count'],
        ['fn' => 'sum', 'field' => 'total'],
        ['fn' => 'avg', 'field' => 'total'],
        ['fn' => 'min', 'field' => 'total'],
        ['fn' => 'max', 'field' => 'total'],
    ]]);

    expect($result->total)->toBe(['count' => 0, 'sum_total' => null, 'avg_total' => null, 'min_total' => null, 'max_total' => null])
        ->and($result->meta['row_count'])->toBe(0);
});

it('a list filter narrows the totals', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'paid', 'total' => '10.5'],
        ['status' => 'paid', 'total' => '20.25'],
        ['status' => 'new', 'total' => '100'],
    ]);

    $result = aggregate_totals(
        ['measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']]],
        new FiltersGroup([new Filter('status', 'paid')]),
    );

    expect($result->total)->toBe(['count' => 2, 'sum_total' => 30.75])
        ->and($result->meta['row_count'])->toBe(2);
});

it('acl narrows the totals', function (): void {
    $permission = Permission::findOrCreate(PermissionName::forModel(new AggregateOrderStubModel, 'select'), 'web');
    $role = Role::factory()->create(['name' => 'aggregate_totals_reader_' . uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $role->id,
        'filters' => new FiltersGroup([new Filter('status', 'paid')]),
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();
    $reader = User::factory()->create();
    $reader->assignRole($role);
    auth()->login($reader);
    AggregateTestKit::seedOrders([
        ['status' => 'paid', 'total' => '10.5'],
        ['status' => 'paid', 'total' => '20.25'],
        ['status' => 'new', 'total' => '100'],
    ]);

    $result = aggregate_totals(['measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']]]);

    expect($result->total)->toBe(['count' => 2, 'sum_total' => 30.75])
        ->and($result->meta['row_count'])->toBe(2);
});

it('sum over a primary key is NOT_MEASURABLE', function (): void {
    $refusal = aggregate_totals_refusal(['measures' => [['fn' => 'sum', 'field' => 'id']]]);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::NotMeasurable)
        ->and($refusal->field)->toBe('measures.0.field');
});

it('sum over a string is NOT_MEASURABLE', function (): void {
    $refusal = aggregate_totals_refusal(['measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'status']]]);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::NotMeasurable)
        ->and($refusal->field)->toBe('measures.1.field');
});

it('min over a string is NOT_MEASURABLE', function (): void {
    $refusal = aggregate_totals_refusal(['measures' => [['fn' => 'min', 'field' => 'status']]]);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::NotMeasurable)
        ->and($refusal->field)->toBe('measures.0.field');
});

it('an unknown column is INVALID_FIELD', function (array $request, string $field): void {
    $refusal = aggregate_totals_refusal(['measures' => [['fn' => 'count']], ...$request]);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::InvalidField)
        ->and($refusal->field)->toBe($field);
})->with([
    'a measure field' => [['measures' => [['fn' => 'sum', 'field' => 'amount']]], 'measures.0.field'],
    'a computed accessor as a measure' => [['measures' => [['fn' => 'count', 'field' => 'total_label']]], 'measures.0.field'],
    'a relation column as a measure' => [['measures' => [['fn' => 'count', 'field' => 'customer.country']]], 'measures.0.field'],
    'a group field' => [['group_by' => ['field' => 'channel']], 'group_by.field'],
    'a relation group field' => [['group_by' => ['field' => 'customer.city']], 'group_by.field'],
    'a multi-hop group field' => [['group_by' => ['field' => 'customer.orders.status']], 'group_by.field'],
    'a group label' => [['group_by' => ['field' => 'customer_id', 'label' => 'customer.nickname']], 'group_by.label'],
    'a label the group field is not the key of' => [['group_by' => ['field' => 'status', 'label' => 'customer.name']], 'group_by.label'],
    'a period field' => [['period' => ['field' => 'shipped_at', 'preset' => 'this_month']], 'period.field'],
]);

it('a column the user cannot read is UNREADABLE_FIELD', function (array $request, string $field): void {
    $refusal = aggregate_totals_refusal(['measures' => [['fn' => 'count']], ...$request], new AggregateRestrictedOrderStubModel);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::UnreadableField)
        ->and($refusal->field)->toBe($field);
})->with([
    'a measure field' => [['measures' => [['fn' => 'sum', 'field' => 'total']]], 'measures.0.field'],
    'a group field' => [['group_by' => ['field' => 'total']], 'group_by.field'],
    'a period field' => [['period' => ['field' => 'total', 'preset' => 'this_month']], 'period.field'],
]);

it('a filter on a column the user cannot read is UNREADABLE_FIELD', function (FiltersGroup $filters, string $field): void {
    $refusal = aggregate_totals_filter_refusal($filters, new AggregateRestrictedOrderStubModel);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::UnreadableField)
        ->and($refusal->field)->toBe($field);
})->with([
    'a column' => [new FiltersGroup([new Filter('status', 'paid'), new Filter('total', '10')]), 'filters.total'],
    'a column in a nested group' => [
        new FiltersGroup([new Filter('status', 'paid'), new FiltersGroup([new Filter('quantity', 1), new Filter('total', '10')], WhereClause::Or)]),
        'filters.total',
    ],
    'a relation path' => [new FiltersGroup([new Filter('customer.name', 'Ada')]), 'filters.customer.name'],
]);

it('a relation node of filters parsed without the route is INVALID_FIELD on filters', function (): void {
    $request = ListRequest::create('/app/crud/aggregate/core/licenses', 'POST');
    $request->setUserResolver(static fn (): ?Authenticatable => auth()->user());
    $data = new ListRequestData($request, 'licenses', ['filters' => [
        ['operator' => 'and', 'filters' => [
            ['relation' => 'user', 'filters' => ['operator' => 'and', 'filters' => [['property' => 'id', 'operator' => '=', 'value' => 5]]]],
        ]],
    ]], 'id', 'core');

    expect(fn (): AggregateResult => app(CrudService::class)->aggregate($data, AggregateQuery::fromArray(['measures' => [['fn' => 'count']]], AggregateLimits::fromConfig())))
        ->toThrow(function (AggregateException $exception): void {
            expect($exception->errorCode)->toBe(AggregateErrorCode::InvalidField)
                ->and($exception->field)->toBe('filters');
        });
});

it('a filter on an unknown column is INVALID_FIELD', function (string $property, Model $model): void {
    $refusal = aggregate_totals_filter_refusal(new FiltersGroup([new Filter($property, 'x')]), $model);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::InvalidField)
        ->and($refusal->field)->toBe('filters.' . $property);
})->with([
    'a column' => ['no_such_column', new AggregateOrderStubModel],
    'a column the caller could not read either' => ['no_such_column', new AggregateRestrictedOrderStubModel],
    'a json path of no column' => ['no_such_column->key', new AggregateOrderStubModel],
]);

it('a filter on a property stored outside the columns still narrows the totals', function (string $property): void {
    Schema::create('dynamic_only_stub', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->json('components')->nullable();
        $table->unsignedBigInteger('entity_id')->nullable();
        $table->unsignedBigInteger('presettable_id')->nullable();
    });
    SchemaInspector::getInstance()->clearTable('dynamic_only_stub');
    DynamicOnlyStubModel::query()->insert([
        ['name' => 'Red thing', 'components' => json_encode(['color' => 'red'])],
        ['name' => 'Blue thing', 'components' => json_encode(['color' => 'blue'])],
    ]);

    $result = aggregate_totals(['measures' => [['fn' => 'count']]], new FiltersGroup([new Filter($property, 'red')]), new DynamicOnlyStubModel);

    expect($result->total)->toBe(['count' => 1]);
})->with([
    'a dynamic field' => ['color'],
    'a json path of a column' => ['components->color'],
]);

it('holds a query built without its validator to max_measures', function (): void {
    config(['core.aggregate.max_measures' => 2]);
    $query = new AggregateQuery(
        measures: [new AggregateMeasure('count', null), new AggregateMeasure('sum', 'total'), new AggregateMeasure('max', 'total')],
        group: null,
        period: null,
        sortBy: 'measure',
        sortDir: 'desc',
        limit: null,
        other: false,
        compare: false,
    );

    expect(fn (): AggregateResult => app(CrudService::class)->aggregate(AggregateTestKit::listData(new AggregateOrderStubModel), $query))
        ->toThrow(function (AggregateException $exception): void {
            expect($exception->toArray())->toBe([
                'code' => 'INVALID_FIELD',
                'message' => 'An aggregate holds at most 2 measures.',
                'field' => 'measures',
            ]);
        });
});

it('no select permission is refused', function (): void {
    auth()->login(User::factory()->create());

    expect(fn (): AggregateResult => aggregate_totals(['measures' => [['fn' => 'count']]]))
        ->toThrow(AuthorizationException::class);
});
