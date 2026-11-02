<?php

declare(strict_types=1);

use Modules\Core\Search\Services\DateExpressionResolver;

it('maps a date expression of the query to an inclusive interval', function (string $query, string $locale, array $expected): void {
    $now = new DateTimeImmutable('2026-11-02 10:30:00');

    expect((new DateExpressionResolver)->resolve($query, $now, $locale))->toBe($expected);
})->with([
    'english year' => ['reports of 2024', 'en', ['from' => '2024-01-01', 'to' => '2024-12-31']],
    'english year with in' => ['concerts in 2024', 'en', ['from' => '2024-01-01', 'to' => '2024-12-31']],
    'english month and year' => ['news March 2025', 'en', ['from' => '2025-03-01', 'to' => '2025-03-31']],
    'english february of a leap year' => ['events in February 2024', 'en', ['from' => '2024-02-01', 'to' => '2024-02-29']],
    'english last month' => ['invoices from last month', 'en', ['from' => '2026-10-01', 'to' => '2026-10-31']],
    'english last year' => ['budget last year', 'en', ['from' => '2025-01-01', 'to' => '2025-12-31']],
    'italian year' => ['articoli del 2024', 'it', ['from' => '2024-01-01', 'to' => '2024-12-31']],
    'italian year with nel' => ['concerti nel 2024', 'it', ['from' => '2024-01-01', 'to' => '2024-12-31']],
    'italian month and year' => ['notizie di marzo 2025', 'it', ['from' => '2025-03-01', 'to' => '2025-03-31']],
    'italian month del year' => ['eventi di dicembre del 2023', 'it', ['from' => '2023-12-01', 'to' => '2023-12-31']],
    'italian last month' => ['fatture del mese scorso', 'it', ['from' => '2026-10-01', 'to' => '2026-10-31']],
    'italian last year' => ["bilancio dell'anno scorso", 'it', ['from' => '2025-01-01', 'to' => '2025-12-31']],
    'italian last year, typographic apostrophe' => ['bilancio dell’anno scorso', 'it', ['from' => '2025-01-01', 'to' => '2025-12-31']],
    'case does not matter' => ['NEWS MARCH 2025', 'en', ['from' => '2025-03-01', 'to' => '2025-03-31']],
    'region suffix of the locale' => ['articoli del 2024', 'it_IT', ['from' => '2024-01-01', 'to' => '2024-12-31']],
]);

it('reads last month across the turn of the year', function (): void {
    $now = new DateTimeImmutable('2026-01-15');

    expect((new DateExpressionResolver)->resolve('last month', $now, 'en'))->toBe(['from' => '2025-12-01', 'to' => '2025-12-31']);
});

it('reads a query in another supported language than the requested one', function (): void {
    $now = new DateTimeImmutable('2026-11-02');

    expect((new DateExpressionResolver)->resolve('news March 2025', $now, 'it'))->toBe(['from' => '2025-03-01', 'to' => '2025-03-31']);
});

it('returns null for text with no date', function (string $query, string $locale): void {
    expect((new DateExpressionResolver)->resolve($query, new DateTimeImmutable('2026-11-02'), $locale))->toBeNull();
})->with([
    'plain words' => ['climate change effects', 'en'],
    'italian plain words' => ['il gatto della nonna', 'it'],
    'a bare number is not a year' => ['iphone 2024 case', 'en'],
    'an identifier with digits' => ['INV-2024-0001', 'en'],
    'a month without a year' => ['march madness', 'en'],
    'empty text' => ['', 'en'],
]);

it('reads the supported languages for a locale that has no pattern table', function (): void {
    expect((new DateExpressionResolver)->resolve('reports of 2024', new DateTimeImmutable('2026-11-02'), 'xx'))
        ->toBe(['from' => '2024-01-01', 'to' => '2024-12-31']);
});

it('tells a recency request apart', function (string $query, bool $expected): void {
    expect((new DateExpressionResolver)->asksForRecent($query, 'it'))->toBe($expected);
})->with([
    'italian recenti' => ['notizie recenti sul clima', true],
    'italian ultime' => ['ultime notizie', true],
    'english latest' => ['latest releases', true],
    'english recent' => ['recent posts', true],
    'no recency' => ['notizie sul clima', false],
]);

it('removes the date expressions from the text and keeps the rest', function (string $query, string $expected): void {
    expect((new DateExpressionResolver)->withoutDateExpressions($query, 'it'))->toBe($expected);
})->with([
    'italian year' => ['articoli del 2024 sul clima', 'articoli sul clima'],
    'italian month and year' => ['notizie di marzo 2025', 'notizie'],
    'italian relative' => ["bilancio dell'anno scorso", 'bilancio'],
    'english recency' => ['latest climate news', 'climate news'],
    'no date' => ['climate news', 'climate news'],
]);

it('reads the recency windows from the configuration and drops invalid entries', function (): void {
    config()->set('core.search.recency_windows_days', [90, 'x', -5, 365]);

    expect((new DateExpressionResolver)->recencyWindows())->toBe([90, 365]);
});

it('defaults the recency windows to 90 and 365 days', function (): void {
    config()->set('core.search.recency_windows_days', null);

    expect((new DateExpressionResolver)->recencyWindows())->toBe([90, 365]);
});
