<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\Sort;
use Modules\Core\Contracts\ProvidesDefaultSearchFilters;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Contracts\ILocaleFilterableEngine;
use Modules\Core\Search\Contracts\IProvidesRerankerText;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\IRerankerWithModel;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\DTOs\RerankResult;
use Modules\Core\Search\DTOs\ResolvedTextMatch;
use Modules\Core\Search\Support\SearchDegradations;
use RuntimeException;
use Throwable;

/**
 * Ensemble search service that combines keyword, vector and hybrid retrieval
 * strategies with Reciprocal Rank Fusion (RRF) and optional reranking. The
 * fusion math lives in {@see RankFusion}.
 *
 * @phpstan-import-type FusedHit from RankFusion
 */
class EnsembleSearchService
{
    public function __construct(
        private readonly IReranker $reranker,
        private readonly ?ScoutSearchConstraintApplier $constraint_applier = null,
    ) {}

    /**
     * Execute an ensemble search across multiple retrieval strategies.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<float>|null  $vector
     * @param  array<int, Sort>  $sort
     */
    public function search(Model $model, string $query, array $plan, ?array $vector, int $page, int $perPage, ?FiltersGroup $filters = null, array $sort = [], ?ResolvedTextMatch $textMatch = null, ?IReranker $reranker = null): AdvancedSearchResult
    {
        $retrieval = $this->planSection($plan, 'retrieval');
        $ensemble_config = $this->planSection($plan, 'ensemble');
        $ranking = $this->planSection($plan, 'ranking');

        $use_fulltext = (bool) ($retrieval['use_fulltext'] ?? true);
        $use_vector = (bool) ($retrieval['use_vector'] ?? false) && $vector !== null;
        $window = max(1, $page * $perPage);
        $driver = $this->driverName($model);

        $weights = [
            'keyword' => $this->planFloat($ensemble_config, 'keyword_weight', 0.35),
            'vector' => $this->planFloat($ensemble_config, 'vector_weight', 0.35),
            'hybrid' => $this->planFloat($ensemble_config, 'hybrid_weight', 0.30),
        ];

        $agreement_boost = $this->planFloat($ensemble_config, 'agreement_boost', 0.15);
        $rrf_k = $this->planInt($ensemble_config, 'rrf_k', 60);
        $rrf_weight = $this->planFloat($ensemble_config, 'rrf_weight', 0.25);

        $per_strategy = [];
        $strategy_results = [];

        if ($use_fulltext) {
            $result = $this->executeScoutSearch($model, $query, null, $filters, $sort, $window, $page, $perPage, 'keyword', $driver, $textMatch);
            $strategy_results[] = $result;
            $per_strategy['keyword'] = $this->hitsById($result);
        }

        if ($use_vector) {
            $result = $this->executeScoutSearch($model, '*', $vector, $filters, $sort, $window, $page, $perPage, 'vector', $driver);
            $strategy_results[] = $result;
            $per_strategy['vector'] = $this->hitsById($result);
        }

        if ($use_fulltext && $use_vector) {
            $result = $this->executeScoutSearch($model, $query, $vector, $filters, $sort, $window, $page, $perPage, 'hybrid', $driver, $textMatch);
            $strategy_results[] = $result;
            $per_strategy['hybrid'] = $this->hitsById($result);
        }

        if ($per_strategy === []) {
            return AdvancedSearchResult::empty($page, $perPage, ['strategies_executed' => 0]);
        }

        $adjusted_weights = RankFusion::renormalizeWeights($weights, $use_fulltext, $use_vector);

        $fused = RankFusion::fuse($per_strategy, $adjusted_weights, $agreement_boost, $rrf_k, $rrf_weight);

        $use_reranker = (bool) ($ranking['use_reranker'] ?? config('core.search.reranker.enabled'));
        $default_rerank_top_k = $this->configInt('core.search.reranker.top_k', 30);
        $rerank_top_k = $this->planInt($ranking, 'rerank_top_k', $default_rerank_top_k);
        $rerank_blend = $this->planFloat($ranking, 'rerank_blend', $this->configFloat('core.search.reranker.weight', 0.6));

        $reranker_model = null;

        if ($use_reranker && $fused !== []) {
            try {
                [$fused, $reranker_model] = $this->rerankTopK($model, $fused, $query, $rerank_top_k, $rerank_blend, $reranker ?? $this->reranker);
            } catch (Throwable $exception) {
                // A reranker failure (e.g. the cross-encoder service is down) must
                // not break search: keep the fused results unreranked and record
                // that reranking did not run. A failure that carries a report
                // context (a request id, a status) keeps it, as the exception
                // handler would.
                $context = method_exists($exception, 'context') ? $exception->context() : [];

                Log::warning('Reranker failed; returning fused results without reranking', [
                    'error' => $exception->getMessage(),
                    ...(is_array($context) ? $context : []),
                ]);

                app(SearchDegradations::class)->note(SearchDegradations::RERANKER_FAILED);
                $use_reranker = false;
            }
        }

        $hits = array_values(
            collect($fused)
                ->sortByDesc('score')
                ->slice(max(0, ($page - 1) * $perPage), $perPage)
                ->map(function (array $item): array {
                    $score = round($item['score'], 6);
                    $score_details = $item['score_details'];
                    $score_details['normalized_score'] = $score;

                    return [
                        'id' => $item['id'],
                        'score' => $score,
                        'raw_score' => $item['raw_score'],
                        'score_details' => $score_details,
                        'source' => $item['source'],
                    ];
                })
                ->all(),
        );
        $total = max(array_map(static fn (AdvancedSearchResult $result): int => $result->total, $strategy_results) ?: [count($hits)]);
        $meta = [
            'driver' => $driver,
            'strategies_executed' => count($per_strategy),
            'strategies' => array_keys($per_strategy),
            'reranked' => $use_reranker,
            ...($use_reranker && $reranker_model !== null ? ['reranker_model' => $reranker_model] : []),
            'total_results' => count($hits),
            'matching' => $textMatch?->toMeta($this->textMatchDegradations($model, $textMatch)) ?? [],
            'per_strategy' => $per_strategy,
        ];
        $tuning = $this->planSection($this->planSection($plan, 'meta'), 'tuning');

        if ($tuning !== []) {
            $meta['tuning'] = $tuning;
        }

        return new AdvancedSearchResult(
            hits: $hits,
            total: $total,
            page: $page,
            perPage: $perPage,
            totalPages: (int) ceil($total / max(1, $perPage)),
            meta: $meta,
        );
    }

