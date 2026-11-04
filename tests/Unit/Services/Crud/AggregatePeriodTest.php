<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Enums\TimeBucket;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Services\Crud\Aggregate\AggregatePeriod;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
});

afterEach(function (): void {
    config(['app.timezone' => 'UTC']);
});

it('resolves a period against a fixed now', function (string $now, array $spec, string $from, string $to): void {
    $period = AggregatePeriod::resolve(['field' => 'created_at', ...$spec], CarbonImmutable::parse($now, 'UTC'));

    expect($period->field)->toBe('created_at')
        ->and($period->from->format('Y-m-d H:i:s'))->toBe($from . ' 00:00:00')
        ->and($period->to->format('Y-m-d H:i:s'))->toBe($to . ' 00:00:00');
})->with([
    'last 12 months, partial' => ['2026-10-20', ['last' => ['n' => 12, 'unit' => 'month']], '2025-11-01', '2026-10-20'],
    'last 1 month is the current one' => ['2026-10-20', ['last' => ['n' => 1, 'unit' => 'month']], '2026-10-01', '2026-10-20'],
    'last 12 months from a 31st' => ['2026-03-31', ['last' => ['n' => 12, 'unit' => 'month']], '2025-04-01', '2026-03-31'],
    'last 7 days' => ['2026-10-20', ['last' => ['n' => 7, 'unit' => 'day']], '2026-10-14', '2026-10-20'],
    'last 1 day is today' => ['2026-10-20', ['last' => ['n' => 1, 'unit' => 'day']], '2026-10-20', '2026-10-20'],
    'last 3 weeks starts on the Monday two weeks before this one' => ['2026-10-20', ['last' => ['n' => 3, 'unit' => 'week']], '2026-10-05', '2026-10-20'],
    'last 2 quarters' => ['2026-10-20', ['last' => ['n' => 2, 'unit' => 'quarter']], '2026-07-01', '2026-10-20'],
    'last 2 years' => ['2026-10-20', ['last' => ['n' => 2, 'unit' => 'year']], '2025-01-01', '2026-10-20'],
    'a now with a time of day' => ['2026-10-20 23:59:59', ['last' => ['n' => 7, 'unit' => 'day']], '2026-10-14', '2026-10-20'],
    'preset today' => ['2026-10-20', ['preset' => 'today'], '2026-10-20', '2026-10-20'],
    'preset yesterday' => ['2026-10-20', ['preset' => 'yesterday'], '2026-10-19', '2026-10-19'],
    'preset this_week runs to the Sunday' => ['2026-10-20', ['preset' => 'this_week'], '2026-10-19', '2026-10-25'],
    'preset last_week' => ['2026-10-20', ['preset' => 'last_week'], '2026-10-12', '2026-10-18'],
    'preset this_month runs to the last day' => ['2026-10-20', ['preset' => 'this_month'], '2026-10-01', '2026-10-31'],
    'preset last_month' => ['2026-10-20', ['preset' => 'last_month'], '2026-09-01', '2026-09-30'],
    'preset last_month from a 31st into a short month' => ['2026-03-31', ['preset' => 'last_month'], '2026-02-01', '2026-02-28'],
    'preset last_month across a year end' => ['2026-01-15', ['preset' => 'last_month'], '2025-12-01', '2025-12-31'],
    'preset this_quarter' => ['2026-10-20', ['preset' => 'this_quarter'], '2026-10-01', '2026-12-31'],
    'preset last_quarter' => ['2026-10-20', ['preset' => 'last_quarter'], '2026-07-01', '2026-09-30'],
    'preset last_quarter across a year end' => ['2026-01-15', ['preset' => 'last_quarter'], '2025-10-01', '2025-12-31'],
    'preset this_year' => ['2026-10-20', ['preset' => 'this_year'], '2026-01-01', '2026-12-31'],
    'preset last_year' => ['2026-10-20', ['preset' => 'last_year'], '2025-01-01', '2025-12-31'],
    'preset last_week across a year end' => ['2026-01-01', ['preset' => 'last_week'], '2025-12-22', '2025-12-28'],
    'a custom range' => ['2026-10-20', ['from' => '2026-07-01', 'to' => '2026-09-30'], '2026-07-01', '2026-09-30'],
    'a custom range of one day' => ['2026-10-20', ['from' => '2026-02-28', 'to' => '2026-02-28'], '2026-02-28', '2026-02-28'],
    'a custom range in the future' => ['2026-10-20', ['from' => '2027-01-01', 'to' => '2027-01-31'], '2027-01-01', '2027-01-31'],
]);

