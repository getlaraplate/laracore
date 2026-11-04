<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Enums\TimeBucket;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Services\Crud\Aggregate\AggregateGroup;
use Modules\Core\Services\Crud\Aggregate\AggregateLimits;
use Modules\Core\Services\Crud\Aggregate\AggregateMeasure;
use Modules\Core\Services\Crud\Aggregate\AggregateQuery;
use Modules\Core\Services\Crud\Aggregate\AggregateResult;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Tests\Stubs\Crud\AggregateCustomerStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateCustomerTranslationStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateLabelledOrderStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateOrderStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateRestrictedOrderStubModel;
use Modules\Core\Tests\Stubs\Crud\AggregateTreeOrderStubModel;
use Modules\Core\Tests\Support\AggregateTestKit;

beforeEach(function (): void {
    AggregateTestKit::createTables();
    AggregateTestKit::asSuperadmin();
});

/**
 * The figures of a grouped request over the orders the logged-in user may see.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_groups(array $request, ?FiltersGroup $filters = null, ?Model $model = null): AggregateResult
{
    return app(CrudService::class)->aggregate(
        AggregateTestKit::listData($model ?? new AggregateOrderStubModel, $filters),
        AggregateQuery::fromArray($request, AggregateLimits::fromConfig()),
    );
}

/**
 * The refusal of a grouped request the service must not run.
 *
 * @param  array<string, mixed>  $request
 */
function aggregate_groups_refusal(array $request, ?Model $model = null): AggregateException
{
    try {
        aggregate_groups($request, model: $model);
    } catch (AggregateException $exception) {
        return $exception;
    }

    throw new RuntimeException('The aggregate request was not refused.');
}

function aggregate_groups_customer(string $name, string $country = 'IT'): AggregateCustomerStubModel
{
    return AggregateCustomerStubModel::query()->create(['name' => $name, 'country' => $country]);
}

it('groups by an own column', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 08:30:00', 'UTC'));
    AggregateTestKit::seedOrders([
        ['status' => 'shipped', 'total' => '7.5'],
        ['status' => 'paid', 'total' => '10.5'],
        ['status' => 'new', 'total' => '100.5'],
        ['status' => 'paid', 'total' => '20.25'],
        ['status' => 'shipped', 'total' => '2.25'],
        ['status' => 'paid', 'total' => '30.5'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'status'],
    ]);

    expect($result->toArray())->toBe([
        'data' => [
            'series' => [
                ['key' => 'paid', 'label' => null, 'values' => ['count' => 3, 'sum_total' => 61.25]],
                ['key' => 'shipped', 'label' => null, 'values' => ['count' => 2, 'sum_total' => 9.75]],
                ['key' => 'new', 'label' => null, 'values' => ['count' => 1, 'sum_total' => 100.5]],
            ],
            'total' => ['count' => 6, 'sum_total' => 171.5],
        ],
        'meta' => ['row_count' => 6, 'group_count' => 3, 'truncated' => false, 'generated_at' => '2026-10-21T08:30:00Z'],
    ]);
});

it('orders by key ascending when asked', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'shipped'], ['status' => 'shipped'], ['status' => 'shipped'],
        ['status' => 'paid'], ['status' => 'paid'],
        ['status' => 'new'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'status'],
        'sort' => ['by' => 'key', 'dir' => 'asc'],
    ]);

    expect(array_column($result->series, 'key'))->toBe(['new', 'paid', 'shipped']);
});

it('the null group comes last when sorted by key, in both directions', function (string $dir, array $keys): void {
    AggregateTestKit::seedOrders([['currency' => 'USD'], ['currency' => null], ['currency' => 'EUR'], ['currency' => 'GBP']]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'currency'],
        'sort' => ['by' => 'key', 'dir' => $dir],
    ]);

    expect(array_column($result->series, 'key'))->toBe($keys);
})->with([
    'ascending' => ['asc', ['EUR', 'GBP', 'USD', null]],
    'descending' => ['desc', ['USD', 'GBP', 'EUR', null]],
]);

