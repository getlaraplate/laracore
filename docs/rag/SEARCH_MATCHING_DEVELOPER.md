# Adaptive search matching — developer and operator guide

## Architecture

Adaptive matching is resolved once before retrieval:

```text
SearchRequest
  -> SearchRequestData
  -> AdvancedSearchService
  -> SearchQuerySyntaxParser
  -> SearchQueryAnalyzer
  -> TextMatchOptionsResolver
  -> ResolvedTextMatch
  -> EnsembleSearchService
  -> Scout builder text_match option
  -> engine adapter
```

HTTP parameters never reach an engine directly. Engines consume only validated `TextMatchOptions`.

## Domain objects

- `TextMatchPreference`: `auto`, `strict`, `balanced`, `tolerant`.
- `SearchTokenKind`: numeric, UUID, email, structured identifier, acronym, short token, or word.
- `SearchQueryAnalyzer`: preserves original case/punctuation while producing normalized classifications.
- `SearchQuerySyntaxParser`: separates free text, `+required` terms, and `"required phrases"` before adaptive analysis.
- `SearchQueryAnalysis`: counts meaningful, protected, and typo-eligible tokens.
- `TextMatchOptionsResolver`: applies defaults, preference presets, adaptive rules, granular overrides, and protected-token invariants.
- `ResolvedTextMatch`: carries analysis, requested/effective preference, effective options, and response metadata.

## Resolution precedence

1. Analyze the original query.
2. Select the automatic behavior.
3. Apply an explicit preference.
4. Apply granular overrides.
5. Enforce identifier protection.
6. Clamp values in `TextMatchOptions`.
7. Translate through the active engine and report degradation.

The current cross-engine safety rule forces `AND`/100% coverage and disables typo expansion for a complete mixed keyword query when any protected token is present. This conservative translation makes the protected token mandatory even on engines without per-token required clauses. Set `identifier_typos=true` only when the caller explicitly accepts fuzzy identifiers; UUIDs remain exact regardless.

Coverage and edit distance are separate. `minimum_should_match` controls how many significant tokens participate; `fuzzy_token_limit` controls how many eligible tokens may contain a correction; `max_edits` controls the character distance within each corrected token. The portable contract currently clamps `max_edits` to one. UUIDs are always exact.

| Profile | 1–2 | 3 | 4 | 5–8 | 9+ | Fuzzy-token limit |
|---------|-----|---|---|-----|----|-------------------|
| strict | 100% | 100% | 100% | 100% | 100% | 1 |
| balanced | 100% | 100% | 75% | 65% | 60% | 2 |
| tolerant | 100% | 66% | 75% | 55% | 50% | 3 |

Auto uses the conservative side for keyword-like input and relaxes toward 65%/60% when at least two stopwords indicate natural-language input. This is intentionally deterministic and can later be replaced by a richer query-shape classifier.

## Required-query syntax contract

`SearchQuerySyntaxParser` is the only component that interprets operators in `qs`. It returns an immutable `ParsedSearchQuery` containing free text, required terms, and required phrases. Adaptive token counting and fuzziness use only free text. Required values are copied into trusted engine options with `toEngineArray()`; public metadata uses `toArray()` and exposes counts only.

Rules:

- `"phrase"` is mandatory, ordered, adjacent, and non-fuzzy;
- `+term` is mandatory anywhere and non-fuzzy;
- `+"phrase"` is accepted as an equivalent redundant form;
- `\"` escapes a literal quote;
- unmatched quotes and standalone `+` degrade to free text without validation errors;
- parsing applies only to `qs`, never filters or other parameters.

Elasticsearch emits separate literal `must` clauses (`best_fields` for terms and `phrase` for phrases). The database engine emits an `AND` group per required value and bound case-insensitive `LIKE` alternatives across declared searchable columns. Typesense receives a cleaned candidate query but currently reports `required_terms` and/or `required_phrases` degradation because native cross-field mandatory adjacency parity is not proven. Degradation entries are emitted only when the corresponding syntax is used.

## Configuration

Defaults and replaceable presets live in `Modules/Core/config/search.php`:

