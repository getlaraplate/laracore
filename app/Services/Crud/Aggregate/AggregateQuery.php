<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud\Aggregate;

use Carbon\CarbonImmutable;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Enums\TimeBucket;
use Modules\Core\Exceptions\AggregateException;

/**
 * An aggregate request as a value, checked for its shape and for the limits that need no query.
 *
 * The route, a saved widget and the assistant tool all build one with {@see self::fromArray()}, so they share
 * one validator. What needs the model (is a field there, may the caller read it, can it be measured) is the
 * engine's, and so is the `resource` the request is about: this value holds the request without it, and
 * without the list `filters`, which travel with the list vocabulary.
 *
 * A malformed request is an {@see AggregateException} that names the offending part by its path in the
 * request (`measures.0.fn`). The values a GET caller can only send as text (a count, a limit) are read as the
 * integers they spell, and what is stored is the normalized form, so {@see self::toArray()} and
 * {@see self::canonical()} do not depend on how the client wrote the request.
 */
final readonly class AggregateQuery
{
    private const array FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    private const array SORT_BYS = ['measure', 'key'];

    private const array SORT_DIRECTIONS = ['asc', 'desc'];

    private const string COMPARE_PREVIOUS_PERIOD = 'previous_period';

    /**
     * The digits of the greatest whole number read as it is. A text of digits with more of them, or a float as
     * large as that, is read as the greatest integer: it is not a count or a limit anyone asks for, a float that
     * large does not cast to an integer, and the check that follows (the limit of groups, the count of a period)
     * refuses it for being too large, not for being malformed.
     */
    private const int MAX_WHOLE_DIGITS = 15;

    private const float MAX_WHOLE_NUMBER = 1.0E15;

    /**
     * @param  list<AggregateMeasure>  $measures
     * @param  array<string, mixed>|null  $period  The spec {@see AggregatePeriod::resolve()} reads, validated and normalized.
     * @param  string  $sortBy  `measure` (the first measure) or `key` (the group key, which is chronological for a bucket).
     * @param  'asc'|'desc'  $sortDir
     */
    public function __construct(
        public array $measures,
        public ?AggregateGroup $group,
        public ?array $period,
        public string $sortBy,
        public string $sortDir,
        public ?int $limit,
        public bool $other,
        public bool $compare,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input  The request: `measures`, and optionally `group_by`, `period`, `sort`, `limit`,
     *                                          `other` and `compare` (`previous_period`, or `true`). Other keys are ignored.
     *
     * @throws AggregateException
     */
    public static function fromArray(array $input, AggregateLimits $limits): self
    {
        $measures = self::measures($input['measures'] ?? null, $limits);
        $group = self::group($input['group_by'] ?? null);
        $period = self::period($input['period'] ?? null);
        $limit = self::limit($input['limit'] ?? null, $limits);
        $other = self::other($input['other'] ?? null);
        $compare = self::compare($input['compare'] ?? null);
        $bucketed = $group?->bucket instanceof TimeBucket;

        if ($bucketed && $period === null) {
            throw self::invalid('period', 'A group by a time bucket needs a period to cut into buckets.');
        }

        if ($compare && $period === null) {
            throw self::invalid('compare', 'A comparison needs a period to compare with.');
        }

        if ($other && $limit === null) {
            throw self::invalid('other', 'The rest of the groups is shown after a limit: give a limit.');
        }

        self::assertGroupOptions($group, $limit, $other);

        [$sortBy, $sortDir] = self::sort($input['sort'] ?? null, $bucketed);

        return new self($measures, $group, $period, $sortBy, $sortDir, $limit, $other, $compare);
    }

    /**
     * Refuses a limit or the rest of the groups on a request that has no groups of a column to cut: no group, or a
     * group by a time bucket, whose buckets are all shown. The validator and the engine both call it, so a query
     * built without the validator is not silently left unlimited.
     *
     * @throws AggregateException INVALID_FIELD on `limit` or `other`
     */
    public static function assertGroupOptions(?AggregateGroup $group, ?int $limit, bool $other): void
    {
        if ($group instanceof AggregateGroup && ! $group->bucket instanceof TimeBucket) {
            return;
        }

        if ($limit !== null) {
            throw self::invalid('limit', 'A limit applies to the groups of a column: group by a column that is not a time bucket.');
        }

        if ($other) {
            throw self::invalid('other', 'The rest of the groups applies to the groups of a column: group by a column that is not a time bucket.');
        }
    }

    /**
     * The normalized request, in the shape {@see self::fromArray()} reads and without `kind` and `resource`.
     * What the request left out is left out, except the sort, which is always the one that applies.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $request = [
            'measures' => array_map(
                static fn (AggregateMeasure $measure): array => $measure->field === null
                    ? ['fn' => $measure->fn]
                    : ['fn' => $measure->fn, 'field' => $measure->field],
                $this->measures,
            ),
        ];

        if ($this->group instanceof AggregateGroup) {
            $request['group_by'] = ['field' => $this->group->field];

            if ($this->group->bucket instanceof TimeBucket) {
                $request['group_by']['bucket'] = $this->group->bucket->value;
            }

            if ($this->group->label !== null) {
                $request['group_by']['label'] = $this->group->label;
            }
        }

        if ($this->period !== null) {
            $request['period'] = $this->period;
        }

        $request['sort'] = ['by' => $this->sortBy, 'dir' => $this->sortDir];

        if ($this->limit !== null) {
            $request['limit'] = $this->limit;
        }

        if ($this->other) {
            $request['other'] = true;
        }

        if ($this->compare) {
            $request['compare'] = self::COMPARE_PREVIOUS_PERIOD;
        }

        return $request;
    }

    /**
     * The normalized request as JSON with its keys sorted, so two spellings of one request give one string. The
     * order of the measures is kept: the first one is what the default sort follows.
     *
     * The string holds the query alone: a caller that tells requests apart by it adds the resource and the
     * filters of the request.
     */
    public function canonical(): string
    {
        return json_encode(self::sortKeys($this->toArray()), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<AggregateMeasure>
     */
    private static function measures(mixed $value, AggregateLimits $limits): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw self::invalid('measures', 'An aggregate needs a list of at least one measure.');
        }

        $limits->assertMeasureCount(count($value));

        $measures = [];
        $names = [];

        foreach ($value as $index => $item) {
            $measure = self::measure($item, 'measures.' . $index);

            if (isset($names[$measure->name()])) {
                throw self::invalid('measures.' . $index, 'A measure repeats an earlier one: each figure is asked for once.');
            }

            $names[$measure->name()] = true;
            $measures[] = $measure;
        }

        return $measures;
    }

    private static function measure(mixed $value, string $path): AggregateMeasure
    {
        if (! is_array($value)) {
            throw self::invalid($path, 'A measure is an object with a function and a field.');
        }

        $fn = $value['fn'] ?? null;

        if (! is_string($fn) || $fn === '') {
            throw self::invalid($path . '.fn', 'A measure names its function.');
        }

        self::assertUtf8($fn, $path . '.fn');

        if (! in_array($fn, self::FUNCTIONS, true)) {
            throw new AggregateException(
                AggregateErrorCode::UnsupportedFunction,
                'The function of a measure is one of ' . implode(', ', self::FUNCTIONS) . '.',
                $path . '.fn',
            );
        }

        $field = $value['field'] ?? null;

        if ($field !== null && (! is_string($field) || $field === '')) {
            throw self::invalid($path . '.field', 'The field of a measure is the name of a column.');
        }

        if (is_string($field)) {
            self::assertUtf8($field, $path . '.field');
        }

        if ($field === null && $fn !== 'count') {
            throw self::invalid($path . '.field', sprintf('The function "%s" needs a field.', $fn));
        }

        return new AggregateMeasure($fn, $field);
    }

    private static function group(mixed $value): ?AggregateGroup
    {
        if ($value === null || $value === []) {
            return null;
        }

        $group = self::associative($value, 'group_by', '{field, bucket?, label?}');
        $field = $group['field'] ?? null;

        if (! is_string($field) || $field === '') {
            throw self::invalid('group_by.field', 'The group names the field it groups by.');
        }

        self::assertUtf8($field, 'group_by.field');

        $bucket = null;

        if (isset($group['bucket'])) {
            $bucket = is_string($group['bucket']) ? TimeBucket::fromInput($group['bucket']) : null;

            if (! $bucket instanceof TimeBucket) {
                throw self::invalid('group_by.bucket', 'The bucket of a group is one of ' . implode(', ', TimeBucket::names()) . '.');
            }
        }

        $label = $group['label'] ?? null;

        if ($label !== null && (! is_string($label) || $label === '')) {
            throw self::invalid('group_by.label', 'The label of a group is the name of a relation column.');
        }

        if (is_string($label)) {
            self::assertUtf8($label, 'group_by.label');
        }

        if ($bucket instanceof TimeBucket && $label !== null) {
            throw self::invalid('group_by.label', 'A group by a time bucket has no label: its key is a date.');
        }

        return new AggregateGroup($field, $bucket, $label);
    }

    /**
     * Checks the period by resolving it, as the engine will, against a fixed day: what is malformed is
     * malformed on any day, and it is refused before a query is built.
     *
     * @return array<string, mixed>|null
     */
    private static function period(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        $period = self::associative($value, 'period', '{field, and one of last, preset or from with to}');

        if (is_string($period['field'] ?? null)) {
            self::assertUtf8($period['field'], 'period.field');
        }

        $last = $period['last'] ?? null;

        if (is_array($last) && isset($last['n'])) {
            $period['last'] = [...$last, 'n' => self::wholeNumber($last['n'])];
        }

        AggregatePeriod::resolve($period, CarbonImmutable::createFromDate(2026, 1, 1, 'UTC'));

        return self::normalizedPeriod($period);
    }

    /**
     * The period the way {@see AggregatePeriod::resolve()} read it: its field and the one way it is given.
     *
     * @param  array<string, mixed>  $period  A period that resolved.
     * @return array<string, mixed>
     */
    private static function normalizedPeriod(array $period): array
    {
        $normalized = ['field' => $period['field']];

        if (is_array($period['last'] ?? null)) {
            $normalized['last'] = ['n' => $period['last']['n'], 'unit' => $period['last']['unit']];
        }

        foreach (['preset', 'from', 'to'] as $key) {
            if (isset($period[$key])) {
                $normalized[$key] = $period[$key];
            }
        }

        return $normalized;
    }

    private static function limit(mixed $value, AggregateLimits $limits): ?int
    {
        if ($value === null) {
            return null;
        }

        $limit = self::wholeNumber($value);

        if (! is_int($limit) || $limit < 1) {
            throw self::invalid('limit', 'The limit is a whole number of at least 1.');
        }

        $limits->assertGroupLimit($limit);

        return $limit;
    }

    private static function other(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (! is_bool($value)) {
            throw self::invalid('other', 'The "other" flag is true or false.');
        }

        return $value;
    }

    private static function compare(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false => false,
            $value === true, $value === self::COMPARE_PREVIOUS_PERIOD => true,
            default => throw self::invalid('compare', 'A comparison is with the "' . self::COMPARE_PREVIOUS_PERIOD . '".'),
        };
    }

    /**
     * The sort of the request with what it leaves out filled in: a bucketed group runs by its key, any other
     * by the first measure, and a key runs ascending, a measure descending.
     *
     * @return array{'measure'|'key', 'asc'|'desc'}
     */
    private static function sort(mixed $value, bool $bucketed): array
    {
        $sort = $value === null || $value === [] ? [] : self::associative($value, 'sort', '{by, dir}');

        $by = $sort['by'] ?? ($bucketed ? 'key' : 'measure');

        if (! in_array($by, self::SORT_BYS, true)) {
            throw self::invalid('sort.by', 'The sort is by "measure" or by "key".');
        }

        $dir = $sort['dir'] ?? ($by === 'key' ? 'asc' : 'desc');

        if (! in_array($dir, self::SORT_DIRECTIONS, true)) {
            throw self::invalid('sort.dir', 'The direction of a sort is "asc" or "desc".');
        }

        return [$by, $dir];
    }

    /**
     * A part of the request that is an object: a list is the list vocabulary of another request, such as the
     * `group_by` and `sort` of a list (or a text the list request split into one), and is refused with the
     * shape the part has.
     *
     * @return array<array-key, mixed>
     */
    private static function associative(mixed $value, string $path, string $shape): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw self::invalid($path, sprintf('The "%s" of an aggregate must be an object %s.', $path, $shape));
        }

        return $value;
    }

    /**
     * The integer a client wrote as text or as a float (`"12"`, `12.0`), which a query string and some JSON
     * encoders send (a JSON number beyond the integers decodes to a float); anything else comes back as it is,
     * for the caller to refuse. A text of digits of any length, or a float of that size, is the greatest
     * integer, so that it is refused as too large.
     */
    private static function wholeNumber(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $digits = mb_ltrim($value, '0');

            return mb_strlen($digits) > self::MAX_WHOLE_DIGITS ? PHP_INT_MAX : (int) $digits;
        }

        if (is_float($value) && is_finite($value) && $value === floor($value)) {
            if ($value >= self::MAX_WHOLE_NUMBER) {
                return PHP_INT_MAX;
            }

            if ($value > -self::MAX_WHOLE_NUMBER) {
                return (int) $value;
            }
        }

        return $value;
    }

    /**
     * @throws AggregateException With `INVALID_FIELD` on the path, when the text is not UTF-8: what is kept of a
     *                            request must encode as JSON, and it is refused, not repaired.
     */
    private static function assertUtf8(string $value, string $path): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw self::invalid($path, 'The value is not valid UTF-8 text.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private static function invalid(string $field, string $message): AggregateException
    {
        return new AggregateException(AggregateErrorCode::InvalidField, $message, $field);
    }
}