it('refuses a malformed spec as INVALID_FIELD on the period', function (array $spec): void {
    $resolve = static fn (): AggregatePeriod => AggregatePeriod::resolve($spec, CarbonImmutable::parse('2026-10-20', 'UTC'));

    expect($resolve)->toThrow(static fn (AggregateException $exception) => expect($exception->errorCode)->toBe(AggregateErrorCode::InvalidField)
        ->and($exception->field)->toBe('period'));
})->with([
    'no field' => [['preset' => 'today']],
    'an empty field' => [['field' => '', 'preset' => 'today']],
    'a field that is not text' => [['field' => 12, 'preset' => 'today']],
    'no way to say the period' => [['field' => 'created_at']],
    'last and preset together' => [['field' => 'created_at', 'last' => ['n' => 1, 'unit' => 'day'], 'preset' => 'today']],
    'preset and a range together' => [['field' => 'created_at', 'preset' => 'today', 'from' => '2026-01-01', 'to' => '2026-01-31']],
    'last and a range together' => [['field' => 'created_at', 'last' => ['n' => 1, 'unit' => 'day'], 'from' => '2026-01-01', 'to' => '2026-01-31']],
    'from without to' => [['field' => 'created_at', 'from' => '2026-01-01']],
    'to without from' => [['field' => 'created_at', 'to' => '2026-01-31']],
    'last that is not a list' => [['field' => 'created_at', 'last' => 12]],
    'last without a count' => [['field' => 'created_at', 'last' => ['unit' => 'month']]],
    'last without a unit' => [['field' => 'created_at', 'last' => ['n' => 12]]],
    'a count of zero' => [['field' => 'created_at', 'last' => ['n' => 0, 'unit' => 'month']]],
    'a negative count' => [['field' => 'created_at', 'last' => ['n' => -3, 'unit' => 'month']]],
    'a count that is not an integer' => [['field' => 'created_at', 'last' => ['n' => 1.5, 'unit' => 'month']]],
    'a count that is text' => [['field' => 'created_at', 'last' => ['n' => 'twelve', 'unit' => 'month']]],
    'a count beyond what a date can follow' => [['field' => 'created_at', 'last' => ['n' => PHP_INT_MAX, 'unit' => 'year']]],
    'an unknown unit' => [['field' => 'created_at', 'last' => ['n' => 2, 'unit' => 'fortnight']]],
    'a plural unit' => [['field' => 'created_at', 'last' => ['n' => 2, 'unit' => 'months']]],
    'a unit that is not text' => [['field' => 'created_at', 'last' => ['n' => 2, 'unit' => 3]]],
    'an unknown preset' => [['field' => 'created_at', 'preset' => 'next_month']],
    'a preset that is not text' => [['field' => 'created_at', 'preset' => ['today']]],
    'from after to' => [['field' => 'created_at', 'from' => '2026-10-02', 'to' => '2026-10-01']],
    'a date that is not a date' => [['field' => 'created_at', 'from' => 'tomorrow', 'to' => '2026-10-01']],
    'a date that does not exist' => [['field' => 'created_at', 'from' => '2026-02-30', 'to' => '2026-03-31']],
    'a date with a month of 13' => [['field' => 'created_at', 'from' => '2026-13-01', 'to' => '2027-01-31']],
    'a date without zero padding' => [['field' => 'created_at', 'from' => '2026-1-1', 'to' => '2026-01-31']],
    'a date with a time' => [['field' => 'created_at', 'from' => '2026-01-01', 'to' => '2026-01-31 23:59:59']],
    'a date that is not text' => [['field' => 'created_at', 'from' => 20260101, 'to' => '2026-01-31']],
]);

it('builds the period in the application time zone', function (): void {
    config(['app.timezone' => 'Europe/Rome']);

    $now = CarbonImmutable::parse('2026-10-20 23:30:00', 'UTC');
    $today = AggregatePeriod::resolve(['field' => 'created_at', 'preset' => 'today'], $now);
    $custom = AggregatePeriod::resolve(['field' => 'created_at', 'from' => '2026-10-01', 'to' => '2026-10-02'], $now);

    expect($today->from->format('Y-m-d H:i:s'))->toBe('2026-10-21 00:00:00')
        ->and($today->from->getTimezone()->getName())->toBe('Europe/Rome')
        ->and($today->to->getTimezone()->getName())->toBe('Europe/Rome')
        ->and($custom->from->getTimezone()->getName())->toBe('Europe/Rome')
        ->and($custom->to->getTimezone()->getName())->toBe('Europe/Rome');
});

