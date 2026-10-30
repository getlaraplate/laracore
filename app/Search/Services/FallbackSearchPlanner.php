<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Support\VectorProfileContext;

/**
 * Rule-based search planner that generates execution plans without AI/LLM.
 *
 * Adjusts retrieval strategy weights and sizes based on simple query
 * heuristics (length, presence of numbers).
 */
final readonly class FallbackSearchPlanner implements ISearchPlanner
{
    /**
     * {@inheritDoc}
     *
     * Delegates to the rule-based fallback since no LLM is available.
     *
     * @return array<string, mixed>
     */
    public function safePlan(string $query): array
    {
        return $this->fallbackPlan($query);
    }

    /**
     * @return array<string, mixed>
     */
    public function fallbackPlan(string $query): array
    {
        $is_short = mb_strlen($query) < 20;
        $has_numbers = (bool) preg_match('/\d/', $query);
        $use_vector = VectorProfileContext::get() instanceof VectorProfile && ! $has_numbers;

        return [
            'strategy' => $use_vector ? 'hybrid' : 'fulltext',
            'retrieval' => [
                'use_fulltext' => true,
                'use_vector' => $use_vector,
                'use_ensemble' => true,
                'size' => $is_short ? 80 : 50,
            ],
            'ensemble' => [
                'enabled' => true,
                'keyword_weight' => $use_vector ? ($is_short ? 0.30 : 0.35) : 1.0,
                'vector_weight' => $use_vector ? ($is_short ? 0.40 : 0.35) : 0.0,
                'hybrid_weight' => $use_vector ? ($is_short ? 0.30 : 0.30) : 0.0,
                'agreement_boost' => 0.15,
                'rrf_k' => 60,
                'rrf_weight' => 0.25,
            ],
            'ranking' => [
                'use_reranker' => (bool) config('core.search.reranker.enabled'),
                'rerank_top_k' => (int) config('core.search.reranker.top_k', 30),
            ],
            'vector' => [
                'enabled' => $use_vector,
                'weight' => $is_short ? 0.4 : 0.2,
            ],
            'filters' => [
                'date_range' => null,
            ],
            'retry_policy' => [
                'enabled' => true,
                'max_attempts' => 2,
                'threshold_avg_score' => 1.5,
            ],
            'meta' => [
                'source' => 'fallback_rules',
            ],
        ];
    }
}