it('a limit by key folds the null group into the rest', function (string $dir, array $keys): void {
    AggregateTestKit::seedOrders([
        ['currency' => null], ['currency' => null], ['currency' => null],
        ['currency' => 'EUR'], ['currency' => 'USD'], ['currency' => 'USD'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'currency'],
        'sort' => ['by' => 'key', 'dir' => $dir],
        'limit' => 2,
        'other' => true,
    ]);

    expect(array_column($result->series, 'key'))->toBe($keys)
        ->and($result->other)->toBe(['count' => 3, 'groups' => 1]);
})->with([
    'ascending' => ['asc', ['EUR', 'USD']],
    'descending' => ['desc', ['USD', 'EUR']],
]);

it('ties on the measure break by key, the null group last', function (string $dir, array $keys): void {
    AggregateTestKit::seedOrders([
        ['currency' => 'USD'], ['currency' => 'USD'],
        ['currency' => null], ['currency' => null],
        ['currency' => 'EUR'], ['currency' => 'EUR'],
        ['currency' => 'GBP'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'currency'],
        'sort' => ['by' => 'measure', 'dir' => $dir],
    ]);

    expect(array_column($result->series, 'key'))->toBe($keys);
})->with([
    'descending' => ['desc', ['EUR', 'USD', null, 'GBP']],
    'ascending' => ['asc', ['GBP', 'EUR', 'USD', null]],
]);

it('a null group has key null and no label', function (): void {
    $ada = aggregate_groups_customer('Ada');
    AggregateTestKit::seedOrders([
        ['customer_id' => $ada->id],
        ['customer_id' => null],
        ['customer_id' => $ada->id],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer_id', 'label' => 'customer.name'],
    ]);

    expect($result->series)->toBe([
        ['key' => $ada->id, 'label' => 'Ada', 'values' => ['count' => 2]],
        ['key' => null, 'label' => null, 'values' => ['count' => 1]],
    ])
        ->and($result->meta['group_count'])->toBe(2);
});

it('groups by a to-one relation column', function (): void {
    $rossi = aggregate_groups_customer('Rossi', 'IT');
    $bianchi = aggregate_groups_customer('Bianchi', 'IT');
    $dupont = aggregate_groups_customer('Dupont', 'FR');
    AggregateTestKit::seedOrders([
        ['customer_id' => $rossi->id, 'total' => '10.5'],
        ['customer_id' => $dupont->id, 'total' => '7.5'],
        ['customer_id' => $rossi->id, 'total' => '20.5'],
        ['customer_id' => $bianchi->id, 'total' => '5.25'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'customer.country'],
    ]);

    expect($result->series)->toBe([
        ['key' => 'IT', 'label' => 'IT', 'values' => ['count' => 3, 'sum_total' => 36.25]],
        ['key' => 'FR', 'label' => 'FR', 'values' => ['count' => 1, 'sum_total' => 7.5]],
    ])
        ->and($result->total)->toBe(['count' => 4, 'sum_total' => 43.75])
        ->and($result->meta['group_count'])->toBe(2);
});

it('a relation group hides related rows the user cannot see', function (): void {
    $rossi = aggregate_groups_customer('Rossi', 'IT');
    $dupont = aggregate_groups_customer('Dupont', 'FR');
    AggregateTestKit::seedOrders([
        ['customer_id' => $rossi->id],
        ['customer_id' => $dupont->id],
        ['customer_id' => $rossi->id],
    ]);
    AggregateTestKit::asReader([
        AggregateOrderStubModel::class => null,
        AggregateCustomerStubModel::class => new FiltersGroup([new Filter('country', 'IT')]),
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer.country'],
    ]);

    expect($result->series)->toBe([['key' => 'IT', 'label' => 'IT', 'values' => ['count' => 2]]])
        ->and($result->meta['group_count'])->toBe(1);
});