    /**
     * Restricts the search to the documents available in the language the results are requested in, which is
     * the language of the locale context. The query may be in any language and the vectors carry none: only
     * the documents the language can be shown for come back, and the engine applies the restriction itself, so
     * the first results are not taken by documents that would be dropped afterwards.
     *
     * Only an engine that understands the restriction gets it, and only for a model whose index records the
     * languages: any other would read `locales` as an attribute the models do not have and return nothing.
     *
     * @param  ScoutBuilder<Model>  $builder
     */
    public function applyRequestedLocale(ScoutBuilder $builder, Model $model): void
    {
        $engine = $this->engineOf($model);

        if ($engine instanceof ILocaleFilterableEngine && $engine->filtersByLocale($model)) {
            $builder->where('locales', [LocaleContext::get()]);
        }
    }

    /**
     * @param  list<float>|null  $vector
     * @param  array<int, Sort>  $sort
     */
    private function executeScoutSearch(Model $model, string $query, ?array $vector, ?FiltersGroup $filters, array $sort, int $window, int $page, int $perPage, string $strategy, string $driver, ?ResolvedTextMatch $textMatch = null): AdvancedSearchResult
    {
        /** @var class-string<Model> $model_class */
        $model_class = $model::class;

        /** @var ScoutBuilder<Model> $builder */
        $builder = $model_class::search($query)->take($window);

        if ($textMatch instanceof ResolvedTextMatch) {
            $builder->options[TextMatchOptionsResolver::BUILDER_OPTION] = $textMatch->options->toEngineArray();
        }

        if ($vector !== null) {
            $builder->where('vector', $vector);
        }

        if ($model instanceof ProvidesDefaultSearchFilters) {
            foreach ($model->defaultSearchFilters() as $field => $value) {
                $builder->where($field, $value);
            }
        }

        $this->applyRequestedLocale($builder, $model);
        $this->constraintApplier()->apply($builder, $model, $filters, $sort);

        $paginator = $builder->paginate($window, 'page', 1);
        $results = $paginator->getCollection();

        return $this->normalizeScoutResults($results, $page, $perPage, $strategy, $driver, $paginator->total());
    }

