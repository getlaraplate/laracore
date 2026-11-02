# Event orchestration — search indexing and modification moderation

## Purpose

Core is the **event bus** between domain modules (CMS, ERP, …) and optional AI capabilities. Modules do not import each other for these pipelines; they use Core events, registries, and per-model settings.

## Transactional outbox

`OutboxRecorder` writes `core_outbox_events` inside the caller transaction and queues `PublishOutboxEventJob` after commit. Rows carry UUID `event_id`, event/aggregate identity, JSON payload, timestamps, attempts, and the last error. Rollbacks remove the row; missing/already-published rows are harmless no-ops. `OutboxPublisher` is replaceable. The default `StubOutboxPublisher` performs no external I/O and marks delivery complete, so production broker/webhook delivery requires an application binding and idempotent consumers keyed by `event_id`.

This document covers two orchestration patterns:

1. **Search indexing** — `ModelRequiresIndexing` (embeddings + optional translation → Elasticsearch/Typesense)
2. **Modification moderation** — `ModificationRequiresModeration` (optional AI vote on pending approvals)

Full technical detail (extra diagrams): `Modules/Core/docs/EVENT_ORCHESTRATION.md`.

## Capabilities

| Pattern | Core event | AI pre-processing keys | Core fallback when AI skips |
|---------|------------|------------------------|-----------------------------|
| Search indexing | `ModelRequiresIndexing` | `embeddings`, `translation` | **Runs** `IndexInSearchJob` |
| Modification moderation | `ModificationRequiresModeration` | `ai_approval` | **No-op** (humans only) |

## Design principles

- Core owns events, cache coordination, finalize/fallback listeners.
- Domain modules register adapters (e.g. CMS `CommentModerationAdapter` on `ModerationAdapterRegistry`).
- AI never imports CMS/ERP; it resolves context via the registry.
- Opt-in per model: `translations.auto.{table}`; AI moderation per entity via the AI module's `ai.features.moderation.entities.{table}`; search via `Searchable` + `$embed`.

## InternalFlow — search indexing

### Trigger

Models with `Modules\Core\Search\Traits\Searchable` call `queueMakeSearchable()` / `makeSearchable()` → `ModelRequiresIndexing`.

### Main classes

| Layer | Class | Role |
|-------|-------|------|
| Core | `Events\ModelRequiresIndexing` | Orchestration state |
| Core | `Events\ModelPreProcessingCompleted` | One step done (`embeddings`, `translation`) |
| Core | `Listeners\IndexModelFallbackListener` | Index without AI when `!handled` |
| Core | `Listeners\FinalizeModelIndexingListener` | Dispatches `IndexInSearchJob` when all steps complete; on a `model_indexing` cache-miss (10-min TTL expired) indexes the searchable model directly, so a late pre-processing retry still patches the document |
| Core | `Search\Jobs\IndexInSearchJob` | Writes to Scout engine |
| AI | `Listeners\HandleModelIndexingListener` | Registers embeddings, dispatches `GenerateEmbeddingsJob` |
| AI | `Jobs\GenerateEmbeddingsJob` | Persists vectors |
| AI | `Listeners\HandleModelTranslationListener` | `TranslatedModelSaved` → `TranslateModelJob` |

### Cache

| Key | Pre-processing |
|-----|----------------|
| `model_indexing:{table}:{id}` | `embeddings`, `translation` |

### Configuration

| Layer | Keys |
|-------|------|
| Scout | `SCOUT_DRIVER`, model `$embed`; vector fields are indexed only when `IVectorProfileProvider::current()` returns a `VectorProfile` (Core has no vector setting; its default provider returns `null`, so search is keyword-only) |
| AI | `ai.features.embeddings.enabled` (its `ActiveEmbeddingProfileProvider` returns the profile of `features.embeddings.serving_model` only while this is on) |
| Per model | `translations.auto.{table}` via `PerModelSettingResolver` |

## InternalFlow — modification moderation

### Trigger

When an **active** `Modification` is **created** (`wasRecentlyCreated`), Core emits `ModificationRequiresModeration` (emitter in `Core\Providers\EventServiceProvider`). Pending comments may fire before `modifiable_id` is set; builders read context from modification JSON.