it('acl narrows the groups of a relation column', function (): void {
    $rossi = aggregate_groups_customer('Rossi', 'IT');
    $dupont = aggregate_groups_customer('Dupont', 'FR');
    $muller = aggregate_groups_customer('Muller', 'DE');
    AggregateTestKit::seedOrders([
        ['customer_id' => $rossi->id, 'status' => 'paid'],
        ['customer_id' => $muller->id, 'status' => 'new'],
        ['customer_id' => $dupont->id, 'status' => 'new'],
        ['customer_id' => $rossi->id, 'status' => 'paid'],
        ['customer_id' => $muller->id, 'status' => 'new'],
        ['customer_id' => $dupont->id, 'status' => 'paid'],
        ['customer_id' => $rossi->id, 'status' => 'new'],
        ['customer_id' => $muller->id, 'status' => 'new'],
        ['customer_id' => $dupont->id, 'status' => 'new'],
    ]);
    AggregateTestKit::asReader([
        AggregateOrderStubModel::class => new FiltersGroup([new Filter('status', 'paid')]),
        AggregateCustomerStubModel::class => null,
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer.country'],
        'limit' => 1,
        'other' => true,
    ]);

    expect($result->series)->toBe([['key' => 'IT', 'label' => 'IT', 'values' => ['count' => 2]]])
        ->and($result->other)->toBe(['count' => 1, 'groups' => 1])
        ->and($result->meta['group_count'])->toBe(2);
});

it('a list filter narrows the groups of a relation column', function (): void {
    $rossi = aggregate_groups_customer('Rossi', 'IT');
    $dupont = aggregate_groups_customer('Dupont', 'FR');
    $muller = aggregate_groups_customer('Muller', 'DE');
    AggregateTestKit::seedOrders([
        ['customer_id' => $rossi->id, 'status' => 'paid'],
        ['customer_id' => $muller->id, 'status' => 'new'],
        ['customer_id' => $dupont->id, 'status' => 'new'],
        ['customer_id' => $rossi->id, 'status' => 'paid'],
        ['customer_id' => $muller->id, 'status' => 'new'],
        ['customer_id' => $dupont->id, 'status' => 'paid'],
        ['customer_id' => $rossi->id, 'status' => 'new'],
        ['customer_id' => $muller->id, 'status' => 'new'],
        ['customer_id' => $dupont->id, 'status' => 'new'],
    ]);

    $result = aggregate_groups(
        ['measures' => [['fn' => 'count']], 'group_by' => ['field' => 'customer.country']],
        new FiltersGroup([new Filter('status', 'paid')]),
    );

    expect($result->series)->toBe([
        ['key' => 'IT', 'label' => 'IT', 'values' => ['count' => 2]],
        ['key' => 'FR', 'label' => 'FR', 'values' => ['count' => 1]],
    ])
        ->and($result->meta['group_count'])->toBe(2);
});

it('a relation group or a group label on an entity the user may not select is refused', function (array $group): void {
    $ada = aggregate_groups_customer('Ada');
    AggregateTestKit::seedOrders([['customer_id' => $ada->id]]);
    AggregateTestKit::asReader([AggregateOrderStubModel::class => null]);

    expect(fn (): AggregateResult => aggregate_groups(['measures' => [['fn' => 'count']], 'group_by' => $group]))
        ->toThrow(AuthorizationException::class);
})->with([
    'a relation column' => [['field' => 'customer.country']],
    'a label through a relation' => [['field' => 'customer_id', 'label' => 'customer.name']],
]);

it('a foreign key group takes labels through group_by.label', function (): void {
    $ada = aggregate_groups_customer('Ada');
    $bob = aggregate_groups_customer('Bob');
    AggregateTestKit::seedOrders([
        ['customer_id' => $bob->id],
        ['customer_id' => $ada->id],
        ['customer_id' => $ada->id],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer_id', 'label' => 'customer.name'],
    ]);

    expect($result->series)->toBe([
        ['key' => $ada->id, 'label' => 'Ada', 'values' => ['count' => 2]],
        ['key' => $bob->id, 'label' => 'Bob', 'values' => ['count' => 1]],
    ]);
});

it('a label the user cannot see falls back to the key', function (): void {
    $ada = aggregate_groups_customer('Ada');
    $bob = aggregate_groups_customer('Bob');
    AggregateTestKit::seedOrders([
        ['customer_id' => $ada->id],
        ['customer_id' => $ada->id],
        ['customer_id' => $bob->id],
    ]);
    AggregateTestKit::asReader([
        AggregateOrderStubModel::class => null,
        AggregateCustomerStubModel::class => new FiltersGroup([new Filter('name', 'Ada')]),
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer_id', 'label' => 'customer.name'],
    ]);

    expect($result->series)->toBe([
        ['key' => $ada->id, 'label' => 'Ada', 'values' => ['count' => 2]],
        ['key' => $bob->id, 'label' => (string) $bob->id, 'values' => ['count' => 1]],
    ]);
});

