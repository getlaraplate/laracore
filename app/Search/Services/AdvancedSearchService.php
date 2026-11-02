<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Contracts\ISearchStrategyResolver;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\DTOs\SearchStrategy;
use Modules\Core\Search\DTOs\VectorAvailability;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Enums\QueryClass;
use Modules\Core\Search\Enums\SearchMode;
use Modules\Core\Search\Enums\TextMatchPreference;
use Modules\Core\Search\Support\QueryDateFilter;
use Modules\Core\Search\Support\SearchDegradations;
use Modules\Core\Search\Support\SearchStageTimings;
use Modules\Core\Search\Support\VectorProfileContext;
use Throwable;

final readonly class AdvancedSearchService
{
    public function __construct(
        private ISearchStrategyResolver $strategies,
        private EnsembleSearchService $ensemble_search,
        private Application $app,
        private ?RetrievalTuningProfile $tuning = null,
        private ?IVectorSearchAvailability $vector_availability = null,
        private ?DateExpressionResolver $dates = null,
    ) {}

    public function available(?Model $model = null): bool
    {
        $engine = $model instanceof Model ? $this->engineFor($model) : null;

        if (! $engine instanceof ISearchEngine) {
            return false;
        }

        return $engine->supportsOrchestratedSearch();
    }

    /**
     * With the `search.debug_timings` setting on, `meta['timings']` reports the milliseconds spent in the
     * intent parser, the planner, the query embedding and the ensemble (rerank included), plus the total.
     *
     * The components come from the strategy the resolver picks for `$mode`, per call: `meta['search']` reports
     * the mode asked for, the mode applied, why they differ (`degraded_reason`) and `retries_used`.
     *
     * `$withheld_reason` is set by a caller that already decided the request may not spend what it asked for
     * (the rate limit): the search runs as `fast` and reports the reason as `degraded_reason`.
     *
     * `$retries` is how many times the caller lets a poor result be searched again, capped by the strategy
     * (`max_retries`, zero for `fast`). A retry is judged by {@see SearchQualityEvaluator} and is not the same
     * query again: see {@see self::relaxedPlan()}.
     *
     * A date the query names becomes a filter on the date field the model declares (`searchDateField()`), and
     * `meta['date_filter']` reports it: see {@see self::searchWithDates()}. A model that declares none, a query
     * that names no date, and a request that filters on the date field itself search as they always did.
     *
     * @param  array<int, \Modules\Core\Casts\Sort>  $sort
     */
    public function search(
        Model $model,
        string $query,
        int $page,
        int $perPage,
        ?FiltersGroup $filters = null,
        array $sort = [],
        TextMatchPreference|string|null $matching = null,
        array $matchingOptions = [],
        SearchMode $mode = SearchMode::Fast,
        ?int $retries = null,
        ?string $withheld_reason = null,
    ): AdvancedSearchResult {
        $degradations = $this->app->make(SearchDegradations::class);
        $degradations->reset();
        $strategy = $this->strategyFor($mode, $withheld_reason);
        $timings = (bool) config('core.search.debug_timings', false) ? new SearchStageTimings() : null;
        $engine = $this->engineFor($model);

        if (! $engine instanceof ISearchEngine || ! $engine->supportsOrchestratedSearch()) {
            $meta = ['unsupported_driver' => true, 'search' => $this->searchMeta($mode, $strategy)];

            if ($timings instanceof SearchStageTimings) {
                $meta['timings'] = $timings->toMeta();
            }

            return AdvancedSearchResult::empty($page, $perPage, $meta);
        }

        $intent = $this->stage($timings, SearchStageTimings::INTENT, fn (): array => $strategy->intent_parser->parse($query));
        $search_query = $this->expandedQuery($intent, $query);
        $plan = $this->stage($timings, SearchStageTimings::PLAN, fn (): array => $strategy->planner->safePlan($query));
        $plan['intent'] = $intent;
        $plan['retrieval']['size'] = $perPage;
        $date_filter = $this->dateFilterFor($model, $intent, $plan, $filters, $perPage);
        $run = function (array $plan, string $search_query) use ($model, $engine, $strategy, $query, $page, $perPage, $filters, $sort, $matching, $matchingOptions, $timings, $date_filter): array {
            $plan = $this->applyEngineCapabilities($engine, $plan);
            $text_match = app(TextMatchOptionsResolver::class)->resolve($search_query, $matching, $matchingOptions);
            $plan = $this->tuningProfile()->apply($plan, QueryClass::fromAnalysis($text_match->analysis));
            $availability = $this->vectorAvailability($model, $plan, $strategy);
            $vector = $availability->available ? $this->resolveVector($query, $plan, $strategy, $timings) : null;

            $ensemble = fn (?FiltersGroup $attempt_filters, string $attempt_query): AdvancedSearchResult => $this->ensemble_search->search(
                model: $model,
                query: $attempt_query,
                plan: $plan,
                vector: $vector,
                page: $page,
                perPage: $perPage,
                filters: $attempt_filters,
                sort: $sort,
                textMatch: $attempt_query === $search_query ? $text_match : app(TextMatchOptionsResolver::class)->resolve($attempt_query, $matching, $matchingOptions),
                reranker: $strategy->reranker,
            );

            $result = $this->stage($timings, SearchStageTimings::ENSEMBLE, fn (): AdvancedSearchResult => $this->searchWithDates($date_filter, $ensemble, $filters, $search_query));

            return [$result, $availability, $plan];
        };

        [$result, $availability, $used_plan] = $run($plan, $search_query);

        $retries_used = 0;
        $max_retries = min(max(0, $retries ?? 0), $strategy->max_retries);
        $judge = new SearchQualityEvaluator;

        while ($retries_used < $max_retries && $judge->shouldRetry($judge->evaluate($result->hits), $retries_used + 1, $used_plan)) {
            [$retry, $retry_availability, $retry_plan] = $run($this->relaxedPlan($plan, $perPage, $strategy), $query);
            $retries_used++;

            if (count($retry->hits) >= count($result->hits)) {
                [$result, $availability, $used_plan] = [$retry, $retry_availability, $retry_plan];
            }
        }

        $meta = $availability->available ? $result->meta : [...$result->meta, 'vector_disabled' => $availability->reason];
        $meta['search'] = $this->searchMeta($mode, $strategy, $retries_used, $degradations->first());

        if ($timings instanceof SearchStageTimings) {
            $meta['timings'] = $timings->toMeta();
        }

        return new AdvancedSearchResult(
            hits: $result->hits,
            total: $result->total,
            page: $result->page,
            perPage: $result->perPage,
            totalPages: $result->totalPages,
            meta: $meta,
        );
    }

    /**
     * Runs the ensemble under the date filter of the query: each interval in turn, the date words taken out of
     * the keyword query, until a run finds enough hits; then once more without a date filter and with the full
     * query. An engine that cannot filter on the declared field (it refuses the filter) gets the search without
     * the date, never an error. With no date filter this is the plain ensemble call.
     *
     * @param  Closure(?FiltersGroup, string): AdvancedSearchResult  $ensemble
     */
    private function searchWithDates(?QueryDateFilter $date_filter, Closure $ensemble, ?FiltersGroup $filters, string $search_query): AdvancedSearchResult
    {
        if (! $date_filter instanceof QueryDateFilter) {
            return $ensemble($filters, $search_query);
        }

        $dated_query = $this->dateResolver()->withoutDateExpressions($search_query, LocaleContext::get());
        $dated_query = $dated_query !== '' ? $dated_query : $search_query;
        $widened = count($date_filter->intervals);
        $reason = null;

        foreach (array_keys($date_filter->intervals) as $attempt) {
            try {
                $result = $ensemble($date_filter->filters($attempt, $filters), $dated_query);
            } catch (InvalidArgumentException $exception) {
                Log::warning('The search engine cannot filter on the search date field; searching without the date', [
                    'field' => $date_filter->field,
                    'error' => $exception->getMessage(),
                ]);
                $widened = $attempt;
                $reason = QueryDateFilter::FIELD_NOT_FILTERABLE;

                break;
            }

            if ($date_filter->isEnough($result->total)) {
                return $this->withMeta($result, ['date_filter' => $date_filter->appliedMeta($attempt)]);
            }
        }

        return $this->withMeta($ensemble($filters, $search_query), ['date_filter' => $date_filter->unappliedMeta($widened, $reason)]);
    }

    /**
     * The date filter of the query, or null when there is none to apply: the model declares no date field, the
     * request already filters on it (dates passed explicitly win over dates read from the text), or the query
     * names no date. The interval comes from the intent, else from the plan; a recency request uses the
     * windows of the plan (`filters.recency_windows_days`), else the configured ones. A failure here costs the
     * date filter, never the search.
     *
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>  $plan
     */
    private function dateFilterFor(Model $model, array $intent, array $plan, ?FiltersGroup $filters, int $per_page): ?QueryDateFilter
    {
        try {
            $field = method_exists($model, 'searchDateField') ? $model->searchDateField() : null;

            if (! is_string($field) || $field === '' || $this->filtersOn($filters, $model, $field)) {
                return null;
            }

            $plan_filters = $this->planSection($plan, 'filters');
            $windows = $this->recencyWindows($plan_filters['recency_windows_days'] ?? null);

            foreach ([$intent['date_range'] ?? null, $plan_filters['date_range'] ?? null] as $range) {
                $date_filter = is_array($range) ? QueryDateFilter::forInterval($field, $range['from'] ?? null, $range['to'] ?? null, $windows) : null;

                if ($date_filter instanceof QueryDateFilter) {
                    return $date_filter;
                }
            }

            if (($intent['recent'] ?? false) !== true && ($plan_filters['recent'] ?? false) !== true) {
                return null;
            }

            $minimum = config('core.search.recency_min_results', 3);

            return QueryDateFilter::forRecency($field, CarbonImmutable::today(), $windows, min($per_page, is_numeric($minimum) ? (int) $minimum : 3));
        } catch (Throwable $exception) {
            Log::warning('Reading the dates of the query failed; searching without a date filter', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Whether the request filters hold a condition on the field, at any depth.
     */
    private function filtersOn(?FiltersGroup $filters, Model $model, string $field): bool
    {
        if (! $filters instanceof FiltersGroup) {
            return false;
        }

        foreach ($filters->filters as $node) {
            if ($node instanceof FiltersGroup && $this->filtersOn($node, $model, $field)) {
                return true;
            }

            if ($node instanceof Filter && in_array($node->property, [$field, $model->getTable() . '.' . $field], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The windows of the plan when it carries a list, else the configured ones.
     *
     * @return list<int>
     */
    private function recencyWindows(mixed $planned): array
    {
        if (! is_array($planned)) {
            return $this->dateResolver()->recencyWindows();
        }

        return array_values(array_filter(
            array_map(static fn (mixed $days): int => is_numeric($days) ? (int) $days : 0, $planned),
            static fn (int $days): bool => $days > 0,
        ));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function withMeta(AdvancedSearchResult $result, array $meta): AdvancedSearchResult
    {
        return new AdvancedSearchResult(
            hits: $result->hits,
            total: $result->total,
            page: $result->page,
            perPage: $result->perPage,
            totalPages: $result->totalPages,
            meta: [...$result->meta, ...$meta],
        );
    }

    private function dateResolver(): DateExpressionResolver
    {
        return $this->dates ?? $this->app->make(DateExpressionResolver::class);
    }

    /**
     * Runs one stage of the search, timed only when timings were asked for. A stage that throws is rethrown
     * unchanged; the durations measured up to that point are logged, since no result will carry them.
     *
     * @template TResult
     *
     * @param  SearchStageTimings::INTENT|SearchStageTimings::PLAN|SearchStageTimings::VECTOR|SearchStageTimings::ENSEMBLE  $stage
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function stage(?SearchStageTimings $timings, string $stage, Closure $callback): mixed
    {
        if (! $timings instanceof SearchStageTimings) {
            return $callback();
        }

        try {
            return $timings->measure($stage, $callback);
        } catch (Throwable $exception) {
            Log::info('Search stage failed; timings measured so far', [
                'failed_stage' => $stage,
                'timings' => $timings->toMeta(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Asks the guard only when the plan wants vectors, so a keyword-only search never reports a reason.
     *
     * @param  array<string, mixed>  $plan
     */
    private function vectorAvailability(Model $model, array $plan, SearchStrategy $strategy): VectorAvailability
    {
        $retrieval = $this->planSection($plan, 'retrieval');

        if (($retrieval['use_vector'] ?? false) !== true || ! $strategy->embedder instanceof ITextEmbedder) {
            return VectorAvailability::yes();
        }

        $guard = $this->vector_availability ?? $this->app->make(IVectorSearchAvailability::class);

        return $guard->check($model);
    }

    private function tuningProfile(): RetrievalTuningProfile
    {
        return $this->tuning ?? $this->app->make(RetrievalTuningProfile::class);
    }

    private function engineFor(Model $model): ?ISearchEngine
    {
        if (! method_exists($model, 'searchableUsing')) {
            return null;
        }

        $engine = $model->searchableUsing();

        return $engine instanceof ISearchEngine ? $engine : null;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function applyEngineCapabilities(ISearchEngine $engine, array $plan): array
    {
        $retrieval = $this->planSection($plan, 'retrieval');

        if (($retrieval['use_vector'] ?? false) !== true) {
            return $plan;
        }

        if ($engine->supportsOrchestratedVectorSearch()) {
            return $plan;
        }

        $plan['retrieval'] = $retrieval;
        $plan['retrieval']['use_vector'] = false;
        $plan['retrieval']['use_ensemble'] = false;

        $plan['ensemble'] = $this->planSection($plan, 'ensemble');
        $plan['ensemble']['keyword_weight'] = 1.0;
        $plan['ensemble']['vector_weight'] = 0.0;
        $plan['ensemble']['hybrid_weight'] = 0.0;

        $plan['vector'] = $this->planSection($plan, 'vector');
        $plan['vector']['enabled'] = false;

        return $plan;
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
     * @param  array<string, mixed>  $plan
     * @return list<float>|null
     */
    private function resolveVector(string $query, array $plan, SearchStrategy $strategy, ?SearchStageTimings $timings): ?array
    {
        $retrieval = is_array($plan['retrieval'] ?? null) ? $plan['retrieval'] : [];
        $embedder = $strategy->embedder;

        if (($retrieval['use_vector'] ?? false) !== true || ! $embedder instanceof ITextEmbedder) {
            return null;
        }

        try {
            return $this->stage($timings, SearchStageTimings::VECTOR, static fn (): array => $embedder->embed($query));
        } catch (Throwable $exception) {
            // A search never errors because a model was unavailable: keep the keyword results.
            Log::warning('Query embedding failed; searching without the vector', ['error' => $exception->getMessage()]);
            $this->app->make(SearchDegradations::class)->note(SearchDegradations::EMBEDDER_FAILED);

            return null;
        }
    }

    private function strategyFor(SearchMode $mode, ?string $withheld_reason): SearchStrategy
    {
        if ($withheld_reason === null || $mode === SearchMode::Fast) {
            return $this->strategies->resolve($mode);
        }

        $cheap = $this->strategies->resolve(SearchMode::Fast);

        return new SearchStrategy(
            applied_mode: $cheap->applied_mode,
            planner: $cheap->planner,
            reranker: $cheap->reranker,
            intent_parser: $cheap->intent_parser,
            embedder: $cheap->embedder,
            max_retries: $cheap->max_retries,
            degraded_reason: $withheld_reason,
        );
    }

    /**
     * The plan of a retry: the first one found too little, so cast a wider net. The candidate window doubles
     * (up to 200), the intent expansion is dropped by the caller, which searches the user's own words, and
     * semantic retrieval is switched on when the strategy has an embedder and a vector profile is available.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function relaxedPlan(array $plan, int $per_page, SearchStrategy $strategy): array
    {
        $retrieval = $this->planSection($plan, 'retrieval');
        $retrieval['use_fulltext'] = true;
        $size = $retrieval['size'] ?? null;
        $retrieval['size'] = min(200, max($per_page, is_numeric($size) ? (int) $size : $per_page) * 2);

        if ($strategy->embedder instanceof ITextEmbedder && VectorProfileContext::get() instanceof VectorProfile) {
            $retrieval['use_vector'] = true;
            $retrieval['use_ensemble'] = true;
            $plan['ensemble'] = [...$this->planSection($plan, 'ensemble'), 'keyword_weight' => 0.35, 'vector_weight' => 0.35, 'hybrid_weight' => 0.30];
            $plan['vector'] = [...$this->planSection($plan, 'vector'), 'enabled' => true];
        }

        $plan['retrieval'] = $retrieval;

        return $plan;
    }

    /**
     * @return array{mode_requested: string, mode_applied: string, degraded_reason: string|null, retries_used: int}
     */
    private function searchMeta(SearchMode $requested, SearchStrategy $strategy, int $retries_used = 0, ?string $noted = null): array
    {
        return [
            'mode_requested' => $requested->value,
            'mode_applied' => $strategy->applied_mode->value,
            'degraded_reason' => $strategy->degraded_reason ?? $noted,
            'retries_used' => $retries_used,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function expandedQuery(array $intent, string $fallback): string
    {
        $query = $intent['query'] ?? null;

        if (! is_array($query)) {
            return $fallback;
        }

        $expanded = $query['expanded'] ?? null;

        return is_string($expanded) && $expanded !== '' ? $expanded : $fallback;
    }
}