    /**
     * @param  Collection<int, mixed>  $results
     */
    private function normalizeScoutResults(Collection $results, int $page, int $perPage, string $strategy, string $driver, ?int $total = null): AdvancedSearchResult
    {
        $hits = [];
        $rank = 1;

        foreach ($results as $result) {
            if ($result instanceof Model) {
                $attributes = $result->getAttributes();
                $raw_score = is_numeric($attributes['_score'] ?? null) ? (float) $attributes['_score'] : 1.0;
                $defaulted = ! is_numeric($attributes['_score'] ?? null);

                $hits[] = [
                    'id' => (string) $result->getKey(),
                    'score' => $raw_score,
                    'raw_score' => $raw_score,
                    'score_details' => $this->scoreDetails($driver, $strategy, $rank, $raw_score, $raw_score, $defaulted),
                    'source' => $attributes,
                ];
                $rank++;

                continue;
            }

            if (is_array($result)) {
                $id = $result['_id'] ?? $result['id'] ?? null;

                if (is_scalar($id)) {
                    $raw_score = is_numeric($result['_score'] ?? null) ? (float) $result['_score'] : 1.0;
                    $defaulted = ! is_numeric($result['_score'] ?? null);

                    $hits[] = [
                        'id' => (string) $id,
                        'score' => $raw_score,
                        'raw_score' => $raw_score,
                        'score_details' => $this->scoreDetails($driver, $strategy, $rank, $raw_score, $raw_score, $defaulted, $result),
                        'source' => $result,
                    ];
                    $rank++;
                }
            }
        }

        $total ??= count($hits);

        return new AdvancedSearchResult(
            hits: $hits,
            total: $total,
            page: $page,
            perPage: $perPage,
            totalPages: (int) ceil($total / max(1, $perPage)),
            meta: ['strategy' => $strategy],
        );
    }

    /**
     * The search engine of a model, or null when it has none: a model without `searchableUsing()`, or one
     * that answers no engine. The engine is read by capability (`method_exists()`, the engine contracts),
     * not by class, and every caller degrades without one.
     */
    private function engineOf(Model $model): ?object
    {
        $engine = method_exists($model, 'searchableUsing') ? $model->searchableUsing() : null;

        return is_object($engine) ? $engine : null;
    }

    private function driverName(Model $model): string
    {
        $engine = $this->engineOf($model);

        if ($engine === null) {
            return 'none';
        }

        return method_exists($engine, 'getName') ? $engine->getName() : $engine::class;
    }

