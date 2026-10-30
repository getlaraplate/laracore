<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Retrieval tuning profile (L1)
|--------------------------------------------------------------------------
|
| Applied by Modules\Core\Search\Services\RetrievalTuningProfile only when the
| `search.adaptive_tuning` setting (config `core.search.adaptive_tuning`) is on.
| `default` applies to every query class; an entry under `classes` overrides it
| for that class. A parameter left out keeps the value the planner emitted
| (L0): the weights depend on query length and vector availability, and
| `rerank_top_k` / `rerank_blend` fall back to the `search.reranker.top_k` and
| `search.reranker.weight` settings.
|
| Allowed parameters: keyword_weight, vector_weight, hybrid_weight, rrf_weight,
| agreement_boost, rerank_blend (all in [0, 1]); rrf_k, rerank_top_k (>= 1).
| Classes: identifier, short_keyword, multi_term, natural_language.
|
| This shipped profile only restates the L0 constants, so turning the switch on
| changes nothing but `meta['tuning']`. Replace it with a block printed by
| `php artisan test:ai:tune-retrieval`, add `'report' => 'docs/evaluations/retrieval-tuning/<file>.json'`
| (relative to the Core module) citing the report of that run, committed there, and
| bump `version`. Never hand-pick values: a test requires every profile with measured
| values to cite a report that passed the held-out validation and the noise check and
| that holds the same values. This L0 profile is exempt.
|
*/

return [
    'version' => '2026-10-01.l0',
    'default' => [
        'agreement_boost' => 0.15,
        'rrf_k' => 60,
        'rrf_weight' => 0.25,
    ],
    'classes' => [
        'identifier' => [],
        'short_keyword' => [],
        'multi_term' => [],
        'natural_language' => [],
    ],
];
