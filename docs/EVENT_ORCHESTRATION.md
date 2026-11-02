# Event orchestration (Core event bus)

> **RAG / assistant corpus:** a retrieval-optimized copy lives in [`docs/rag/EVENT_ORCHESTRATION.md`](rag/EVENT_ORCHESTRATION.md). Run `php artisan ai:index-rag-docs` after editing it. This file is the full technical reference (extra diagrams).

Core owns the **contract** between domain modules (CMS, ERP, …) and optional capabilities (AI, search). Modules never import each other for these pipelines; they communicate through Core events, registries, and settings.

## Transactional outbox

`OutboxRecorder` persists a `core_outbox_events` row in the caller's database transaction and schedules `PublishOutboxEventJob` with `afterCommit()`. A rollback therefore removes the event together with the domain change. Each row has a stable UUID `event_id`, event and aggregate identifiers, JSON payload, occurrence/publication timestamps, attempt count, and last error.

The queued job is unique per outbox row, skips missing or already published rows, records failures, and calls the `OutboxPublisher` contract. Core binds `StubOutboxPublisher` by default: it performs no external I/O and the job marks the row published. Deployments that need broker, webhook, or event-stream delivery must replace that binding; consumers must use `event_id` as their idempotency key because delivery transports can be at least once.

This document describes two orchestration patterns:

1. **Search indexing** — `ModelRequiresIndexing` (embeddings + optional translation sync → Elasticsearch/Typesense)
2. **Modification moderation** — `ModificationRequiresModeration` (optional AI vote on pending approvals)

Both mirror the same ideas: emit always, optional AI pre-processing, cache coordination, Core finalize/fallback listeners.

---

## Design principles

| Principle | Implementation |
|-----------|----------------|
| Core is the bus | Events and registries live in `Modules\Core` |
| Domain modules register adapters | e.g. CMS registers `CommentModerationContextBuilder` |
| AI never imports CMS/ERP | AI resolves builders via `ModerationContextBuilderRegistry` |
| Opt-in per model | Settings: `translations.auto.{table}`, AI module's `ai.features.moderation.entities.{table}`; search: `Searchable` + `$embed` |
| Fallback when AI skips | Indexing: Core still runs `IndexInSearchJob`; moderation: humans only (no-op) |

---

## 1. Search indexing (embeddings + translations)

### Trigger

Any model using `Modules\Core\Search\Traits\Searchable` calls `queueMakeSearchable()` / `makeSearchable()`, which dispatches:

- **Event:** `Modules\Core\Events\ModelRequiresIndexing`
- **Payload:** `Model $model`, `bool $sync`

### High-level flow

```mermaid
flowchart TB
    subgraph Core
        ST[Searchable trait]
        E1[ModelRequiresIndexing]
        FB[IndexModelFallbackListener]
        FIN[FinalizeModelIndexingListener]
        IDX[IndexInSearchJob]
    end
    subgraph AI
        HMI[HandleModelIndexingListener]
        GEJ[GenerateEmbeddingsJob]
        HMT[HandleModelTranslationListener]
        TMJ[TranslateModelJob]
    end
    ST --> E1
    E1 --> HMI
    E1 --> FB
    HMI --> GEJ
    GEJ --> FIN
    HMT --> TMJ
    TMJ --> FIN
    FIN --> IDX
    FB --> IDX
```

### Sequence (async, with embeddings)

```mermaid
sequenceDiagram
    participant Model as Searchable model
    participant Core as Core (Searchable)
    participant AI as AI HandleModelIndexingListener
    participant Emb as GenerateEmbeddingsJob
    participant Cache as Cache model_indexing:*
    participant Fin as FinalizeModelIndexingListener
    participant ES as IndexInSearchJob

    Model->>Core: save / queueMakeSearchable()
    Core->>Core: event ModelRequiresIndexing
    AI->>AI: shouldHandle? embeddings enabled + $embed
  AI->>Core: addRequiredPreProcessing(embeddings)
    AI->>Cache: put indexing event
    AI->>Emb: dispatch job
    AI->>Core: markAsHandled()
    Emb->>Emb: EmbeddingService embedDocument()
    Emb->>Core: event ModelPreProcessingCompleted(embeddings)
    Fin->>Cache: get indexing event
    Fin->>Fin: markPreProcessingCompleted(embeddings)
    alt all pre-processing done
        Fin->>ES: dispatch IndexInSearchJob
        Fin->>Cache: forget key
    else wait for more steps
        Fin->>Cache: put updated event
    end
```

