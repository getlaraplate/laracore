<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Models\SearchQueryStat;
use Modules\Core\Search\Enums\SearchTokenKind;

/**
 * The application search registry: one row per normalized query, locale, searched model and day, holding
 * counters only. It stores no user, IP or session, and never a query that holds an email, a UUID, a structured
 * identifier or a number.
 */
final readonly class SearchQueryRegistry
{
    private const int MAX_LENGTH = 255;

    public function __construct(private SearchQueryAnalyzer $analyzer) {}

    /**
     * The form in which a query is stored, or null when it must not be stored.
     */
    public function normalize(string $query, string $locale): ?string
    {
        $text = mb_strtolower(mb_trim(preg_replace('/\s+/u', ' ', $query) ?? $query));
        $text = $this->analyzer->withoutRequestWords($text, $locale);
        $text = mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return null;
        }

        foreach ($this->analyzer->analyze($text)->tokens as $token) {
            if (in_array($token->kind, [
                SearchTokenKind::Email,
                SearchTokenKind::Uuid,
                SearchTokenKind::StructuredIdentifier,
                SearchTokenKind::Numeric,
            ], true)) {
                return null;
            }
        }

        return $text;
    }

    /**
     * Adds one search to the row of the day. The counters are incremented in the database so concurrent
     * searches add up.
     */
    public function record(string $model, string $query, string $locale, int $hits, CarbonImmutable $day): void
    {
        $stat = SearchQueryStat::query()->firstOrCreate(
            ['query' => $query, 'locale' => $locale, 'model' => $model, 'day' => $day->toDateString()],
            ['searches' => 0, 'low_result_searches' => 0, 'result_sum' => 0],
        );

        $stat->incrementEach([
            'searches' => 1,
            'low_result_searches' => $hits < $this->lowResultsThreshold() ? 1 : 0,
            'result_sum' => max(0, $hits),
        ]);
    }

    private function lowResultsThreshold(): int
    {
        $value = config('core.search.registry.low_results_threshold', 5);

        return is_numeric($value) ? (int) $value : 5;
    }
}
