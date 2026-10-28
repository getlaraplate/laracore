# Search retrieval pipeline — developer and operator guide

How an orchestrated search request becomes a ranked list. Adaptive text matching (fuzziness,
required terms, engine translation) is a separate concern documented in
[SEARCH_MATCHING_DEVELOPER.md](./SEARCH_MATCHING_DEVELOPER.md) and
[SEARCH_MATCHING_USER.md](./SEARCH_MATCHING_USER.md).

## Entry point and mode

`CrudService::search()` picks the path from `SearchRequestData->mode` (`mode` query parameter,
default `auto`):

| `mode` | Path |
|--------|------|
| `orchestrated` | always `AdvancedSearchService` |
| `auto` (default) | `AdvancedSearchService` when `available()` (engine implements `ISearchEngine` **and** `supportsOrchestratedSearch()`), otherwise plain Scout |
| `basic` | always plain Scout, no orchestration |

An engine that does not support orchestration returns `AdvancedSearchResult::empty()` with
`meta['unsupported_driver'] = true` when `orchestrated` was forced.

## Search modes: how much a request spends

`AdvancedSearchService::search()` takes a `SearchMode` (`$mode`, default `fast`) and asks one contract,
`ISearchStrategyResolver`, for the components of this request. The resolver returns a `SearchStrategy`: planner,
reranker, intent parser, an optional embedder and a retry cap. The service holds no planner, reranker or
embedder of its own, so the cost of a search is decided per call, never at boot.