it('a foreign key group takes a translated label through a declared label source', function (): void {
    app()->setLocale('it');
    $ada = aggregate_groups_customer('Ada');
    AggregateCustomerTranslationStubModel::query()->create(['customer_id' => $ada->id, 'locale' => 'en', 'name' => 'Ada in English']);
    AggregateCustomerTranslationStubModel::query()->create(['customer_id' => $ada->id, 'locale' => 'it', 'name' => 'Ada in italiano']);
    AggregateTestKit::seedOrders([['customer_id' => $ada->id]]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer_id', 'label' => 'translated_customer.name'],
    ], model: new AggregateLabelledOrderStubModel);

    expect($result->series)->toBe([['key' => $ada->id, 'label' => 'Ada in italiano', 'values' => ['count' => 1]]]);
});

it('a declared label source whose translation does not resolve labels from the related column', function (string $label): void {
    $ada = aggregate_groups_customer('Ada');
    AggregateTestKit::seedOrders([['customer_id' => $ada->id]]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer_id', 'label' => $label],
    ], model: new AggregateLabelledOrderStubModel);

    expect($result->series)->toBe([['key' => $ada->id, 'label' => 'Ada', 'values' => ['count' => 1]]]);
})->with([
    'a translation relation the related model lacks' => ['unresolved_translation.name'],
    'a source with no translation column' => ['untranslated_column.name'],
]);

it('a declared label source whose translation does not resolve refuses a column the related table lacks', function (): void {
    $refusal = aggregate_groups_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer_id', 'label' => 'unresolved_translation.title'],
    ], model: new AggregateLabelledOrderStubModel);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::InvalidField)
        ->and($refusal->field)->toBe('group_by.label');
});

it('a translated label source refuses a column other than its translation column', function (): void {
    $refusal = aggregate_groups_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'customer_id', 'label' => 'translated_customer.country'],
    ], model: new AggregateLabelledOrderStubModel);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::InvalidField)
        ->and($refusal->field)->toBe('group_by.label');
});

it('limit with other', function (): void {
    AggregateTestKit::seedOrders([
        ['currency' => 'EUR', 'total' => '10.5'],
        ['currency' => 'USD', 'total' => '5.5'],
        ['currency' => 'EUR', 'total' => '20.5'],
        ['currency' => 'GBP', 'total' => '40.25'],
        ['currency' => null, 'total' => '9.75'],
        ['currency' => 'USD', 'total' => '6.5'],
        ['currency' => 'EUR', 'total' => '30.5'],
        ['currency' => 'GBP', 'total' => null],
        ['currency' => 'USD', 'total' => '6'],
        ['currency' => 'EUR', 'total' => '2.5'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count'], ['fn' => 'avg', 'field' => 'total'], ['fn' => 'min', 'field' => 'total']],
        'group_by' => ['field' => 'currency'],
        'limit' => 2,
        'other' => true,
    ]);

    expect($result->series)->toBe([
        ['key' => 'EUR', 'label' => null, 'values' => ['count' => 4, 'avg_total' => 16.0, 'min_total' => 2.5]],
        ['key' => 'USD', 'label' => null, 'values' => ['count' => 3, 'avg_total' => 6.0, 'min_total' => 5.5]],
    ])
        ->and($result->other)->toBe(['count' => 3, 'avg_total' => 25.0, 'min_total' => 9.75, 'groups' => 2])
        ->and($result->meta['truncated'])->toBeTrue()
        ->and($result->meta['group_count'])->toBe(4);
});

it('other is left out when no group was left out', function (): void {
    AggregateTestKit::seedOrders([['status' => 'paid'], ['status' => 'paid'], ['status' => 'new']]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'status'],
        'limit' => 2,
        'other' => true,
    ]);

    expect(array_column($result->series, 'key'))->toBe(['paid', 'new'])
        ->and($result->toArray()['data'])->not->toHaveKey('other')
        ->and($result->meta['truncated'])->toBeFalse();
});