### Main classes

| Layer | Class | Role |
|-------|-------|------|
| Core | `Events\ModificationRequiresModeration` | Same orchestration pattern as indexing |
| Core | `Events\ModificationApproved` | Fired by `ModificationVoteService` after the approving vote's transaction commits, for every model |
| Core | `Events\ModificationRejected` | Fired by `ModificationVoteService` after a rejecting vote commits; `modifiable` is null for a rejected create |
| Core | `Events\ModificationWithdrawn` | Fired by `ModificationVoteService::withdraw()` after the author drops a request; the modification and its votes are already deleted |
| Core | `Events\ModificationPreProcessingCompleted` | e.g. `ai_approval` done |
| Core | `Contracts\ModerationAdapter` | Domain adapter (returns `ModerationRequest`) |
| Core | `Services\ModerationAdapterRegistry` | Resolves adapter by modification |
| Core | `Data\ModerationInput` / `ModerationRequest` | Neutral input + domain-owned prompts |
| Core | `Listeners\ModificationModerationFallbackListener` | No-op if `!handled` |
| Core | `Services\PerModelSettingResolver` | `auto_translate_*`, `soft_deletes_*`, … |
| AI | `Listeners\HandleModificationModerationListener` | Dispatches `ApproveModificationJob` |
| AI | `Jobs\ApproveModificationJob` | LLM vote → `approvals` / `disapprovals` `meta` |
| CMS | `CommentModerationAdapter` + `CommentModerationPrompt` | Article, optional parent comment, prompts |

### Cache

| Key | Purpose |
|-----|---------|
| `modification_moderation:{modification_id}` | Cached event until pre-processing completes |

### Configuration

| Layer | Keys |
|-------|------|
| AI global | `ai.features.moderation.*`, AI module settings `features.moderation.*` in Filament (group `ai`); no env variable |
| Per entity | AI module setting `ai.features.moderation.entities.{table}` (group `moderation`), offered only for models with a registered `ModerationAdapter` |
| System actor | the user named by `permission.users.system` (`SYSTEM_USER`), seeded by Core |

### Post-decision

`ModificationVoteService` is the only emitter of the decision events: `cast()` fires `ModificationApproved` or `ModificationRejected`, `applyAuthorCredit()` fires `ModificationApproved` when the author's own credit completes the quorum, and `withdraw()` fires `ModificationWithdrawn`. Each fires through `afterCommit()`, so a listener never sees the record before the decision is committed. `ModificationApproved` → AI `HandleModificationApprovedTranslationListener` may dispatch `TranslateModelJob` when `auto_translate_*` is enabled.

## HowToUse — extend

### New searchable model

1. Add `Searchable`, implement `toSearchableArray()` / `$embed`.
2. Enable `ai.features.embeddings.enabled` and provider.
3. Optionally `translations.auto.{table}`.

### New moderatable model

1. Implement `ModerationAdapter` in the domain module (build `ModerationRequest` with domain prompts).
2. Register on `ModerationAdapterRegistry` in module `ServiceProvider`.
3. Enable `ai.features.moderation.entities.{table}` for the entity (only models with a registered `ModerationAdapter` have one).
4. No AI module code changes required.

## ErrorsAndTroubleshooting

| Symptom | Check |
|---------|--------|
| Model never indexed | `Searchable`, Scout driver, `IndexModelFallbackListener` registered |
| Embeddings missing | `ai.features.embeddings.enabled`, model `$embed`, `GenerateEmbeddingsJob` queue |
| AI moderation never runs | `ai.features.moderation.enabled`, a user with the `permission.users.system` username, registry builder, `ai.features.moderation.entities.{table}` |
| Comment pending, no AI | `CommentModerationContextBuilder` registered in `CMSServiceProvider` |
| Humans only (expected) | AI skipped → fallback is no-op; Filament approval still works |

## FAQPrompts

- How does ModelRequiresIndexing work with embeddings and translation?
- What is ModificationRequiresModeration and who emits it?
- Difference between search indexing fallback and moderation fallback?
- How do I add AI moderation for a new model type?
- What settings control AI moderation and auto_translate per table?
- Where does CMS register CommentModerationContextBuilder?
