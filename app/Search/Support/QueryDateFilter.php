<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use DateTimeImmutable;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;

/**
 * The date filter a search applies because its query names a date: the declared date field of the model and
 * the intervals to try on it, in order. The search runs the first interval, moves to the next while a run
 * finds fewer hits than {@see self::$requiredHits}, and after the last one searches without a date filter,
 * so a date read from the text never empties a result set by itself.
 *
 * - An interval the query names ("of 2024") is tried as it is, then widened on both sides by each window.
 * - A recency request ("latest") tries the last N days for each window N.
 *
 * Each interval reaches the engine as two column filters on the field, `>= from` and `< the day after to`,
 * which the engines already take through the advanced filters: a date-time on the last day is inside.
 */
final readonly class QueryDateFilter
{
    public const string SOURCE_QUERY = 'query';

    public const string SOURCE_RECENT = 'recent';

    public const string FIELD_NOT_FILTERABLE = 'field_not_filterable';

    /**
     * @param  list<array{from: string|null, to: string|null}>  $intervals  inclusive ISO dates, a null side is open
     */
    public function __construct(
        public string $field,
        public string $source,
        public array $intervals,
        public int $requiredHits = 1,
    ) {}

    /**
     * The filter of an interval the query names, widened by each window. Null when neither side is an ISO date
     * (`YYYY-MM-DD`): a date that does not parse is no filter. Sides given in the wrong order are swapped.
     *
     * @param  list<int>  $windows  days
     */
    public static function forInterval(string $field, mixed $from, mixed $to, array $windows): ?self
    {
        $from = self::isoDate($from);
        $to = self::isoDate($to);

        if (! $from instanceof DateTimeImmutable && ! $to instanceof DateTimeImmutable) {
            return null;
        }

        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $intervals = [self::interval($from, $to)];

        foreach ($windows as $days) {
            $intervals[] = self::interval($from?->modify(sprintf('-%d days', $days)), $to?->modify(sprintf('+%d days', $days)));
        }

        return new self($field, self::SOURCE_QUERY, $intervals);
    }

    /**
     * The filter of a recency request: the last N days up to today, for each window N. Null with no window.
     *
     * @param  list<int>  $windows  days
     */
    public static function forRecency(string $field, DateTimeImmutable $today, array $windows, int $required_hits): ?self
    {
        if ($windows === []) {
            return null;
        }

        $intervals = array_map(
            static fn (int $days): array => self::interval($today->modify(sprintf('-%d days', $days)), $today),
            $windows,
        );

        return new self($field, self::SOURCE_RECENT, $intervals, max(1, $required_hits));
    }

    /**
     * The filters of one attempt: the request filters, untouched, and the date conditions of the interval.
     */
    public function filters(int $attempt, ?FiltersGroup $filters): FiltersGroup
    {
        $interval = $this->intervals[$attempt];
        $conditions = [];

        if ($interval['from'] !== null) {
            $conditions[] = new Filter($this->field, $interval['from'], FilterOperator::GreatEquals);
        }

        if ($interval['to'] !== null) {
            $next_day = new DateTimeImmutable($interval['to'])->modify('+1 day')->format('Y-m-d');
            $conditions[] = new Filter($this->field, $next_day, FilterOperator::Less);
        }

        $dates = new FiltersGroup($conditions, WhereClause::And);

        return $filters instanceof FiltersGroup ? new FiltersGroup([$filters, $dates], WhereClause::And) : $dates;
    }

    public function isEnough(int $hits): bool
    {
        return $hits >= $this->requiredHits;
    }

    /**
     * What the search reports when the interval of `$attempt` gave the result.
     *
     * @return array{field: string, source: string, from: string|null, to: string|null, widened: int, applied: true}
     */
    public function appliedMeta(int $attempt): array
    {
        return [
            'field' => $this->field,
            'source' => $this->source,
            'from' => $this->intervals[$attempt]['from'],
            'to' => $this->intervals[$attempt]['to'],
            'widened' => $attempt,
            'applied' => true,
        ];
    }

    /**
     * What the search reports when it ended without a date filter, after `$widened` widenings or because the
     * engine could not take the filter (`$reason`).
     *
     * @return array{field: string, source: string, from: null, to: null, widened: int, applied: false, reason?: string}
     */
    public function unappliedMeta(int $widened, ?string $reason = null): array
    {
        $meta = [
            'field' => $this->field,
            'source' => $this->source,
            'from' => null,
            'to' => null,
            'widened' => $widened,
            'applied' => false,
        ];

        if ($reason !== null) {
            $meta['reason'] = $reason;
        }

        return $meta;
    }

    private static function isoDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $value === $date->format('Y-m-d') ? $date : null;
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    private static function interval(?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        return ['from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d')];
    }
}
