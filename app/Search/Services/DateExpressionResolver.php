<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use DateTimeImmutable;

/**
 * Reads the dates a search query names, by rules and without a model call: a year ("of 2024", "del 2024"),
 * a month and year ("March 2025", "marzo 2025"), the previous month or year ("last month", "l'anno scorso"),
 * and the words that ask for recent results ("latest", "recenti").
 *
 * The patterns live in one table per language. The table of the requested locale is tried first, then the
 * others, so a query written in another supported language is still read. A query that names no date gives
 * null: no date means no filter.
 */
final readonly class DateExpressionResolver
{
    /**
     * The recency windows used when the configuration gives none.
     *
     * @var list<int>
     */
    public const array DEFAULT_RECENCY_WINDOWS_DAYS = [90, 365];

    /**
     * Per language: the twelve month names, then one pattern per kind of expression. `month_year` captures
     * `month` and `year`, `year` captures `year`. Each pattern also covers the preposition or article that
     * belongs to the expression, so that removing it leaves the rest of the query readable.
     *
     * @var array<string, array{months: list<string>, month_year: string, year: string, last_month: string, last_year: string, recent: string}>
     */
    private const array PATTERNS = [
        'en' => [
            'months' => ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'],
            'month_year' => '(?:(?:in|of|from|during)\s+)?(?<month>{months}),?\s+(?:of\s+)?(?<year>{year})',
            'year' => '(?:in|of|during)\s+(?:the\s+year\s+)?(?<year>{year})',
            'last_month' => '(?:(?:in|of|from|during)\s+)?(?:the\s+)?(?:last|previous)\s+month',
            'last_year' => '(?:(?:in|of|from|during)\s+)?(?:the\s+)?(?:last|previous)\s+year',
            'recent' => '(?:the\s+)?(?:most\s+recent|recent|latest|newest)',
        ],
        'it' => [
            'months' => ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
            'month_year' => '(?:(?:a|di|del|nel|in)\s+)?(?<month>{months})\s+(?:(?:del|di)\s+)?(?<year>{year})',
            'year' => "(?:del|nel|dell['’]anno|nell['’]anno)\\s+(?<year>{year})",
            'last_month' => '(?:(?:del|nel|dal|il|lo|dello|nello)\s+)?(?:mese\s+scorso|scorso\s+mese)',
            'last_year' => "(?:(?:dell['’]|nell['’]|dall['’]|l['’])\\s*)?anno\\s+scorso|(?:(?:dello|nello|lo)\\s+)?scorso\\s+anno",
            'recent' => '(?:(?:i|le|gli)\s+)?(?:più\s+)?(?:recenti|recente|ultimi|ultime)',
        ],
    ];

    /**
     * A year a query may name: 1900 to 2199, which keeps codes and quantities out.
     */
    private const string YEAR = '(?:19|20|21)\d{2}';

    /**
     * The interval the query names, as inclusive ISO dates, or null when it names none.
     *
     * @return array{from: string, to: string}|null
     */
    public function resolve(string $query, DateTimeImmutable $now, string $locale): ?array
    {
        foreach ($this->languagesFor($locale) as $language) {
            $interval = $this->resolveIn($language, $query, $now);

            if ($interval !== null) {
                return $interval;
            }
        }

        return null;
    }

    /**
     * Whether the query asks for recent results ("latest", "ultime"), which the search answers with widening
     * windows rather than a fixed interval.
     */
    public function asksForRecent(string $query, string $locale): bool
    {
        foreach ($this->languagesFor($locale) as $language) {
            if (preg_match($this->pattern($language, 'recent'), $query) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The query without the date expressions it names, for the keyword search that runs once the dates became
     * a filter. Empty when the query held nothing else: the caller keeps the full query then.
     */
    public function withoutDateExpressions(string $query, string $locale): string
    {
        $text = $query;

        foreach ($this->languagesFor($locale) as $language) {
            foreach (['month_year', 'last_month', 'last_year', 'year', 'recent'] as $kind) {
                $text = preg_replace($this->pattern($language, $kind), ' ', $text) ?? $text;
            }
        }

        return mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * The recency windows, in days and in the order they are tried (`core.search.recency_windows_days`). An
     * entry that is not a positive whole number is dropped; no valid entry gives the defaults.
     *
     * @return list<int>
     */
    public function recencyWindows(): array
    {
        $configured = config('core.search.recency_windows_days');

        if (! is_array($configured)) {
            return self::DEFAULT_RECENCY_WINDOWS_DAYS;
        }

        $windows = [];

        foreach ($configured as $days) {
            if (is_int($days) || (is_string($days) && ctype_digit($days))) {
                $days = (int) $days;

                if ($days > 0) {
                    $windows[] = $days;
                }
            }
        }

        return $windows === [] ? self::DEFAULT_RECENCY_WINDOWS_DAYS : $windows;
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function resolveIn(string $language, string $query, DateTimeImmutable $now): ?array
    {
        if (preg_match($this->pattern($language, 'month_year'), $query, $match) === 1) {
            $month = array_search(mb_strtolower($match['month']), self::PATTERNS[$language]['months'], true);

            if (is_int($month)) {
                $first = $now->setDate((int) $match['year'], $month + 1, 1);

                return $this->interval($first, $first->modify('last day of this month'));
            }
        }

        if (preg_match($this->pattern($language, 'last_month'), $query) === 1) {
            $first = $now->modify('first day of last month');

            return $this->interval($first, $first->modify('last day of this month'));
        }

        if (preg_match($this->pattern($language, 'last_year'), $query) === 1) {
            return $this->wholeYear($now, (int) $now->format('Y') - 1);
        }

        if (preg_match($this->pattern($language, 'year'), $query, $match) === 1) {
            return $this->wholeYear($now, (int) $match['year']);
        }

        return null;
    }

    /**
     * @return array{from: string, to: string}
     */
    private function wholeYear(DateTimeImmutable $now, int $year): array
    {
        return $this->interval($now->setDate($year, 1, 1), $now->setDate($year, 12, 31));
    }

    /**
     * @return array{from: string, to: string}
     */
    private function interval(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    /**
     * The whole-word, case-insensitive pattern of one kind of expression in one language.
     *
     * @param  'month_year'|'year'|'last_month'|'last_year'|'recent'  $kind
     */
    private function pattern(string $language, string $kind): string
    {
        $body = str_replace(
            ['{months}', '{year}'],
            [implode('|', self::PATTERNS[$language]['months']), self::YEAR],
            self::PATTERNS[$language][$kind],
        );

        return '/(?<![\p{L}\p{N}])(?:' . $body . ')(?![\p{L}\p{N}])/iu';
    }

    /**
     * The languages with a pattern table, the one of the locale first. A locale with a region (`it_IT`, `en-GB`)
     * reads as its language.
     *
     * @return list<string>
     */
    private function languagesFor(string $locale): array
    {
        $language = mb_strtolower(mb_substr(mb_trim($locale), 0, 2));
        $languages = array_keys(self::PATTERNS);

        if (! in_array($language, $languages, true)) {
            return $languages;
        }

        return [$language, ...array_values(array_diff($languages, [$language]))];
    }
}