| Mode | What it runs | Cost |
|------|--------------|------|
| `fast` (default) | Core's `FallbackSearchPlanner`, `HeuristicReranker`, `SimpleQueryIntentParser`; no query vector | milliseconds, no external call |
| `balanced` | the same, plus the query embedding (the AI module's `SearchEmbedder`), so lexical and vector retrieval are fused | one embedding call, about 80 ms once the model is warm |
| `deep` | LLM intent parsing and planning, cross-encoder reranking when the plan asks for it, the embedding, and up to two retries | seconds per LLM call, real money on a paid provider |

Core binds `CoreSearchStrategyResolver`, which ignores the mode and always returns the `fast` set: Core has
no embedder and no LLM. A module that can do more rebinds `ISearchStrategyResolver` (the AI module binds
`AiSearchStrategyResolver`); Core never names it. `Balanced` and `Deep` are therefore served as `fast` when no
module offers them.

**Degradation is part of the contract.** A search never fails because a model is unavailable. Whatever prevents
the requested mode produces the cheaper result and says why, in `meta['search']['degraded_reason']`:

| `degraded_reason` | Cause |
|-------------------|-------|
| `mode_unavailable` | no installed module offers the requested mode (Core's resolver) |
| `search_orchestration_disabled` | the AI overlay is switched off by the Setting `features.search_orchestration.enabled` of the AI module (Filament, Settings) |
| `embedder_unavailable` | the embedding service could not be built |
| `deep_unavailable` | the LLM components could not be built |
| `rate_limited` | the person started more deep searches than `search.deep_per_minute` allows |

A failure during the search is handled where it happens, and the component that falls back notes it in
`SearchDegradations` (a scoped object, emptied at the start of every search); the first note becomes
`degraded_reason` when the strategy itself did not degrade:

| `degraded_reason` | Fallback inside the search |
|-------------------|----------------------------|
| `llm_unavailable` | the LLM intent parser or planner failed or timed out and the search used its rules; also set when a repeated query is served a cached rule-based plan |
| `embedder_failed` | the query embedding threw (logged) and the search ran on keywords |
| `reranker_failed` | the reranker threw (logged) and the fused order was kept, with `reranked=false` |

`mode_applied` still says what the strategy was (`deep`), so a client sees both: it got `deep`, and part of it
fell back. A client should read `degraded_reason` as well as `mode_applied` rather than assume it got the mode it
asked for.

**Retries.** `$retries` is how many times the caller lets a poor result be searched again, capped by the strategy
(`max_retries`: 0 for `fast` and `balanced`, 2 for `deep`). `SearchQualityEvaluator` judges the result (fewer than five
hits, fewer than three distinct ones, or an average score under the plan's `retry_policy` threshold). A retry
is not the same query again: the candidate window doubles up to 200, the user's own words replace the intent
expansion, and vector retrieval is switched on when the strategy has an embedder. The retry replaces the first
result only if it has at least as many hits. `meta['search']['retries_used']` reports how many ran.

**On the wire.** The search endpoint takes two parameters for this, next to the existing `mode`
(`auto|basic|orchestrated`, which picks the *path* and is unrelated to cost):

| Parameter | Values | Default | Effect |
|-----------|--------|---------|--------|
| `cost` | `fast`, `balanced`, `deep` | `fast` | the search mode above; an unknown value reads as `fast`, never an error |
| `retry` | integer 0 or more | `0` | retries the caller allows after a poor result; clamped to the setting `search.max_retries` (default 2), then to the strategy's cap |

`cost` matters only on the orchestrated path; a plain Scout search ignores it. (It is not the `depth` of the graph search.) The response carries
`meta.search` (`mode_requested`, `mode_applied`, `degraded_reason`, `retries_used`); it is the only part of the engine meta the HTTP
response serializes. `deep` has no permission of its own. It is limited per person by the setting
`search.deep_per_minute` (default 6, counted per signed-in user, else per IP address); over the limit the
search is served as `fast` with `degraded_reason: rate_limited` and a 200, not refused. Both settings are in the
`search` group and change without a deploy.

## The five steps

```text
AdvancedSearchService::search()
  1. IQueryIntentParser::parse()        -> optional query expansion
  2. ISearchPlanner::safePlan()         -> the plan (which strategies, weights, rerank)
     applyEngineCapabilities()          -> plan downgraded to keyword if engine lacks kNN
     TextMatchOptionsResolver::resolve() -> ResolvedTextMatch (lexical only, carries the query analysis)
     RetrievalTuningProfile::apply()    -> measured profile per QueryClass, only when search.adaptive_tuning is on
  3. resolveVector()                    -> query embedding, or null
  4. EnsembleSearchService::search()
       executeScoutSearch() x1 or x3    -> keyword | vector | hybrid, SEQUENTIAL
       RankFusion::fuse()               -> weighted normalized score + RRF + agreement
  5. rerankTopK()                       -> IReranker over the fused top-K
```

### Step 2 — the plan decides how many strategies run

`FallbackSearchPlanner::fallbackPlan()` (Core, no LLM) applies two rules:

```php
$is_short   = mb_strlen($query) < 20;
$has_numbers = (bool) preg_match('/\d/', $query);
$use_vector  = config('core.search.vector.enabled', false) && ! $has_numbers;
```

| Condition | Strategies executed |
|-----------|---------------------|
| `core.search.vector.enabled` = false | `keyword` only |
| query contains any digit | `keyword` only |
| engine without `supportsOrchestratedVectorSearch()` | `keyword` only (plan downgraded by `applyEngineCapabilities()`) |
| the strategy has no embedder (`fast`, or the AI module absent or its overlay disabled) | `keyword` only (`$vector === null`) |
| otherwise | `keyword` + `vector` + `hybrid` |

**One or three, never two**: `hybrid` runs only when both `use_fulltext` and `use_vector` are true.
The three Scout calls are **sequential**, not parallel. When a strategy does not run, its weight is
removed and the remaining weights are renormalized to sum to 1.0
(`RankFusion::renormalizeWeights()`), so a keyword-only run gives keyword weight 1.0 and
fusion becomes a pass-through.

`SearchOrchestratorAgent` (AI module) can replace the planner with an LLM-produced plan; it is
clamped and validated before use, and falls back to the same rule-based plan on any failure.

### Step 2b — the retrieval tuning profile (L1)

When the `search.adaptive_tuning` runtime setting (`core.search.adaptive_tuning`, group `search`,
seeded **off**) is on, `RetrievalTuningProfile::apply()` merges a committed parameter set into the
plan right after the planner and the capability downgrade, so it covers both planners. It is not
learning: nothing reads user behaviour and two identical requests get identical plans.

The query class comes from the analysis `TextMatchOptionsResolver` already made (no second
tokenizer), via `QueryClass::fromAnalysis()`, in this precedence:

| Class | Condition |
|-------|-----------|
| `identifier` | a significant numeric, UUID, email or structured-identifier token (`INV-1042`) |
| `short_keyword` | at most two significant tokens |
| `multi_term` | three to five significant tokens and fewer than two stopwords |
| `natural_language` | anything longer, or two stopwords or more |

Short words and acronyms are protected from fuzziness by text matching but are not codes, so they
do not make a query an `identifier`.

The profile is `Modules/Core/config/search_tuning.php` (`config('search_tuning')`): a `version`, a
`default` set and one optional set per class. The class set overrides `default`; a parameter left
out keeps the planner value. Allowed parameters: `keyword_weight`, `vector_weight`,
`hybrid_weight`, `rrf_weight`, `agreement_boost`, `rerank_blend` (numbers in `[0, 1]`), `rrf_k` and
`rerank_top_k` (integers >= 1); a set defining all three weights must keep one positive. The
profile only writes `plan.ensemble` and `plan.ranking`; it never touches `plan.retrieval`, so
whether vector search runs stays a capability question.

With the switch off, or a missing or invalid profile, the plan is returned untouched (an invalid
profile logs one warning per process). The shipped profile restates the L0 constants
(`rrf_k` 60, `rrf_weight` 0.25, `agreement_boost` 0.15, empty class sets), so switching it on
changes only `meta['tuning']` until a measured profile is committed. A profile with measured values
must cite its source in a `report` key, a path relative to the Core module under
`docs/evaluations/retrieval-tuning/`: a report of `ai:tune-retrieval` that passed the held-out
validation and the noise check and holds the same `default` and class sets (checked by
`CommittedRetrievalTuningProfileTest`; the runtime ignores the key). Profile values come from
`ai:tune-retrieval` (AI module), never from intuition: see
`Modules/AI/docs/rag/MODULE.md`. The tuner holds out a share of its cases to validate the winner and
withholds class overrides backed by too few cases, and it declares no winner when the gain over the committed profile is no bigger than what one flipped case of its sample could produce (the noise margin), so a profile is not just the best fit to its dataset.

### Step 3 — the query vector is an AI-module capability

```php
if (($retrieval['use_vector'] ?? false) !== true || ! $embedder instanceof ITextEmbedder) {
    return null;
}
```

The embedder comes from the `SearchStrategy`, not from the container. Core's strategy has none, so a `fast`
search is keyword-only; the AI module's resolver supplies `SearchEmbedder` for `balanced` and `deep`. Without the AI
module the query vector is `null` and the pipeline stays keyword-only. This is independent of
`core.search.vector.enabled`: both must hold. An embedder that throws is logged (`Query embedding failed`) and
the search continues on keywords.

What is lost without the AI module, per contract:

| Contract | Core fallback | Effect when AI is absent |
|----------|---------------|--------------------------|
| `ITextEmbedder` | none | vector and hybrid retrieval unavailable (`fast` only) |
| `IReranker` | `HeuristicReranker` (pure PHP lexical scoring) | reranking still runs, coarser |
| `ISearchPlanner` | `FallbackSearchPlanner` (rules above) | plans by rules instead of LLM |
| `IQueryIntentParser` | `SimpleQueryIntentParser` | basic intent, no LLM expansion |

Only the embedder has no Core fallback. Lexical tokenization is not affected: it belongs to the
engine analyzers and to `TextMatchOptionsResolver`, both in Core.

### Vector availability guard

Before the query is embedded, `AdvancedSearchService` asks `IVectorSearchAvailability::check($model)`
whether vectors may be used. It asks only when the plan wants vectors and `ITextEmbedder` is bound,
so a keyword-only search never reports a reason. When the answer is no, the query vector is `null`,
the search runs keyword-only (no engine error), and the result carries `meta['vector_disabled']`
with the reason. Reasons, checked in this order:

| Reason | Cause | Checked by |
|--------|-------|------------|
| `disabled` | `core.search.vector.enabled` is false | Core `VectorSearchAvailability` |
| `suspended` | `core.search.vector.suspended_reason` is a non-empty string: an embedding model switch is running, or failed after its start | Core |
| `dimension_mismatch` | the engine's index maps vectors of another length than `core.search.vector.dimensions` | Core, for engines implementing `IReportsVectorDimensions` (Elasticsearch: `embeddings.properties.vector.dims` of the model's index), cached 60 s per model class |
| `no_vectors` | no `core_model_embeddings` row carries the active profile's `model_key`, or no active profile resolves | AI `EmbeddingVectorSearchAvailability`, which decorates Core's guard and runs after it |

Without the AI module only the first three exist. With it, `no_vectors` also answers when the
embeddings feature is off or nothing has been embedded yet. The AI model switch calls
`VectorSearchAvailability::forget()` at activation, so the new dimensions are read at once instead
of after the cache expires. Engines that do not report dimensions (database, Typesense) skip the
`dimension_mismatch` check.

Index documents carry the vectors of one model only: `Searchable::toSearchableArray()` keeps the
embedding rows whose `model_key` equals `VectorModelContext::get()`, which is
`core.search.vector.model` unless code runs inside `VectorModelContext::using($key, ...)` (the switch
does, to write documents with the target's vectors). With no model configured every row is kept.

### PostgreSQL with pgvector

On PostgreSQL with the `vector` extension, `core_model_embeddings.embedding` is created as a plain
`vector` column, with no dimension, in its create migration
(`2024_11_05_233754_create_model_embeddings_table.php`; an existing installation needs
`migrate:fresh`, there is no alter migration). Rows of several models, of different lengths, can
coexist. The migration creates no vector index.

Each embedding profile gets its own partial HNSW expression index, built by
`Modules\Core\Search\Support\PgvectorProfileIndex` (bound as `IProfileVectorIndex`):

```sql
CREATE INDEX IF NOT EXISTS "me_embedding_<12 hex of sha1(model_key)>" ON "<prefix>core_model_embeddings"
  USING hnsw (("embedding"::vector(N)) <ops>) WHERE ("model_key" = '<model_key>')
```

| `similarity` | Operator class | Distance operator |
|--------------|----------------|-------------------|
| `cosine` | `vector_cosine_ops` | `<=>` |
| `l2` | `vector_l2_ops` | `<->` |
| `ip` | `vector_ip_ops` | `<#>` |

Any other similarity throws `InvalidArgumentException`. The name is a fixed prefix and a hash, so
any key gives a valid identifier. `ensure()` reads `pg_indexes` first and issues the `CREATE INDEX`
only when the name is missing; `ensureOnce()` remembers a key per process. The index is created by
the switch's `indexes` phase and, once per process, by the AI synchronizer before it writes rows of
the active profile; it is dropped with its rows at activation and by `ai:embeddings:prune`.

`DatabaseEngine` searches with the same cast, operator and predicate, so the planner can use the
index: `"embedding"::vector(N) <op> ?::vector`, with N from `core.search.vector.dimensions`, the
operator from `core.search.vector.similarity`, and `"model_key" = '<core.search.vector.model>'` as a
literal (not a binding, so a generic plan still matches the partial predicate). Without a configured
model it filters `vector_dims("embedding") = N` instead, because a row of another length would make
the cast fail. A non-positive or non-numeric dimension throws.

Caveats, none verified on a real PostgreSQL yet (open point of the design spec): the build is not
`CONCURRENTLY`, so it blocks writes to `core_model_embeddings` while it runs; the `pg_indexes` check
ignores `indisvalid`, so an invalid index left by a failed build counts as present; that the planner
uses the partial index for this query is by construction, not measured.

### Step 4 — fusion is weighted score **plus** RRF, not RRF alone

The math lives in `RankFusion` (pure, no engine or container), which `EnsembleSearchService`
delegates to and the offline tuner reuses to re-fuse recorded rankings.

Per strategy, hit scores are min-max normalized to `[0,1]` (`RankFusion::minMaxNormalize()`), because BM25
and cosine similarity are not comparable. Then, for every id seen by at least one strategy:

```text
score = Σ_s (normalized_score_s × weight_s)          // weighted component
      + (Σ_s 1 / (rrf_k + rank_s)) × rrf_weight      // rank-based component
      + agreement_boost × (appearances / strategies) // only when appearances > 1
```

Defaults from the plan: `keyword_weight` 0.35, `vector_weight` 0.35, `hybrid_weight` 0.30,
`rrf_k` 60, `rrf_weight` 0.25, `agreement_boost` 0.15. For short queries the planner shifts weights
to `0.30 / 0.40 / 0.30`.

The rank component makes the fusion robust to scale differences; the weighted component keeps the
within-strategy score distance meaningful; the agreement term rewards ids that more than one
strategy found.

### Step 5 — reranking

Reranking runs on the **fused** list, not on the individual strategy lists, and only on the first
`rerank_top_k` entries (default 30). The reranker receives `{query, text}` pairs, never the raw engine
scores. The text is the one the model provides through `IProvidesRerankerText::rerankerTexts()`, called once
per search with every key to rerank and the language of the search; a model that provides none is read from
the hit source (`buildRerankerText()`: title/name plus body area). This matters when the text is not an
attribute of the model: a search hit carries the model's own columns, and translated text lives elsewhere, so
without the contract a model like the CMS `Content` sent the reranker an empty text for every hit and the
rerank returned the fused order whatever model scored it. When no pair has any text at all the rerank fails
(`meta['reranked'] = false`, a warning in the log) instead of scoring empty pairs.

The blend comes from the plan (`ranking.rerank_blend`, set by the tuning profile when it defines
one), falling back to the `search.reranker.weight` runtime setting (`core.search.reranker.weight`,
seeded `0.6`):

```php
$item['score'] = ($item['score'] * (1 - $blend)) + ($rerank_score * $original_max * $blend);
```

The seeded `0.6` reproduces the historical `0.4 / 0.6` split exactly. `0` keeps the fused order,
`1` ranks the top-K by the reranker alone. `$original_max` rescales the reranker's `[0,1]` output onto the fused score range. A reranker
failure (for example the cross-encoder service being down) is caught, logged as a warning, and the
fused order is returned with `meta['reranked'] = false`. Search never fails because of reranking.
An `IReranker` that cannot score must therefore throw, not answer: `CrossEncoderService` throws on an
error status, an unreachable service and an answer that is not one numeric score per pair, because a
list of invented zeros would pass for a rerank that changed nothing and `meta['reranked']` would say
`true`.

A caller can disable it for one search through the plan (`ranking.use_reranker`); otherwise
`config('core.search.reranker.enabled')` decides, and it is off by default.

## The language of the results

The query can be in any language and the vectors carry none, so every vector of every document is searched.
What is language-bound is the **result**: it comes back only in the language requested, whether by a
preference or explicitly, which is the language of the `LocaleContext`. A document that has no translation in
that language is not returned, however strongly it matches: an English query that matches an English
translation strongly still brings out the document in Italian when Italian is requested, as long as the
document exists in Italian. This is strict; the `translations.locale_fallback.*` settings govern how a
translated model is shown elsewhere and do not apply to search.

The restriction is part of the engine request, not a clean-up after it. `EnsembleSearchService` adds a
`locales` where clause to every strategy when the model's engine implements `ILocaleFilterableEngine` and its
index records `locales` (`filtersByLocale()`; Elasticsearch does, and a mono-language model such as a ticket
does not). The Elasticsearch engine turns it into a document-level `terms` filter on the nearest-vector search,
on the keyword search and on the text half of a hybrid search, whose hits are added to the vector ones. Applied
only after the top results are fetched, the documents of another language took the first places and were then
dropped, and a request for five results returned one to four. An engine without the capability is left alone:
it would read `locales` as a model attribute and return nothing.

The text half of a hybrid search is added to the nearest-vector hits, so it carries the **same filters as the
vectors**: the language and every filter the caller passed, the ones the keyword and vector strategies already
applied. It used to carry none, so a document that matched the text but not those filters could still come back
through the hybrid strategy. The generic CRUD search passes the user's ACL row filters this way
(`injectAclFilters()`) and used to reload the hits without them, since only `Media` re-authorized on rehydration,
so for those hits the row-level restriction did not hold, on every model but `Media` and whenever vector search
was on (it is off by default). When there is no filter at all the query is unchanged.

The filters in the engine query are no longer the only barrier. `CrudService` reloads the records behind the hits
of both search paths (orchestrated and Scout) through one query that applies the user's ACL row filters to the
database (`AuthorizationService::applyAclFiltersToQuery()`, the same semantics as everywhere else in the CRUD
service) and then the model's own `IAuthorizesSearchRehydration` guard, so a hit the engine should not have
returned (a strategy that dropped the filters, an index out of date) is dropped there. The cost is that such a
hit takes a place in the page. The CMS and SAO application-content providers have always reloaded through an
authorized query.

The text is matched on the fields of the requested language only (`title.it`, `subtitle.it`, `content.it`, with
the title boosted), analysed with that language's analyser; fields with no language (`entity`, `preset`, ...)
stay, and nested fields, which a plain `multi_match` cannot reach, dates, numbers and `locales` are left out.
The field list comes from the live mapping of the index (cached for five minutes), because the component fields
are mapped dynamically, as an object with a sub-field per language, and from the model's declared mapping when
the cluster cannot be asked. A caller that names its own fields in the text-match options keeps them. The
consequence for ranking is deliberate: a word that only matches the text of another language no longer counts,
so a request in Italian ranks on Italian text and on the vectors.

## Response metadata

`AdvancedSearchResult->meta` carries:

| Key | Meaning |
|-----|---------|
| `driver` | active Scout driver |
| `strategies_executed` / `strategies` | how many and which strategies ran |
| `reranked` | whether reranking actually ran (false after a reranker failure) |
| `reranker_model` | the model that scored the hits, present only when the reranker names it (`IRerankerWithModel`, which `CrossEncoderService` implements from the `model` its service answers with) |
| `matching` | resolved text-match decision plus engine degradations |
| `per_strategy` | per-strategy ordered hits (`id`, `score`, `rank`), consumed by `ai:evaluate-retrieval-strategies` and `ai:tune-retrieval` |
| `tuning` | present only when the tuning profile was applied: `{applied, profile_version, query_class}` |
| `search` | always present: `{mode_requested, mode_applied, degraded_reason, retries_used}`, see [Search modes](#search-modes-how-much-a-request-spends) |
| `unsupported_driver` | present when the engine cannot orchestrate |
| `vector_disabled` | present when the plan wanted vectors and the guard refused them: `disabled`, `suspended`, `dimension_mismatch` or `no_vectors` |
| `timings` | present only when the `search.debug_timings` setting is on: `{intent_ms, plan_ms, vector_ms, ensemble_ms, total_ms}`, see [Measuring the stages](#measuring-the-stages) |

`AdvancedSearchResult->meta` is what `CrudService` hands to `CrudMeta->search`. The CRUD HTTP response
(`CrudController::buildResponse()`) serializes only its `search` block, as `meta.search`; the other keys are read
in PHP (tests, tinker, the AI evaluation commands), not in the JSON a client receives.

### Measuring the stages

The `search.debug_timings` runtime setting (config `core.search.debug_timings`, seeded off, group
`search`) makes `AdvancedSearchService::search()` time its four costly stages with `hrtime()` and add
`meta['timings']`, in milliseconds rounded to 3 decimals:

| Key | Stage |
|-----|-------|
| `intent_ms` | `IQueryIntentParser::parse()` (an LLM call with the AI overlay, a stopword split without it) |
| `plan_ms` | `ISearchPlanner::safePlan()` only (the AI planner caches a plan per query for 10 minutes) |
| `vector_ms` | resolving `ITextEmbedder` and embedding the query; `null` when no embedding ran (no embedder bound, plan without vectors, or guard refusal) |
| `ensemble_ms` | `EnsembleSearchService::search()`: the 1 or 3 engine calls, fusion and reranking |
| `total_ms` | the whole `search()` call; the gap to the sum of the four is plan shaping, text-match resolution, tuning and the vector guard |

A stage that did not run is `null`, never `0`. With the setting off nothing is built and the meta is
unchanged. A stage that throws is rethrown unchanged; with the setting on, an `info` log line
`Search stage failed; timings measured so far` carries `failed_stage` and the partial `timings`.

How to take the numbers (a machine that runs the app against its real engine, never the test suite;
`bootstrap/cache/config.php` must not exist where tests run, so do not `config:cache` to switch the
overlay):

1. Pick a searchable model and a representative query. The snippet sets the flag for its own process,
   so the setting row does not need to change; to see timings from a worker instead, set
   `search.debug_timings` to true in Filament > Settings (run `db:seed --class=CoreDatabaseSeeder`
   first if the row is missing).
2. Run, from the laraplate root:

   ```bash
   php artisan tinker --execute '
   config(["core.search.debug_timings" => true]);
   $service = app(Modules\Core\Search\Services\AdvancedSearchService::class);
   $model = new Modules\CMS\Models\Content();
   $query = "replace with a representative query";
   $metas = [];
   foreach (range(1, 11) as $run) { $metas[] = $service->search($model, $query, 1, 25)->meta; }
   $warm = collect(array_slice($metas, 1))->pluck("timings");
   foreach (["intent_ms", "plan_ms", "vector_ms", "ensemble_ms", "total_ms"] as $stage) {
       $values = $warm->pluck($stage)->reject(fn ($value) => $value === null);
       printf("%-12s cold %10s   warm median %10s\n", $stage, $metas[0]["timings"][$stage] ?? "null", $values->isEmpty() ? "null" : $values->median());
   }
   printf("reranked: %s, vector_disabled: %s\n", var_export($metas[0]["reranked"] ?? null, true), $metas[0]["vector_disabled"] ?? "-");
   '
   ```

   The first run is the cold one (planner cache empty for that query, if it was not searched in the
   last 10 minutes); the median of the other ten is the warm figure. Use a query not searched recently
   to get a true cold run.
3. The loop above measures the path of the `fast` mode, which no longer calls any model. To time the LLM and
   embedding stages, call the AI classes directly with a reachable provider (`OLLAMA_API_URL` for Ollama), for
   example `app(Modules\AI\Services\LlmQueryIntentParser::class)->parse($query)`,
   `app(Modules\AI\Services\SearchOrchestratorAgent::class)->safePlan($query)` and
   `app(Modules\Core\Search\Contracts\ITextEmbedder::class)->embed($query)`, each wrapped in `hrtime()`. Measured
   on 2026-10-09 on a 1 vCPU test server: 23 to 60 s per LLM call, about 80 ms per warm embedding; see the
   *Measurements* section of `2026-09-16-search-modes-and-strategy-resolution-design.md`. The timeout of an LLM
   call in a search is the Setting `features.search_orchestration.timeout` of the AI module (10 s by default, 0 = no limit).
4. Reranking runs only when the plan or the `search.reranker.enabled` setting asks for it: the
   `reranked` line says whether the `ensemble_ms` figure includes it.

`perf:crud` does not exercise this path: it benchmarks `/api/v1/select/{module}/{entity}`, the list
endpoint, without a search query. `perf:bench` and `perf:profile` can target
`GET:/api/v1/search/{module}/{entity}?qs=...&mode=orchestrated` for end-to-end latency or a flame
profile (the public API must be exposed, as `perf:crud` does with the `expose_api` setting), but none
of the perf commands prints the response body, so the per-stage split comes from step 2.

## What the `matching` preference does and does not affect

`strict` / `balanced` / `tolerant` / `auto` resolve to granular `TextMatchOptions` and are passed
**only** to the `keyword` and `hybrid` Scout calls. The `vector` call receives no text-match
options: an embedding has no notion of typo tolerance or token coverage.

The preference therefore never changes ensemble weights, `rrf_k`, `rrf_weight`, `agreement_boost`,
or reranking. Those come from the plan. Preference and plan are two independent axes: the plan
decides *which* strategies and *how to fuse*, the preference decides *how forgiving the lexical
strategy is*.

## Configuration reality check

Consumed at runtime:

| Key | Env | Where it is read |
|-----|-----|------------------|
| `core.search.vector.enabled` | runtime setting (Filament > Settings) | `FallbackSearchPlanner`, engines |
| `core.search.vector.dimensions` | managed setting (written by the AI model switch) | ES `dense_vector` mapping, guard, pgvector query cast |
| `core.search.vector.similarity` | managed setting (written by the AI model switch) | ES mapping, pgvector operator |
| `core.search.vector.model` | managed setting (written by the AI model switch) | `VectorModelContext` (index documents), pgvector query filter |
| `core.search.vector.suspended_reason` | managed setting (`switching` during a switch, else null) | vector guard |
| `search.analyzers` | `SEARCH_ANALYZER_IT`, `SEARCH_ANALYZER_EN` | per-locale text mappings |
| `core.search.reranker.enabled` | runtime setting | `EnsembleSearchService` (plan fallback) |
| `core.search.reranker.top_k` | runtime setting | `EnsembleSearchService` |
| `core.search.reranker.weight` | runtime setting | `EnsembleSearchService` (rerank blend when the plan sets none) |
| `core.search.adaptive_tuning` | runtime setting | `RetrievalTuningProfile` (switch) |
| `core.search.debug_timings` | runtime setting | `AdvancedSearchService` (adds `meta['timings']`) |
| `search_tuning.*` | — (committed file) | `RetrievalTuningProfile`, `ai:tune-retrieval` |
| `search.text_matching.*` | — | `TextMatchOptionsResolver` |

Declared but **not read by any code today** (do not document them as working knobs):

| Key | Env | Status |
|-----|-----|--------|
| `plan.retry_policy.*` | — | produced by both planners, validated by the AI planner, consumed by nobody |

## Evaluation is an offline loop

`ai:evaluate-application-content` (end-to-end ordering) and `ai:evaluate-retrieval-strategies`
(per-strategy ordering, reranker off and on) score the real pipeline against committed relevance
datasets. **No runtime code reads the produced reports.** The loop is: run the command, read the
metrics, change code or configuration in a reviewed commit, re-run. Committed baselines are
regression gates in CI, not inputs to the algorithm.

See `Modules/AI/docs/rag/MODULE.md` for datasets, metrics and baseline locations.

## Common failure modes

| Symptom | Check |
|---------|-------|
| `strategies_executed = 1` when vectors were expected | digits in the query, `core.search.vector.enabled`, AI module present, engine kNN support |
| `reranked = false` | reranker service reachable; look for the `Reranker failed` warning (its context carries the reranker's own report context, such as a `request_id`, when the failure has one) |
| `unsupported_driver = true` | Scout driver is not an orchestration-capable `ISearchEngine` |
| `meta.vector_disabled` = `suspended` | an embedding model switch runs or failed: `ai:embeddings:status`, then `ai:embeddings:switch --resume` or `--abandon` |
| `meta.vector_disabled` = `dimension_mismatch` | the index maps another length than `core.search.vector.dimensions`: finish the switch, or recreate the index |
| `meta.vector_disabled` = `no_vectors` | nothing embedded with the active model: embeddings feature off, or run `ai:embeddings:repair --all` |
| Vector results are noise | records with embeddings but none of the active `model_key`; run `ai:embeddings:repair --stale` |
| Hybrid ranks worse than keyword alone | measure with `ai:evaluate-retrieval-strategies` before changing weights |
| Ranking changed after flipping `search.adaptive_tuning` | read `meta['tuning']` (profile version, query class); switch it off to return to L0 without a deploy |

## FAQ prompts for RAG

- How many retrieval strategies run for a query with a number in it?
- Why is vector search inactive even though `core.search.vector.enabled` is true?
- What does `meta.vector_disabled` mean, and what are its reasons?
- How does PostgreSQL store vectors of two embedding models, and which index serves a query?
- Does `matching=tolerant` change how results are fused?
- Where is the reranker blend defined?
- What does the `search.adaptive_tuning` setting change, and how do I tell from a response?
- Which query class does a query with an invoice code fall into?
- Which search configuration keys are declared but unused?
- Do the evaluation reports influence search at runtime?