### Translation synchronized with indexing

When a translatable **and** searchable model is saved:

```mermaid
sequenceDiagram
    participant Model as HasTranslations + Searchable
    participant Core as TranslatedModelSaved
    participant AI as HandleModelTranslationListener
    participant Cache as Cache model_indexing:*
    participant TMJ as TranslateModelJob
    participant Fin as FinalizeModelIndexingListener

    Model->>Core: event TranslatedModelSaved
    AI->>AI: autoTranslateEnabledBySettings?
    AI->>Cache: addRequiredPreProcessing(translation) if indexing pending
    AI->>TMJ: dispatch TranslateModelJob
    TMJ->>Core: ModelPreProcessingCompleted(translation)
    Fin->>Fin: when embeddings + translation both complete → IndexInSearchJob
```

If indexing was not started yet, translation runs **without** blocking on the indexing cache (see `HandleModelTranslationListener`).

### Fallback (AI disabled or model without embeddings)

```mermaid
sequenceDiagram
    participant Core as ModelRequiresIndexing
    participant FB as IndexModelFallbackListener
    participant ES as IndexInSearchJob

    Core->>FB: handle (handled = false)
    FB->>ES: dispatch IndexInSearchJob (no embeddings)
```

### Cache keys and pre-processing types

| Cache key | Event type | Pre-processing keys |
|-----------|------------|---------------------|
| `model_indexing:{table}:{id}` | `ModelRequiresIndexing` | `embeddings`, `translation` |

The `model_indexing` entry has a **10-minute TTL**. If a pre-processing step finishes after it expires (e.g. a manual `queue:retry` of an embedding job hours later), `FinalizeModelIndexingListener` no longer finds the coordination event and instead indexes the searchable model directly, so the regenerated data still reaches the search engine instead of being dropped.

### Core classes

| Class | Role |
|-------|------|
| `Events\ModelRequiresIndexing` | Orchestration state (`handled`, `required_pre_processing`, …) |
| `Events\ModelPreProcessingCompleted` | Signals one step finished (`embeddings`, `translation`) |
| `Listeners\IndexModelFallbackListener` | Index without AI when `!handled` |
| `Listeners\FinalizeModelIndexingListener` | Dispatches `IndexInSearchJob` when all steps complete; on a cache-miss (the `model_indexing` entry expired) indexes the searchable model directly so a late pre-processing retry still patches the document |
| `Search\Jobs\IndexInSearchJob` | Writes document to Scout engine |

### AI classes

| Class | Role |
|-------|------|
| `Listeners\HandleModelIndexingListener` | Registers `embeddings`, dispatches `GenerateEmbeddingsJob` |
| `Jobs\GenerateEmbeddingsJob` | Persists vectors, emits `ModelPreProcessingCompleted` |
| `Listeners\HandleModelTranslationListener` | `TranslatedModelSaved` → `TranslateModelJob` |
| `Jobs\TranslateModelJob` | Fills translation rows, may emit `ModelPreProcessingCompleted('translation')` |

### Configuration (summary)

| Layer | Keys |
|-------|------|
| Scout / Core | `SCOUT_DRIVER`, model `$embed`; vector fields are indexed only when `IVectorProfileProvider::current()` returns a `VectorProfile` (`Searchable::vectorSearchEnabled()`); Core has no vector setting and its default provider returns `null` (keyword-only) |
| AI | `ai.features.embeddings.enabled` (also makes the AI provider return a profile, from `features.embeddings.serving_model`), embedding provider env vars |
| Per model | `translations.auto.{table}` (translations group) via `PerModelSettingResolver` |