it('counts the days of a period inclusively', function (array $spec, int $expected): void {
    $period = AggregatePeriod::resolve(['field' => 'created_at', ...$spec], CarbonImmutable::parse('2026-10-20', 'UTC'));

    expect($period->days())->toBe($expected);
})->with([
    'today' => [['preset' => 'today'], 1],
    'a week' => [['preset' => 'this_week'], 7],
    'a month of 31 days' => [['preset' => 'this_month'], 31],
    'a month of 30 days' => [['preset' => 'last_month'], 30],
    'a quarter' => [['preset' => 'this_quarter'], 92],
    'a year' => [['preset' => 'this_year'], 365],
    'a leap year' => [['from' => '2028-01-01', 'to' => '2028-12-31'], 366],
    'a partial last 12 months' => [['last' => ['n' => 12, 'unit' => 'month']], 354],
]);

it('counts a day that has 25 hours as one day', function (): void {
    config(['app.timezone' => 'Europe/Rome']);

    $period = AggregatePeriod::resolve(
        ['field' => 'created_at', 'from' => '2026-10-24', 'to' => '2026-10-26'],
        CarbonImmutable::parse('2026-10-20', 'UTC'),
    );

    expect($period->days())->toBe(3);
});

it('shifts a period of whole months by that many calendar months', function (array $spec, string $from, string $to): void {
    $previous = AggregatePeriod::resolve(['field' => 'created_at', ...$spec], CarbonImmutable::parse('2026-10-20', 'UTC'))->previous();

    expect($previous->field)->toBe('created_at')
        ->and($previous->from->format('Y-m-d H:i:s'))->toBe($from . ' 00:00:00')
        ->and($previous->to->format('Y-m-d H:i:s'))->toBe($to . ' 00:00:00');
})->with([
    'this_month is September' => [['preset' => 'this_month'], '2026-09-01', '2026-09-30'],
    'this_quarter is the third quarter' => [['preset' => 'this_quarter'], '2026-07-01', '2026-09-30'],
    'last_quarter is the second quarter' => [['preset' => 'last_quarter'], '2026-04-01', '2026-06-30'],
    'this_year is the year before' => [['preset' => 'this_year'], '2025-01-01', '2025-12-31'],
    'a month across a year end' => [['from' => '2026-01-01', 'to' => '2026-01-31'], '2025-12-01', '2025-12-31'],
    'two months ending in a short month are not 59 days' => [['from' => '2026-01-01', 'to' => '2026-02-28'], '2025-11-01', '2025-12-31'],
    'a month ending on the 29th of February' => [['from' => '2028-02-01', 'to' => '2028-02-29'], '2028-01-01', '2028-01-31'],
    'last_month is August' => [['preset' => 'last_month'], '2026-08-01', '2026-08-31'],
]);

it('shifts any other period by its own length in days', function (array $spec, string $from, string $to): void {
    $previous = AggregatePeriod::resolve(['field' => 'created_at', ...$spec], CarbonImmutable::parse('2026-10-20', 'UTC'))->previous();

    expect($previous->from->format('Y-m-d H:i:s'))->toBe($from . ' 00:00:00')
        ->and($previous->to->format('Y-m-d H:i:s'))->toBe($to . ' 00:00:00');
})->with([
    'a partial last 12 months is the 354 days before it' => [['last' => ['n' => 12, 'unit' => 'month']], '2024-11-12', '2025-10-31'],
    'a partial month from its first day' => [['last' => ['n' => 1, 'unit' => 'month']], '2026-09-11', '2026-09-30'],
    'a week is the week before' => [['preset' => 'this_week'], '2026-10-12', '2026-10-18'],
    'today is yesterday' => [['preset' => 'today'], '2026-10-19', '2026-10-19'],
    'a range that ends on a last day but does not start on a first' => [['from' => '2026-10-15', 'to' => '2026-10-31'], '2026-09-28', '2026-10-14'],
    'a range that starts on a first day but does not end on a last' => [['from' => '2026-10-01', 'to' => '2026-10-14'], '2026-09-17', '2026-09-30'],
    'a custom range' => [['from' => '2026-10-10', 'to' => '2026-10-14'], '2026-10-05', '2026-10-09'],
]);

