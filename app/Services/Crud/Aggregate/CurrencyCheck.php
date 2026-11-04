<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud\Aggregate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Enums\ColumnKind;
use Modules\Core\Enums\TimeBucket;
use Modules\Core\Support\ColumnKinds;
use Modules\Core\Support\CurrencyColumns;

/**
 * The currency of the figures of one aggregate run (spec 4.6): it adds the currency partials to the queries, turns
 * a cell that adds amounts of more than one currency into a `null` value, and says in the `meta` of the result what
 * it found.
 *
 * A `sum`, `avg`, `min` or `max` over a numeric field that is not a key and has a currency column
 * ({@see CurrencyColumns::for()}) selects `min(<currency>)` and `max(<currency>)` beside its figure, in every query
 * of the run. The earliest date or the greatest key of rows in two currencies is no amount, so it is left alone.
 *
 * A cell is a series entry, a bucket (the partials of its days), `other`, or a total. It is mixed when its partials
 * name more than one currency: the value of that measure is then `null`, and the measure gets one warning, however
 * many cells mix.
 *
 * A row with no currency has none, and an empty string is no currency either: the partials read the column through
 * `nullif(<currency>, '')`, so `min` and `max` leave a blank out exactly as they leave a null out (a blank would
 * otherwise sort first and hide the least real currency). A cell with one currency beside rows with no currency
 * keeps its value and has that currency, and a cell with no currency at all has none.
 *
 * Grouping by the currency column of an amount makes every group single-currency, so no group can mix there; the
 * total and the `other` that add the groups up do, and are left empty without a warning. A `count` is never checked.
 *
 * One instance serves one run and keeps what its cells showed: the engine reads {@see self::meta()} once, after
 * every cell was settled.
 */
final class CurrencyCheck
{
    private const array AMOUNT_FUNCTIONS = ['sum', 'avg', 'min', 'max'];

    /**
     * How many currencies `meta.currencies` names at most.
     */
    private const int MAX_CURRENCIES = 20;

    private const string MET_ALIAS = 'met_currency';

    /**
     * The measures that have a mixed cell and so get a warning, by the index of the measure.
     *
     * @var array<int, true>
     */
    private array $mixed = [];

    /**
     * The measures whose total is mixed, by the index of the measure.
     *
     * @var array<int, true>
     */
    private array $mixedTotal = [];

    /**
     * Whether any cell of the run is mixed, a split one too.
     */
    private bool $anyMixed = false;

    /**
     * The currencies the cells of the run showed.
     *
     * @var list<string>
     */
    private array $met = [];

    /**
     * @param  array<int, array{name: string, column: string, split: bool}>  $amounts  The amounts of the run that have a currency column, by the index of their measure.
     */
    private function __construct(private readonly array $amounts) {}

    /**
     * The amounts of the query that have a currency column. A measure with an unknown function or field is left to
     * {@see MeasureSql}, which refuses it before any query runs.
     */
    public static function for(Model $model, AggregateQuery $query): self
    {
        $group = $query->group;
        $amounts = [];

        foreach ($query->measures as $index => $measure) {
            $column = self::columnOf($model, $measure);

            if ($column === null) {
                continue;
            }

            $amounts[$index] = [
                'name' => $measure->name(),
                'column' => $column,
                'split' => $group instanceof AggregateGroup && ! $group->bucket instanceof TimeBucket && $group->field === $column,
            ];
        }

        return new self($amounts);
    }

    /**
     * The currency column the run reads beside the measure, or null when it reads none: the measure is a `sum`,
     * `avg`, `min` or `max` over an amount (a numeric column that is not a key) and the table has a currency column
     * for it ({@see CurrencyColumns::for()}). Its values reach the result as `meta.currency`, `meta.currencies` and
     * the warnings, so the service refuses a measure whose currency column the caller may not read before any query
     * runs.
     */
    public static function columnOf(Model $model, AggregateMeasure $measure): ?string
    {
        if ($measure->field === null || ! in_array($measure->fn, self::AMOUNT_FUNCTIONS, true) || ! self::isAmount($model, $measure->field)) {
            return null;
        }

        return CurrencyColumns::for($model, $measure->field);
    }

    /**
     * The currency partials of the amounts, to select beside their figures: the least and the greatest currency of
     * the rows that fall in a cell.
     *
     * @return list<Expression<string>>
     */
    public function select(Model $model, Grammar $grammar): array
    {
        $select = [];

        foreach ($this->amounts as $index => $amount) {
            $currency = self::present($grammar->wrap($model->qualifyColumn($amount['column'])));

            $select[] = new Expression('min(' . $currency . ') as ' . $grammar->wrap(self::alias($index, 'min')));
            $select[] = new Expression('max(' . $currency . ') as ' . $grammar->wrap(self::alias($index, 'max')));
        }

        return $select;
    }

    /**
     * The values of a cell, with the value of each mixed amount turned into `null`.
     *
     * @param  list<array<array-key, mixed>>  $partials  The rows that were read for the cell: one, or the days of a bucket.
     * @param  array<string, int|float|string|null>  $values
     * @return array<string, int|float|string|null>
     */
    public function settle(array $partials, array $values): array
    {
        return $this->read($partials, $values, false);
    }