See also: [Modules/AI/docs/SEARCH_AND_TRANSLATION.md](../AI/docs/SEARCH_AND_TRANSLATION.md).

---

## 2. Modification moderation (approvals + optional AI)

### Trigger

When an **active** `Modification` is **created** (`wasRecentlyCreated`), Core emits:

- **Event:** `Modules\Core\Events\ModificationRequiresModeration`
- **Payload:** `Modification $modification`, `bool $sync`

Emitter: `Modules\Core\Providers\EventServiceProvider` (no CMS-specific listener).

> Pending comments create a modification **before** the comment row is public; the event fires on first save even if `modifiable_id` is still null. The AI builder reads context from the modification JSON (`body`, `content_id`, …).

### Module boundaries

```mermaid
flowchart LR
    subgraph CMS
        Comment[Comment + CommentApprovalCapture]
        Builder[CommentModerationContextBuilder]
    end
    subgraph Core
        Mod[Modification]
        Ev[ModificationRequiresModeration]
        Reg[ModerationContextBuilderRegistry]
        Vote[ModificationVoteService]
        EvA[ModificationApproved / Rejected / Withdrawn]
    end
    subgraph AI
        L[HandleModificationModerationListener]
        J[ApproveModificationJob]
        S[ModerationService]
    end
    Comment --> Mod
    Mod --> Ev
    Builder --> Reg
    Ev --> L
    L --> J
    J --> Reg
    J --> S
    Mod --> Vote
    Vote --> EvA
```

### Sequence (comment + AI enabled)

```mermaid
sequenceDiagram
    participant User
    participant CMS as CMS CommentApprovalCapture
    participant Mod as Modification
    participant Core as Core emitter
    participant AI as HandleModificationModerationListener
    participant Job as ApproveModificationJob
    participant Reg as ModerationContextBuilderRegistry
    participant CMSB as CMS CommentModerationContextBuilder
    participant Vote as approvals / disapprovals

    User->>CMS: Comment save (pending)
    CMS->>Mod: create active modification + diff
    Mod->>Core: saved → ModificationRequiresModeration
    Core->>AI: event
    AI->>AI: moderation.enabled + registry.supports + ai.features.moderation.entities.* setting
    AI->>AI: addRequiredPreProcessing(ai_approval)
    AI->>Job: dispatch ApproveModificationJob
    AI->>Core: markAsHandled()
    Job->>Reg: build(modification)
    Reg->>CMSB: build context (Content + body)
    Job->>Job: ModerationService.analyze()
    Job->>Vote: system user approve/disapprove + meta JSON
    Job->>Core: ModificationPreProcessingCompleted(ai_approval)
    Note over User,Vote: Humans may still approve/reject in Filament
    User->>Core: human approve (ModificationVoteService.cast)
    Core->>CMS: Comment.applyModificationChanges() in the vote's transaction
    Core->>Core: after commit: ModificationApproved(modification, comment)
```

### Fallback (no AI)

```mermaid
sequenceDiagram
    participant Core as ModificationRequiresModeration
    participant FB as ModificationModerationFallbackListener

    Core->>FB: handle (handled = false)
    Note over FB: No-op — human approval workflow unchanged
```

Unlike indexing, there is **no** automated fallback step when AI skips moderation.

### Decision events

`ModificationVoteService` is the only emitter of the three decision events, for every model with
`HasApprovals`, and fires each through `afterCommit()` on the modification's connection, so no
listener runs before the decision is committed:

| Event | Fired by | `modifiable` |
|-------|----------|--------------|
| `ModificationApproved` | `cast()` when an approving vote completes the quorum; `applyAuthorCredit()` when the author's own credit does | The record, after the diff is written or the operation run |
| `ModificationRejected` | `cast()` when a rejecting vote completes the quorum | The record, or `null` for a rejected create |
| `ModificationWithdrawn` | `withdraw()`, after the author drops the request and its votes | The record, or `null` for a create |

### Post-approval: translations

```mermaid
sequenceDiagram
    participant Svc as ModificationVoteService
    participant Core as ModificationApproved
    participant AI as HandleModificationApprovedTranslationListener

    Svc->>Core: event(modification, modifiable) after commit
    AI->>AI: HasTranslations + auto_translate_* ?
    AI->>AI: dispatch TranslateModelJob
```