    /**
     * @return list<string>
     */
    private function textMatchDegradations(Model $model, ResolvedTextMatch $textMatch): array
    {
        $engine = $this->engineOf($model);

        if ($engine === null || ! method_exists($engine, 'textMatchCapabilities')) {
            return ['capabilities'];
        }

        $degraded = $engine->textMatchCapabilities()['degraded'] ?? [];

        if (is_string($degraded)) {
            return [$degraded];
        }

        if (! is_array($degraded)) {
            return [];
        }

        return array_values(array_filter(
            $degraded,
            static fn (mixed $capability): bool => is_string($capability)
                && ($capability !== 'required_terms' || $textMatch->options->requiredTerms !== [])
                && ($capability !== 'required_phrases' || $textMatch->options->requiredPhrases !== []),
        ));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function scoreDetails(string $driver, string $strategy, int $rank, float $rawScore, float $normalizedScore, bool $defaulted, array $extra = []): array
    {
        $details = [
            'driver' => $driver,
            'strategy' => $strategy,
            'rank' => $rank,
            'raw_score' => $rawScore,
            'normalized_score' => $normalizedScore,
            'defaulted' => $defaulted,
        ];

        foreach (['distance', 'similarity', 'similarity_score', 'text_match'] as $key) {
            if (is_numeric($extra[$key] ?? null)) {
                $details[$key] = (float) $extra[$key];
            }
        }

        return $details;
    }

    private function constraintApplier(): ScoutSearchConstraintApplier
    {
        return $this->constraint_applier ?? app(ScoutSearchConstraintApplier::class);
    }

    /**
     * @return array<string, array{id: string, score: float, source: array<string, mixed>, rank: int}>
     */
    private function hitsById(AdvancedSearchResult $result): array
    {
        $out = [];
        $rank = 1;

        foreach ($result->hits as $hit) {
            $id = $hit['id'];
            $out[$id] = [
                'id' => $hit['id'],
                'score' => $hit['score'],
                'raw_score' => $hit['raw_score'] ?? $hit['score'],
                'score_details' => $hit['score_details'] ?? [],
                'source' => $hit['source'],
                'rank' => $rank,
            ];
            $rank++;
        }

        return $out;
    }

    /**
     * Rerank the top-K results with the given reranker (the one the search was called with, else the injected one).
     *
     * Each top-K score becomes `fused * (1 - blend) + rerank * max_fused * blend`; the default
     * blend 0.6 is the historical 0.4 / 0.6 split.
     *
     * @param  list<FusedHit>  $results
     * @return array{0: list<FusedHit>, 1: string|null} the hits, and the model that scored them when the reranker names it
     */
    private function rerankTopK(Model $model, array $results, string $query, int $top_k, float $blend, IReranker $reranker): array
    {
        usort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $to_rerank = array_slice($results, 0, $top_k);
        $remaining = array_slice($results, $top_k);

        if ($to_rerank === []) {
            return [$results, null];
        }

        $provided = $this->providedRerankerTexts($model, $to_rerank);

        $pairs = array_map(
            function (array $item) use ($query, $provided): array {
                $text = $provided[(string) $item['id']] ?? '';

                return [
                    'query' => $query,
                    'text' => $text !== '' ? $text : $this->buildRerankerText($item['source']),
                ];
            },
            $to_rerank,
        );

        // A reranker handed only empty texts can do nothing but echo the order it got: fail, so the search
        // reports `reranked = false` instead of a rerank that never saw a document.
        if (array_all($pairs, static fn (array $pair): bool => $pair['text'] === '')) {
            throw new RuntimeException('No text to rerank: the hits carry none and the model provides none.');
        }

        $reranked = $reranker instanceof IRerankerWithModel
            ? $reranker->scoreWithModel($pairs)
            : new RerankResult($reranker->score($pairs));
        $rerank_scores = $reranked->scores;

        $original_max = max(array_column($to_rerank, 'score')) ?: 1.0;

        foreach ($to_rerank as $i => &$item) {
            $rerank_score = $rerank_scores[$i] ?? 0.0;
            $item['score'] = ($item['score'] * (1 - $blend)) + ($rerank_score * $original_max * $blend);
        }

        return [array_merge($to_rerank, $remaining), $reranked->model];
    }

    /**
     * The text the model provides for the hits to rerank, in the language of the search, keyed by hit id.
     * Empty for a model that provides none: its hits are read for text as they are.
     *
     * @param  list<FusedHit>  $items
     * @return array<string, string>
     */
    private function providedRerankerTexts(Model $model, array $items): array
    {
        if (! $model instanceof IProvidesRerankerText) {
            return [];
        }

        $texts = [];

        foreach ($model::rerankerTexts(array_column($items, 'id'), LocaleContext::get()) as $key => $text) {
            $texts[(string) $key] = mb_trim($text);
        }

        return $texts;
    }

    /**
     * Build a text representation of a document source for reranking.
     *
     * @param  array<string, mixed>  $source
     */
    private function buildRerankerText(array $source): string
    {
        $title = $this->sourceScalarToString($source, 'title')
            ?: $this->sourceScalarToString($source, 'name');
        $body = $this->sourceScalarToString($source, 'body')
            ?: $this->sourceScalarToString($source, 'content')
            ?: $this->sourceScalarToString($source, 'description');

        return mb_trim($title . ' ' . $body);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceScalarToString(array $source, string $key): string
    {
        if (! isset($source[$key]) || ! is_scalar($source[$key])) {
            return '';
        }

        return (string) $source[$key];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planSection(array $plan, string $key): array
    {
        $section = $plan[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function planInt(array $section, string $key, int $default): int
    {
        $value = $section[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function planFloat(array $section, string $key, float $default): float
    {
        $value = $section[$key] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }

    private function configFloat(string $key, float $default): float
    {
        $value = config($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