it('the rest leaves out a null group that is shown', function (): void {
    $rossi = aggregate_groups_customer('Rossi');
    $dupont = aggregate_groups_customer('Dupont');
    AggregateTestKit::seedOrders([
        ['customer_id' => null, 'total' => '1.5'],
        ['customer_id' => $rossi->id, 'total' => '10.5'],
        ['customer_id' => null, 'total' => '1.5'],
        ['customer_id' => $dupont->id, 'total' => '20.5'],
        ['customer_id' => null, 'total' => '1.5'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count'], ['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'customer_id'],
        'limit' => 1,
        'other' => true,
    ]);

    expect($result->series)->toBe([['key' => null, 'label' => null, 'values' => ['count' => 3, 'sum_total' => 4.5]]])
        ->and($result->other)->toBe(['count' => 2, 'sum_total' => 31.0, 'groups' => 2]);
});

it('limit without other still reports truncated', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'paid'], ['status' => 'paid'], ['status' => 'paid'],
        ['status' => 'new'], ['status' => 'new'],
        ['status' => 'shipped'],
    ]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'status'],
        'limit' => 2,
    ]);

    expect(array_column($result->series, 'key'))->toBe(['paid', 'new'])
        ->and($result->toArray()['data'])->not->toHaveKey('other')
        ->and($result->meta['truncated'])->toBeTrue()
        ->and($result->meta['group_count'])->toBe(3);
});

it('a limit above max_groups is TOO_MANY_GROUPS', function (): void {
    config(['core.aggregate.max_groups' => 2]);

    $refusal = aggregate_groups_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'status'],
        'limit' => 3,
    ]);

    expect($refusal->toArray())->toBe(['code' => 'TOO_MANY_GROUPS', 'message' => 'The limit is at most 2 groups.', 'field' => 'limit']);
});

it('holds a query built without its validator to max_groups', function (): void {
    config(['core.aggregate.max_groups' => 2]);
    $query = new AggregateQuery(
        measures: [new AggregateMeasure('count', null)],
        group: new AggregateGroup('status'),
        period: null,
        sortBy: 'measure',
        sortDir: 'desc',
        limit: 3,
        other: false,
        compare: false,
    );

    expect(fn (): AggregateResult => app(CrudService::class)->aggregate(AggregateTestKit::listData(new AggregateOrderStubModel), $query))
        ->toThrow(function (AggregateException $exception): void {
            expect($exception->toArray())->toBe(['code' => 'TOO_MANY_GROUPS', 'message' => 'The limit is at most 2 groups.', 'field' => 'limit']);
        });
});

it('holds a query built without its validator to the shape its figures need', function (array $shape, array $error): void {
    AggregateTestKit::seedOrders([['ordered_at' => '2026-01-15 12:00:00'], ['ordered_at' => '2026-02-15 12:00:00']]);
    $query = new AggregateQuery(...[
        'measures' => [new AggregateMeasure('count', null)],
        'group' => null,
        'period' => null,
        'sortBy' => 'measure',
        'sortDir' => 'desc',
        'limit' => null,
        'other' => false,
        'compare' => false,
        ...$shape,
    ]);

    expect(fn (): AggregateResult => app(CrudService::class)->aggregate(AggregateTestKit::listData(new AggregateOrderStubModel), $query))
        ->toThrow(function (AggregateException $exception) use ($error): void {
            expect([$exception->errorCode->value, $exception->field])->toBe($error);
        });
})->with([
    'a bucket with a limit' => [[
        'group' => new AggregateGroup('ordered_at', TimeBucket::Month),
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-03-31'],
        'sortBy' => 'key',
        'sortDir' => 'asc',
        'limit' => 1,
    ], ['INVALID_FIELD', 'limit']],
    'a bucket with the rest of the groups' => [[
        'group' => new AggregateGroup('ordered_at', TimeBucket::Month),
        'period' => ['field' => 'ordered_at', 'from' => '2026-01-01', 'to' => '2026-03-31'],
        'sortBy' => 'key',
        'sortDir' => 'asc',
        'other' => true,
    ], ['INVALID_FIELD', 'other']],
    'a limit with no group' => [['limit' => 1], ['INVALID_FIELD', 'limit']],
    'the rest of the groups with no group' => [['other' => true], ['INVALID_FIELD', 'other']],
    'a bucket with no period' => [['group' => new AggregateGroup('ordered_at', TimeBucket::Month)], ['INVALID_FIELD', 'period']],
    'a comparison with no period' => [['compare' => true], ['INVALID_FIELD', 'compare']],
]);