### Registry (opt-in per modifiable type)

```php
// Modules\Core\Contracts\ModerationContextBuilder
interface ModerationContextBuilder {
    public function supports(Modification $modification): bool;
    public function build(Modification $modification): ModerationContext;
}
```

CMS registers in `CMSServiceProvider::boot()`:

```php
$this->app->make(ModerationContextBuilderRegistry::class)
    ->register($this->app->make(CommentModerationContextBuilder::class));
```

### Cache keys

| Cache key | Purpose |
|-----------|---------|
| `modification_moderation:{modification_id}` | Cached `ModificationRequiresModeration` until `ModificationPreProcessingCompleted` |

### Core classes

| Class | Role |
|-------|------|
| `Events\ModificationRequiresModeration` | Same orchestration pattern as indexing |
| `Events\ModificationApproved` | `Modification` + `Model $modifiable`, fired by `ModificationVoteService` after the approval commits |
| `Events\ModificationRejected` | `Modification` + `?Model $modifiable`, fired after a rejection commits |
| `Events\ModificationWithdrawn` | `Modification` + `?Model $modifiable`, fired after the author withdraws |
| `Events\ModificationPreProcessingCompleted` | e.g. `ai_approval` step done |
| `Contracts\ModerationContextBuilder` | Domain adapter interface |
| `Services\ModerationContextBuilderRegistry` | Resolves builder by modification |
| `Listeners\ModificationModerationFallbackListener` | No-op if `!handled` |
| `Listeners\FinalizeModificationModerationListener` | Clears cache when steps complete (v1) |
| `Services\PerModelSettingResolver` | Cached DB flags (`auto_translate_*`, …) |

### Configuration (summary)

| Layer | Keys |
|-------|------|
| AI global | `ai.features.moderation.*`, AI module settings `features.moderation.*` in Filament (group `ai`); no env variable |
| Per entity | AI module setting `ai.features.moderation.entities.{table}` (group `moderation`), offered only for models with a registered `ModerationAdapter` |
| System actor | the user named by `permission.users.system` (`SYSTEM_USER`), seeded by Core |

See also:

- [Modules/AI/docs/MODERATION.md](../AI/docs/MODERATION.md) — AI listeners, job, thresholds
- [Modules/CMS/docs/COMMENT_MODERATION.md](../CMS/docs/COMMENT_MODERATION.md) — comment capture and CMS adapter

---

## Comparison

| Aspect | Search indexing | Modification moderation |
|--------|-----------------|-------------------------|
| Core event | `ModelRequiresIndexing` | `ModificationRequiresModeration` |
| AI pre-processing key | `embeddings`, `translation` | `ai_approval` |
| Core fallback if AI skips | **Runs** `IndexInSearchJob` | **No-op** (humans only) |
| Domain adapter | Model traits (`Searchable`, `HasTranslations`) | `ModerationContextBuilder` registry |
| Durable outcome | Search index + embeddings table | `approvals` / `disapprovals` + `meta` |
| Post-success event | `ModelPreProcessingCompleted` | `ModificationPreProcessingCompleted` |

---

## Extending

### New searchable model

1. Add `Searchable` trait and implement `toSearchableArray()` / `$embed`.
2. Ensure `ai.features.embeddings.enabled` and provider configured.
3. Optionally enable `translations.auto.{table}` for `TranslatedModelSaved` flow.

### New moderatable model

1. Implement `ModerationContextBuilder` in the domain module.
2. Register it on `ModerationContextBuilderRegistry` in the module `ServiceProvider`.
3. Enable `ai.features.moderation.entities.{table}` for the entity (only models with a registered `ModerationAdapter` have one).
4. No changes required in the AI module.

---

## Related specs

- `docs/superpowers/specs/2026-05-15-modification-moderation-design.md` — moderation design (orchestration)
- `docs/superpowers/specs/2026-05-15-cms-comments-moderation-design.md` — comment product rules (thresholds, Filament, ratings)