```php
'text_matching' => [
    'defaults' => [
        'typo_tolerance' => true,
        'max_edits' => 1,
        'prefix' => true,
        'minimum_term_length' => 4,
        'two_edit_minimum_term_length' => 8,
        'exact_match_boost' => 2.0,
        'operator' => 'and',
        'minimum_should_match' => 100,
        'fuzzy_token_limit' => 1,
        'identifier_typos' => false,
    ],
    'preferences' => [
        'strict' => [],
        'balanced' => [],
        'tolerant' => [],
    ],
],
```

Preferences remain configuration presets plus resolver policy. Engine code must not branch on preference names; it consumes only resolved granular options. Elasticsearch and Typesense cannot fully enforce a maximum count of fuzzy tokens in one native multi-term query, so capability metadata must continue to describe this semantic as approximate where applicable.

Direct Scout callers can pass effective or override options through:

```php
$builder->options[TextMatchOptionsResolver::BUILDER_OPTION] = [
    'preference' => 'balanced',
    'max_edits' => 1,
];
```

The orchestrated pipeline passes already resolved granular options to keyword and hybrid strategies. Pure vector retrieval receives no text-match options. A preference therefore never changes ensemble weights, `rrf_k`, `rrf_weight`, `agreement_boost`, or reranking: those belong to the plan, not to text matching.

### Reranking

The orchestrated pipeline can rerank the fused top-K results and is **off by default** (`core.search.reranker.enabled` runtime setting, seeded `false`: the first real measure, a cross-encoder on the 61-case private pilot, showed no gain and about 5 s more per search on a CPU host; an existing installation keeps the value its row already holds; `core.search.reranker.top_k`, seeded `30`). `EnsembleSearchService` honours a per-plan `ranking.use_reranker`, falling back to `config('core.search.reranker.enabled')` when the plan omits it, so a caller can still disable it for one search. The bound `IReranker` is `HeuristicReranker` (pure-PHP lexical scoring: exact-phrase, keyword-overlap, title-area — no external service); the AI module overrides the binding with `CrossEncoderService` (an external cross-encoder) when installed. Reranked scores blend the fused score with the reranker score: `ranking.rerank_blend` from the plan, else the `core.search.reranker.weight` runtime setting (seeded `0.6`, the historical 0.4/0.6 split). `AdvancedSearchResult->meta['reranked']` reports whether reranking ran, and is `false` when the reranker threw and the fused order was kept. Fusion always runs when more than one strategy executes. The full retrieval pipeline (plan, strategy count, fusion formula, reranking, response metadata) is documented in [SEARCH_RETRIEVAL_PIPELINE.md](./SEARCH_RETRIEVAL_PIPELINE.md).

### Vector / hybrid retrieval (enablement)

Core owns no vector setting. It defines the contract: `Modules\Core\Search\DTOs\VectorProfile` (`modelKey`, `dimensions`, `similarity`) and `Modules\Core\Search\Contracts\IVectorProfileProvider::current(): ?VectorProfile`, read through `Modules\Core\Search\Support\VectorProfileContext::get()`. Core binds `NullVectorProfileProvider`, which returns `null`: vector/hybrid retrieval is **off** (every plan stays keyword-only, no vector field is indexed, and `Searchable::toSearchableArray()` emits no `embeddings`) until a module binds a provider that returns a profile. The AI module does, so vector retrieval is available only with the AI module installed and its embeddings feature on. The engines (Elasticsearch, Typesense, Database/pgvector), `ElasticsearchTranslator`, `FallbackSearchPlanner`, `AdvancedSearchService`, `VectorSearchAvailability` and `Searchable` all read the profile; there is no default dimension or similarity in Core. Enabling it is not a flag flip, it requires:

1. An online embeddings provider (the AI module's default profile is `openai_compatible:intfloat/multilingual-e5-small`, 384-dim output). The vector length and similarity belong to the AI embedding profile (`ai.features.embeddings.models.*`, verified by `ai:embeddings:probe`) and reach Core through `VectorProfile`; the AI provider returns the profile of the model named by the managed setting `features.embeddings.serving_model` (read-only in Settings, written by the model switch `ai:embeddings:switch`). The Elasticsearch `dense_vector` mapping is built from the profile's dimensions; if an index still maps another length, the vector guard turns vector search off with `dimension_mismatch` instead of querying it (see [SEARCH_RETRIEVAL_PIPELINE.md](./SEARCH_RETRIEVAL_PIPELINE.md), *Vector availability guard*). Typesense stores the vector as `float[]` and needs no dimension.
2. A vector-capable engine (Elasticsearch or Typesense) reachable and healthy.
3. Turning on the AI setting `features.embeddings.enabled` (the single switch; without it the AI provider returns `null`, so there is no vector search and no embedding generation or repair), then, per searchable model, recreating the index so the `embedding` field is mapped at the correct dimension and backfilling the documents. The `Modules\Core\Search\Console` commands do this: `scout:delete-index {Model}` + `scout:index {Model}` rebuild the index from `getSearchMapping()` (which now carries the `dense_vector` mapping), and `scout:reindex {Model}` queues a `ReindexSearchJob` that, over the same population `scout:import` indexes (so content hidden by `LocaleScope`, such as mono-language content, is included), re-emits each document through `toSearchableArray()` so the indexing listener generates the `ModelEmbedding` rows (`scout:import` / `scout:flush` / `scout:sync` cover import, flush, and incremental sync). Until this backfill completes, models carry no vectors and hybrid ranking silently degrades to keyword.

Once enabled, `FallbackSearchPlanner` plans a hybrid strategy for non-numeric queries and `EnsembleSearchService` fuses keyword and vector results (RRF + weighted + agreement) before reranking. A query containing any digit keeps the plan keyword-only, and so does an unbound `ITextEmbedder` (the AI module owns that binding), so vector retrieval needs a vector profile (AI `features.embeddings.enabled` on) **and** the AI module. See [SEARCH_RETRIEVAL_PIPELINE.md](./SEARCH_RETRIEVAL_PIPELINE.md).

## Engine adapters

### Elasticsearch

`ElasticsearchEngine::buildTextMatchQuery()` builds a fuzzy/prefix `multi_match` plus an exact phrase boost. `minimum_should_match` is emitted for relaxed `OR` queries. Fuzziness is disabled for protected-only queries unless identifier typo permission is effective.

### Typesense

`TypesenseEngine::buildTextMatchParameters()` emits `num_typos`, `prefix`, `min_len_1typo`, `min_len_2typo`, and `prioritize_exact_match`. Capabilities declare semantics that are only approximate.

### Database

`DatabaseTextMatchCompiler` always produces bound SQL values. Field identifiers originate from model/search schema metadata and are wrapped by the connection grammar.

Portable drivers use case-insensitive `LIKE`. PostgreSQL optionally uses `strict_word_similarity()`:

```env
SEARCH_DATABASE_PG_TRGM_ENABLED=true
```

The database must also have:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

For production performance, schema fields now declare their physical intent explicitly with `IndexType::Fuzzy`, `IndexType::FullText`, or `IndexType::Prefix`. Plain searchable text no longer implies a full-text database index.

Migration DDL is centralized in the existing `MigrateUtils`:

```php
MigrateUtils::prefixIndex($table, 'slug');
MigrateUtils::fuzzyIndex($tableName, 'name');
MigrateUtils::fullTextIndex($tableName, 'search_text', language: 'italian');
```

Call `prefixIndex()` inside `Schema::create()` and specialized methods after the table exists. PostgreSQL fuzzy indexes install `pg_trgm` and use GIN `gin_trgm_ops`; PostgreSQL full-text indexes use GIN `to_tsvector`. MySQL/MariaDB supports declared full-text indexes but intentionally degrades fuzzy DDL. SQLite keeps prefix B-trees and safely skips specialized DDL. Do not apply fuzzy/full-text indexes indiscriminately to codes, UUIDs, emails, enums, or short machine identifiers.

Oracle DDL maps a single declared fuzzy or full-text column to `CTXSYS.CONTEXT`. Short mutable labels default to `SYNC (ON COMMIT)`; prose defaults to manual synchronization. Query execution still remains on the portable fallback until the schema-aware `CONTAINS`/`SCORE` adapter, lexer/stoplist preferences, privilege checks, synchronization jobs, and rebuild handling are implemented. Never apply `UTL_MATCH` to arbitrary long columns.

Initial migration policy is deliberately conservative: names and short human titles use fuzzy indexes; slugs retain their existing B-tree indexes; prose requires a normalized physical `search_text` column before receiving full-text DDL. JSON component payloads are not indexed as database full text directly.

## Capabilities and degradation

Every `ISearchEngine` implements `textMatchCapabilities()`. Add new semantic controls to the portable contract only when each adapter can either translate or explicitly report them as degraded.

Response metadata is assembled from `ResolvedTextMatch::toMeta()` and engine capabilities. Never include additional raw protected token values in logs or telemetry; counts and kinds are sufficient.

**Indexing degrades when the engine is unreachable.** Indexing is a side effect of a domain write, so `Search\Traits\Searchable::queueMakeSearchable()` and `syncMakeSearchable()` wrap both the index check and the document push. When the failure is a transport failure — `Support\SearchEngineAvailability::isUnreachable()` matches PSR-18 network exceptions, Guzzle and Laravel HTTP connection exceptions, Elastic `NoNodeAvailableException`, and the Typesense unavailable/timeout errors, including wrapped ones — the write completes and a `warning` is logged with driver, index and message. Every other failure (schema, payload, authentication) still propagates. Documents written while the engine was down are not queued for retry: reindex them with `scout:sync` (or `scout:reindex`) once the engine is back. Bulk imports that must skip indexing entirely still have `--no-search` on `{module}:import`.

**Imports index in deferred bulk flushes.** `{module}:import` runs inside `Search\DeferredSearchIndexing::run()`. `Searchable::queueMakeSearchable()` first asks it to `defer()` the models: while a run is active it records `(class, scout key)` and returns, so no `ModelRequiresIndexing`, cache write or queued job happens per save. Every `batchSize` distinct records (scheduled with the connection's `afterCommit()`, so never inside an open transaction) and at the end of the run, the records are reloaded through `makeAllSearchableQuery()` and passed to `ISearchableModel::makeSearchableInBulk()`, the same bulk path `scout:import` uses. With `scout.queue` on and a non-`sync` queue connection the flush dispatches one `Search\Jobs\IndexDeferredSearchChunkJob` (keys only) per chunk instead, and the worker runs `DeferredSearchIndexing::indexChunk()`; otherwise the chunk is indexed in place. `run()` takes an optional `onFlush` closure told about each chunk (class, count, queued, milliseconds). AI's `HandleTranslationReembeddingListener` also defers instead of dispatching a per-locale `GenerateEmbeddingsJob`. In discard mode (`--no-search`, dry-run) the calls are dropped. Models saved inside a transaction are also recorded at save time (an `eloquent.saved: *` listener active during the run), so interrupting the run inside a commit's after-commit callbacks loses none of them. `DeferredSearchIndexing::interrupt()` unwinds the run with `DeferredRunInterruptedException`, at once or after the flush in progress, and the run flushes on its way out; the import command calls it from its Ctrl+C / SIGTERM handler.

## Testing requirements

Contract tests must cover:

- Unicode proper names and common misspellings;
- uppercase acronyms;
- structured codes, numbers, UUIDs, and emails;
- two-token `AND` behavior;
- medium and long minimum-match thresholds;
- strict and tolerant preference behavior;
- granular override precedence;
- explicit identifier typo opt-in;
- engine parameter translation;
- builder propagation and response metadata;
- portable database SQL and PostgreSQL trigram SQL.

Run:

```bash
php artisan test --compact Modules/Core/tests/Integration/Search
php artisan test --compact Modules/Core/tests/Integration/Http/Requests/AuthAndSearchRequestsTest.php
vendor/bin/pint --dirty
```