it('more groups than max_groups with no limit is TOO_MANY_GROUPS', function (): void {
    config(['core.aggregate.max_groups' => 2]);
    AggregateTestKit::seedOrders([['currency' => 'EUR'], ['currency' => 'USD'], ['currency' => null]]);

    $refusal = aggregate_groups_refusal([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'currency'],
    ]);

    expect($refusal->toArray())->toBe([
        'code' => 'TOO_MANY_GROUPS',
        'message' => 'Grouping by "currency" gives 3 groups, more than 2: narrow the request with filters or set a limit.',
        'field' => 'group_by.field',
    ]);
});

it('as many groups as max_groups with no limit are all shown', function (): void {
    config(['core.aggregate.max_groups' => 2]);
    AggregateTestKit::seedOrders([['currency' => 'EUR'], ['currency' => 'EUR'], ['currency' => null]]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'currency'],
    ]);

    expect(array_column($result->series, 'key'))->toBe(['EUR', null])
        ->and($result->meta['truncated'])->toBeFalse();
});

it('acl narrows the groups', function (): void {
    AggregateTestKit::seedOrders([
        ['status' => 'paid', 'currency' => 'EUR'],
        ['status' => 'new', 'currency' => 'GBP'],
        ['status' => 'paid', 'currency' => 'USD'],
        ['status' => 'new', 'currency' => 'GBP'],
        ['status' => 'paid', 'currency' => 'EUR'],
    ]);
    AggregateTestKit::asReader([AggregateOrderStubModel::class => new FiltersGroup([new Filter('status', 'paid')])]);

    $result = aggregate_groups([
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'currency'],
    ]);

    expect($result->series)->toBe([
        ['key' => 'EUR', 'label' => null, 'values' => ['count' => 2]],
        ['key' => 'USD', 'label' => null, 'values' => ['count' => 1]],
    ])
        ->and($result->total)->toBe(['count' => 3])
        ->and($result->meta['group_count'])->toBe(2);
});

it('a hidden column in group_by or sort is UNREADABLE_FIELD', function (array $request, string $field): void {
    $refusal = aggregate_groups_refusal($request, new AggregateRestrictedOrderStubModel);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::UnreadableField)
        ->and($refusal->field)->toBe($field);
})->with([
    'the group field' => [['measures' => [['fn' => 'count']], 'group_by' => ['field' => 'total']], 'group_by.field'],
    'the measure the sort follows' => [[
        'measures' => [['fn' => 'sum', 'field' => 'total']],
        'group_by' => ['field' => 'status'],
        'sort' => ['by' => 'measure', 'dir' => 'asc'],
    ], 'measures.0.field'],
]);

it('a relation to the same table is INVALID_FIELD on group_by.field', function (): void {
    Schema::table('aggregate_orders', function (Blueprint $table): void {
        $table->unsignedBigInteger('parent_id')->nullable();
    });
    SchemaInspector::getInstance()->clearTable('aggregate_orders');
    AggregateTestKit::seedOrders([['status' => 'new'], ['status' => 'paid']]);

    $refusal = aggregate_groups_refusal(['measures' => [['fn' => 'count']], 'group_by' => ['field' => 'parent.status']], new AggregateTreeOrderStubModel);

    expect($refusal->toArray())->toBe([
        'code' => 'INVALID_FIELD',
        'message' => '"parent.status" is a relation to the same table, which cannot be grouped on.',
        'field' => 'group_by.field',
    ]);
});

it('a multi-hop relation path is INVALID_FIELD', function (array $group, string $field): void {
    $refusal = aggregate_groups_refusal(['measures' => [['fn' => 'count']], 'group_by' => $group]);

    expect($refusal->errorCode)->toBe(AggregateErrorCode::InvalidField)
        ->and($refusal->field)->toBe($field);
})->with([
    'a group field' => [['field' => 'customer.orders.status'], 'group_by.field'],
    'a group label' => [['field' => 'customer_id', 'label' => 'customer.orders.status'], 'group_by.label'],
]);