it('shifts by days on the calendar across a change of the clock', function (): void {
    config(['app.timezone' => 'Europe/Rome']);

    $previous = AggregatePeriod::resolve(
        ['field' => 'created_at', 'from' => '2026-10-26', 'to' => '2026-10-27'],
        CarbonImmutable::parse('2026-10-20', 'UTC'),
    )->previous();

    expect($previous->from->format('Y-m-d H:i:s'))->toBe('2026-10-24 00:00:00')
        ->and($previous->to->format('Y-m-d H:i:s'))->toBe('2026-10-25 00:00:00');
});

it('counts and shifts the days of a period across the spring change of the clock', function (): void {
    config(['app.timezone' => 'Europe/Rome']);

    // 2026-03-29 has 23 hours in Rome: the clock moves from 02:00 to 03:00.
    $period = AggregatePeriod::resolve(
        ['field' => 'created_at', 'from' => '2026-03-28', 'to' => '2026-03-30'],
        CarbonImmutable::parse('2026-03-20', 'UTC'),
    );
    $previous = $period->previous();

    expect($period->days())->toBe(3)
        ->and($period->from->format('Y-m-d H:i:s'))->toBe('2026-03-28 00:00:00')
        ->and($period->to->format('Y-m-d H:i:s'))->toBe('2026-03-30 00:00:00')
        ->and($previous->from->format('Y-m-d H:i:s'))->toBe('2026-03-25 00:00:00')
        ->and($previous->to->format('Y-m-d H:i:s'))->toBe('2026-03-27 00:00:00')
        ->and($previous->days())->toBe(3);
});

it('names the units of last when it refuses one', function (): void {
    $resolve = static fn (): AggregatePeriod => AggregatePeriod::resolve(
        ['field' => 'created_at', 'last' => ['n' => 2, 'unit' => 'fortnight']],
        CarbonImmutable::parse('2026-10-20', 'UTC'),
    );

    expect($resolve)->toThrow(AggregateException::class, 'one of day, week, month, quarter, year');
});

it('lists the buckets from the one of the first day to the one of the last', function (TimeBucket $bucket, array $spec, array $expected): void {
    $period = AggregatePeriod::resolve(['field' => 'created_at', ...$spec], CarbonImmutable::parse('2026-10-20', 'UTC'));

    $keys = array_map(static fn (CarbonImmutable $start): string => $bucket->key($start), $period->buckets($bucket));

    expect($keys)->toBe($expected);
})->with([
    'a month is the whole month' => [TimeBucket::Month, ['preset' => 'this_month'], ['2026-10-01']],
    'weeks reach back to the Monday before the first day' => [
        TimeBucket::Week,
        ['preset' => 'this_month'],
        ['2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'],
    ],
    'days' => [TimeBucket::Day, ['last' => ['n' => 3, 'unit' => 'day']], ['2026-10-18', '2026-10-19', '2026-10-20']],
    'a single day' => [TimeBucket::Day, ['preset' => 'today'], ['2026-10-20']],
    'quarters across a year end' => [TimeBucket::Quarter, ['from' => '2025-11-15', 'to' => '2026-02-10'], ['2025-10-01', '2026-01-01']],
    'years' => [TimeBucket::Year, ['from' => '2024-12-31', 'to' => '2026-01-01'], ['2024-01-01', '2025-01-01', '2026-01-01']],
]);

it('lists twelve months for the last 12 months', function (): void {
    $period = AggregatePeriod::resolve(
        ['field' => 'created_at', 'last' => ['n' => 12, 'unit' => 'month']],
        CarbonImmutable::parse('2026-10-20', 'UTC'),
    );

    $months = $period->buckets(TimeBucket::Month);

    expect($months)->toHaveCount(12)
        ->and($months[0]->format('Y-m-d'))->toBe('2025-11-01')
        ->and($months[11]->format('Y-m-d'))->toBe('2026-10-01');
});

it('describes the resolved period for the meta of a result', function (): void {
    $period = AggregatePeriod::resolve(
        ['field' => 'created_at', 'last' => ['n' => 12, 'unit' => 'month']],
        CarbonImmutable::parse('2026-10-20', 'UTC'),
    );

    expect($period->toMeta())->toBe([
        'field' => 'created_at',
        'from' => '2025-11-01',
        'to' => '2026-10-20',
        'timezone' => 'UTC',
    ]);
});

it('names the application time zone in the meta', function (): void {
    config(['app.timezone' => 'Europe/Rome']);

    $period = AggregatePeriod::resolve(['field' => 'created_at', 'preset' => 'today'], CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    expect($period->toMeta()['timezone'])->toBe('Europe/Rome');
});