    /**
     * {@see self::settle()} for the total of the rows of the result, whose mix of currencies the `meta` names.
     *
     * @param  array<array-key, mixed>  $row
     * @param  array<string, int|float|string|null>  $values
     * @return array<string, int|float|string|null>
     */
    public function settleTotal(array $row, array $values): array
    {
        return $this->read([$row], $values, true);
    }

    /**
     * What the run found about currencies, for the `meta` of the result.
     *
     * - `currency`: the one currency of every cell, when no cell is mixed.
     * - `warnings`: one `MIXED_CURRENCY` per measure that has a mixed cell.
     * - `currencies`: the currencies of the rows of the result, at most twenty, read with one query when its total
     *   is mixed.
     *
     * @return array{currency?: string, currencies?: list<string>, warnings?: list<array{code: string, field: string, message: string}>}
     */
    public function meta(AggregateScope $scope): array
    {
        if ($this->mixed === []) {
            return ! $this->anyMixed && count($this->met) === 1 ? ['currency' => $this->met[0]] : [];
        }

        $meta = [];

        if ($this->mixedTotal !== []) {
            $meta['currencies'] = $this->currencies($scope);
        }

        $meta['warnings'] = [];

        foreach ($this->amounts as $index => $amount) {
            if (isset($this->mixed[$index])) {
                $meta['warnings'][] = [
                    'code' => AggregateErrorCode::MixedCurrency->value,
                    'field' => $amount['name'],
                    'message' => sprintf('"%s" adds amounts in more than one currency, so it has no value: group by "%s" to get one value per currency.', $amount['name'], $amount['column']),
                ];
            }
        }

        return $meta;
    }

    private static function isAmount(Model $model, string $field): bool
    {
        return ColumnKinds::exists($model, $field)
            && ColumnKinds::of($model, $field) === ColumnKind::Numeric
            && ! ColumnKinds::isKey($model, $field);
    }

    private static function alias(int $index, string $function): string
    {
        return 'c' . $index . '_' . $function;
    }

    /**
     * The wrapped column read as null when it holds an empty string. `nullif` is standard SQL and does not change
     * Oracle, which stores an empty string as null already (a `case when <currency> <> '' ...` would hide every
     * currency there).
     */
    private static function present(string $wrapped): string
    {
        return 'nullif(' . $wrapped . ", '')";
    }

    /**
     * A currency as the database gave it, or null when the row has none. The queries leave blanks out, so a value
     * is used as it comes.
     */
    private static function currency(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * @param  list<array<array-key, mixed>>  $partials
     * @param  array<string, int|float|string|null>  $values
     * @return array<string, int|float|string|null>
     */
    private function read(array $partials, array $values, bool $total): array
    {
        foreach ($this->amounts as $index => $amount) {
            $currencies = $this->currenciesOf($partials, $index);

            foreach ($currencies as $currency) {
                if (! in_array($currency, $this->met, true)) {
                    $this->met[] = $currency;
                }
            }

            if (count($currencies) < 2) {
                continue;
            }

            $values[$amount['name']] = null;
            $this->anyMixed = true;

            if ($amount['split']) {
                continue;
            }

            $this->mixed[$index] = true;

            if ($total) {
                $this->mixedTotal[$index] = true;
            }
        }

        return $values;
    }

    /**
     * The distinct currencies the partials of an amount name, in the order they appear. Comparing every minimum and
     * every maximum, not only the least of the first and the greatest of the second, keeps the answer the same
     * whichever way the database and PHP order the codes.
     *
     * @param  list<array<array-key, mixed>>  $partials
     * @return list<string>
     */
    private function currenciesOf(array $partials, int $index): array
    {
        $currencies = [];

        foreach ($partials as $partial) {
            foreach (['min', 'max'] as $function) {
                $currency = self::currency($partial[self::alias($index, $function)] ?? null);

                if ($currency !== null && ! in_array($currency, $currencies, true)) {
                    $currencies[] = $currency;
                }
            }
        }

        return $currencies;
    }

    /**
     * The distinct currencies of the rows of the scope, in the columns of the amounts whose total is mixed, bounded
     * to {@see self::MAX_CURRENCIES} in one query (a union when the amounts have more than one column).
     *
     * @return list<string>
     */
    private function currencies(AggregateScope $scope): array
    {
        $model = $scope->model;
        $columns = array_unique(array_map(fn (int $index): string => $this->amounts[$index]['column'], array_keys($this->mixedTotal)));
        $query = null;

        foreach ($columns as $column) {
            $rows = ($scope->baseQuery)()->toBase()->reorder();
            $grammar = $rows->getGrammar();
            $currency = self::present($grammar->wrap($model->qualifyColumn($column)));

            $rows->select(new Expression($currency . ' as ' . $grammar->wrap(self::MET_ALIAS)))
                ->whereRaw($currency . ' is not null')
                ->distinct();

            $query = $query instanceof BaseQueryBuilder ? $query->union($rows) : $rows;
        }

        if (! $query instanceof BaseQueryBuilder) {
            return [];
        }

        $currencies = [];

        foreach ($query->orderBy(self::MET_ALIAS)->limit(self::MAX_CURRENCIES)->get() as $row) {
            $currency = self::currency(((array) $row)[self::MET_ALIAS] ?? null);

            if ($currency !== null) {
                $currencies[] = $currency;
            }
        }

        return $currencies;
    }
}
