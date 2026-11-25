<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud\Aggregate;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Support\ColumnKinds;
use UnexpectedValueException;

/**
 * The SQL of the measures of an aggregate and the figures read back from it, shared by every path of the
 * {@see AggregateEngine}.
 *
 * A function is one of a closed list and a field is used only once it is a column of the model, then qualified by
 * its table and wrapped by the grammar of the connection. A figure is selected under a positional alias (`m0`,
 * `m1`, ...) and named after its measure in PHP, so no text of the request reaches SQL. The currency partials of
 * the amounts ({@see CurrencyCheck}) travel in the same queries.
 */
final class MeasureSql
{
    private const string ROW_COUNT_ALIAS = 'row_count';

    /**
     * The alias the figure of the measure at the index is selected under.
     */
    public static function alias(int $index): string
    {
        return 'm' . $index;
    }

    /**
     * One query over every row of the scope: each measure, and the number of rows.
     *
     * The ordering of the base query is dropped: the default ordering of a model means nothing to an aggregate,
     * and an ORDER BY on a plain column next to an aggregate function is refused by MySQL (only_full_group_by)
     * and by PostgreSQL.
     *
     * The total of the rows of the result is the one whose mix of currencies the result names; the total of the
     * previous period ($previous) is checked like any other cell but is not named there.
     *
     * @param  list<AggregateMeasure>  $measures
     *
     * @throws AggregateException
     *
     * @return array{values: array<string, int|float|string|null>, row_count: int}
     */
    public static function totals(AggregateScope $scope, array $measures, CurrencyCheck $currency, bool $previous = false): array
    {
        $query = ($scope->baseQuery)()->toBase()->reorder();
        $grammar = $query->getGrammar();

        $select = self::select($scope->model, $grammar, $measures, $currency);
        $select[] = new Expression('count(*) as ' . $grammar->wrap(self::ROW_COUNT_ALIAS));

        $row = (array) $query->select($select)->first();
        $values = self::figures($row, $measures);

        return [
            'values' => $previous ? $currency->settle([$row], $values) : $currency->settleTotal($row, $values),
            'row_count' => (int) self::figure($row[self::ROW_COUNT_ALIAS] ?? 0),
        ];
    }

    /**
     * The select of the measures, each under its positional alias, and the currency partials of their amounts.
     *
     * @param  list<AggregateMeasure>  $measures
     *
     * @throws AggregateException
     *
     * @return list<ExpressionContract>
     */
    public static function select(Model $model, Grammar $grammar, array $measures, CurrencyCheck $currency): array
    {
        $select = [];

        foreach ($measures as $index => $measure) {
            $select[] = new Expression(self::expression($model, $grammar, $measure, $index) . ' as ' . $grammar->wrap(self::alias($index)));
        }

        return [...$select, ...$currency->select($model, $grammar)];
    }

    /**
     * The function of the measure, checked against the closed list.
     *
     * @throws AggregateException UNSUPPORTED_FUNCTION on `measures.<index>.fn`
     *
     * @return 'count'|'sum'|'avg'|'min'|'max'
     */
    public static function functionOf(AggregateMeasure $measure, int $index): string
    {
        return match ($measure->fn) {
            'count' => 'count',
            'sum' => 'sum',
            'avg' => 'avg',
            'min' => 'min',
            'max' => 'max',
            default => throw new AggregateException(
                AggregateErrorCode::UnsupportedFunction,
                'The function of a measure is one of count, sum, avg, min, max.',
                'measures.' . $index . '.fn',
            ),
        };
    }

    /**
     * The qualified, wrapped column of the measure; null for a count of the rows.
     *
     * @throws AggregateException INVALID_FIELD on `measures.<index>.field` when the field is not a column of the model, or a function other than count has none
     */
    public static function column(Model $model, Grammar $grammar, AggregateMeasure $measure, int $index): ?string
    {
        $function = self::functionOf($measure, $index);

        if ($measure->field === null) {
            if ($function !== 'count') {
                throw new AggregateException(
                    AggregateErrorCode::InvalidField,
                    sprintf('The function "%s" needs a field.', $function),
                    'measures.' . $index . '.field',
                );
            }

            return null;
        }

        if (! ColumnKinds::exists($model, $measure->field)) {
            throw new AggregateException(
                AggregateErrorCode::InvalidField,
                sprintf('"%s" is not a column of the entity.', $measure->field),
                'measures.' . $index . '.field',
            );
        }

        return $grammar->wrap($model->qualifyColumn($measure->field));
    }

    /**
     * A figure as JSON carries it (ruling R8): a number stays a number, and a numeric string, as which a driver
     * may return a decimal or a count, becomes the number it spells. Any other text is the minimum or the maximum
     * of a date-time column, and stays as the database holds it.
     */
    public static function figure(mixed $value): int|float|string|null
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return is_numeric($value) ? 0 + $value : $value;
        }

        throw new UnexpectedValueException(sprintf('An aggregate function returned %s, which is neither a number nor a text.', get_debug_type($value)));
    }

    /**
     * The figures of a row read by {@see self::select()}, named after their measures.
     *
     * @param  array<array-key, mixed>  $row
     * @param  list<AggregateMeasure>  $measures
     * @return array<string, int|float|string|null>
     */
    public static function figures(array $row, array $measures): array
    {
        $values = [];

        foreach ($measures as $index => $measure) {
            $values[$measure->name()] = self::figure($row[self::alias($index)] ?? null);
        }

        return $values;
    }

    /**
     * The SQL of one measure: `count(*)`, or a function of the closed list over the qualified, wrapped column.
     *
     * @throws AggregateException
     */
    private static function expression(Model $model, Grammar $grammar, AggregateMeasure $measure, int $index): string
    {
        $column = self::column($model, $grammar, $measure, $index);

        return self::functionOf($measure, $index) . '(' . ($column ?? '*') . ')';
    }
}
