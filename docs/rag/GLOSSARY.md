# Core module glossary

Canonical English names for Core platform entities. Use these terms in code, APIs, and cross-module documentation.

## Identity and access


| Term                      | Meaning                                                                 |
| ------------------------- | ----------------------------------------------------------------------- |
| **User**                  | Authenticated principal; Fortify + Sanctum + optional 2FA and social login. |
| **Role**                  | Hierarchical Spatie role (`parent_id` inheritance).                       |
| **Permission**            | Operation-level grant (e.g. `default.orders.select`).                   |
| **ACL**                   | Row-level filter attached to a permission; restricts which records match. |
| **AuthorizationService**  | Checks permissions and injects/applies ACL filters on queries.          |
| **AclResolverService**    | Resolves effective ACLs for a user/permission; combines filters with OR logic. |
| **unrestricted**          | ACL flag: user sees all rows for that permission (no filter).          |
| **api guard** | Guard of every `/api` route. Permissions exist for `web` and `api` with the same names; a role holds those of its own guard. |
| **AuthenticateApiRequest** | Middleware that owns the `expose_api` switch, the bearer token, the anonymous fallback, the token's network list and the rate limit of every `/api` route. |
| **anonymous user** | Account named by `permission.users.guest`; the caller of `/api` with no token. Holds the `guest` role of both guards. |
| **SelfAuthenticatedApiRoute** | Marker for an `/api` route whose callers send their own secret as bearer; the route authenticates, Laraplate does not read the bearer as a token. |
| **service account** | Non-interactive user (`is_service_account`): cannot sign in or open a panel, authenticates with tokens only. |
| **token ability** | An `api` permission name carried by a token; a request needs the role permission and the ability. |
| **RelationFilter** | ACL node that conditions a record on a related record (`whereHas` or `whereHasMorph`). |
| **IsPartOfParent** | Contract of a model that only exists inside its parent record and inherits the parent's visibility. |
| **media projection** | Safe serialization of a media on the `api` guard: URLs, dimensions and alt text, no storage details. |
| **License**               | Seat / entitlement record gating module or feature access.              |
| **Lease**                 | Owned, expiring lock taken by opening an edit form; only its holder may write. |
| **Hold**                  | Owned lock with no deadline; deliberate, needs the `lock` permission.    |
| **Freeze**                | **Ownerless** lock: nobody may write. Ownerless, not permanent — a freeze can expire. |
| **`locked_until`**        | Moment a lock lapses; null means never. Evaluated on read, so a lapsed lock is free at once. |
| **lock guard**            | `LockedModelSubscriber`: refuses saves, deletes and replicates on a record the writer does not hold. |
| **`Locked::withoutGuard()`** | Explicit, scoped bypass of the guard for system work that must go through. |
| **Per-row select check** | `HasValidations` verifying the table-level permission on every hydrated row. Suppressed by the CRUD read engine, and never applied to roles or permissions. |
| **Attributes writable while locked** | Attributes a model declares as not being edits, which a locked record still accepts from any caller. Empty by default. |
| **`lock_version`**        | Optimistic version; a mismatch on write is a 409, not a server fault.   |


### Permission vs ACL


| Component      | Question answered                              | Scope                                      |
| -------------- | ---------------------------------------------- | ------------------------------------------ |
| **Permission** | Can the user perform this operation?           | Operation (SELECT, INSERT, UPDATE, DELETE) |
| **ACL**        | On which records?                              | Row-level filters on queries               |


## CRUD pipeline


| Term               | Meaning                                                                      |
| ------------------ | ---------------------------------------------------------------------------- |
| **CrudService**    | Orchestrates list/detail/insert/update/delete/history/tree operations.       |
| **CrudController** | HTTP entry point mapping routes to `CrudService`.                            |
| **QueryBuilder**   | Builds Eloquent queries from `RequestData` (filters, sorts, relations).      |
| **ModelPropertyResolver** | Classifies a flat filter property that is not a column: translated field (request locale), dynamic field (searched in every JSON container) or attribute mounted from a related record (`IMountsRelatedAttributes`). |
| **DynamicEntity**  | Runtime model resolver: maps entity name string to Eloquent class + metadata. |
| **ResponseBuilder**| Formats `CrudResult` to JSON/XML with optional caching.                      |
| **FiltersGroup**   | Nested AND/OR filter tree applied by `QueryBuilder`.                         |
| **CrudResult**     | Service result envelope: data rows + pagination/meta.                        |
| **AggregateQuery** | The validated request of the aggregate route as a value (measures, one group, period, sort, limit, `other`, `compare`); built by `AggregateRequest` and by any other caller through `fromArray()`. |
| **AggregateEngine** | Runs an `AggregateQuery` over the rows the ACL and the list filters leave, and returns counts, sums, averages, minima and maxima by group or time bucket. |
| **AggregateException** | Refusal of the aggregate route: a contract code (`INVALID_FIELD`, `UNREADABLE_FIELD`, ...), a message without SQL and the `field` that caused it; answers `422`. |


## Dynamic entity stack


| Term             | Meaning                                                              |
| ---------------- | -------------------------------------------------------------------- |
| **Entity**       | Abstract schema owner: defines fields and presets for a business object type. |
| **Preset**       | Versioned field layout template under an `Entity`.                   |
| **Presettable**  | Snapshot row binding one `Preset` to one `Entity` at a point in time. |
| **Field**        | Column definition (type, validation, UI hints) for dynamic entities. |
| **SchemaInspector** | Introspects database schema for `DynamicEntity` runtime.          |


## Lifecycle traits


| Term                   | Meaning                                                           |
| ---------------------- | ----------------------------------------------------------------- |
| **SoftDeletes**        | Logical delete with `deleted_at`; refresh command syncs DB triggers. |
| **HasVersions**        | Immutable version history via `Version` rows.                     |
| **HasApprovals**       | Captures creates, updates, deletes, force deletes and restores its author may not apply alone as `Modification` requests decided by votes. |
| **Modification**       | A request awaiting approval: its `operation` and, for creates and updates, the diff. Kept inactive once decided. |
| **PendingDeletionStrategy** | What a record whose deletion waits looks like: `Block` (visible, refuses saves) or `Hide` (filtered for users who cannot decide). |
| **Withdrawal**         | The author drops their own pending request and its votes before the decision. |
| **HasValidity**        | `valid_from` / `valid_to` temporal validity window.               |
| **HasLocks**           | Application-level record lock on business events.                 |
| **HasOptimisticLocking** | Concurrency guard via version column.                           |


## Event orchestration (cross-module bus)


| Term                                       | Meaning                                                                                  |
| ------------------------------------------ | ---------------------------------------------------------------------------------------- |
| **ModelRequiresIndexing**                  | Emitted by `Searchable` when a model should be indexed.                                  |
| **IndexInSearchJob**                       | Core job writing documents to the configured search engine.                              |
| **ModificationRequiresModeration**         | Emitted when a new `Modification` needs optional AI pre-processing.                      |
| **ModelPreProcessingCompleted**            | Signals one pre-processing step finished (embeddings, translation, AI vote).               |
| **FinalizeModelIndexingListener**          | Dispatches `IndexInSearchJob` when all pre-processing steps complete.                    |
| **ModerationContextBuilderRegistry**       | Domain modules register builders; AI resolves at runtime.                                |
| **IndexModelFallbackListener**             | Ensures indexing proceeds when AI does not handle the event.                             |
| **ModificationModerationFallbackListener** | No-op fallback when AI moderation is skipped.                                             |


## Search


| Term                  | Meaning                                                         |
| --------------------- | --------------------------------------------------------------- |
| **Searchable**        | Trait: `queueMakeSearchable()`, `$embed`, vector search hooks.  |
| **Transactional outbox** | `OutboxRecorder` stores durable integration events in `core_outbox_events` within the domain transaction; `PublishOutboxEventJob` delivers them through the replaceable `OutboxPublisher` contract after commit. |
| **Module import framework** | Core `AbstractImportCommand` and neutral runner/resolver/discovery contracts used by destination-specific module commands; Core exposes no runnable generic import command. |
| **SchemaDefinition**  | Describes indexable fields and analyzers for a model.           |
| **ISearchEngine**     | Contract for Elasticsearch, Typesense, or database translator backends. |
| **ModelEmbedding**    | Persisted embedding vectors linked to searchable models.        |
| **Indexed relation-field filter** | Search filter using a schema-declared dot path such as `tags.id`; it targets relation data already stored in the search document. |
| **Relation anti-exists filter** | `!=` / `not in` on an indexed relation field; means no related indexed row matches the value/list. |
| **TextMatchPreference** | Adaptive caller preference: `auto`, `strict`, `balanced`, or `tolerant`; resolved before engine translation. |
| **TextMatchOptions** | Granular effective text matching contract shared by all engines. |
| **Protected search token** | Acronym, code, UUID, email, number, or short token that remains non-fuzzy unless explicitly enabled. |
| **SearchQueryAnalyzer** | Classifies original query tokens and selects significant/protected/eligible counts for matching policy. |
| **Matching degradation** | Requested portable semantic that the active engine cannot represent exactly. |


## Internationalization


| Term                      | Meaning                                              |
| ------------------------- | ---------------------------------------------------- |
| **HasTranslations**       | Trait for translatable attribute storage.            |
| **LocaleContext**         | Request-scoped active locale resolution.             |
| **LocaleScope**           | Global scope filtering translatable queries by locale. |
| **TranslatedModelSaved**  | Event triggering optional auto-translation pipeline. |
| **translations.auto.{table}**| Per-model setting for post-save translation.         |


## Geo and places


| Term                  | Meaning                                                    |
| --------------------- | ---------------------------------------------------------- |
| **Place**             | Canonical address/geo record (lat/lng, formatted address). |
| **IGeocodingService** | Contract for Nominatim or Google Maps geocoding adapters.  |
| **HasPlace**          | Trait linking a model to a `Place` row.                    |


## Platform settings

| **ForcedVersionStrategySettings** | Core resolver for `versioning.strategy.{table}` names made non-configurable by a concrete model's hardcoded DIFF property. |


| Term                         | Meaning                                                   |
| ---------------------------- | --------------------------------------------------------- |
| **Setting**                  | Key/value store (often JSON) for runtime configuration.   |
| **ModuleDatabaseActivator**  | Enables/disables modules through one `core_modules` row per module (`is_active`, frontend `url`); `Core` cannot be disabled. |
| **CronJob**                  | Scheduled task definition managed in Filament: the name of an artisan command (no arguments in it), its `parameters` (a list of strings, in order: a flag `--all`, an option with a value `--limit=10`, or an argument; the value is escaped by the scheduler, a value must not start with a dash, and a value of `@now` or `@today` is replaced by that date, as ISO 8601, each time the job runs), a cron expression, and the flags `on_one_server` and `without_overlapping`, both on by default. The list shows when an active job was last due and is next due, computed from the cron expression (`previousRunAt()`, `nextRunAt()`); nothing records the runs themselves. |


## Related reading

- `docs/ACL_SYSTEM.md` — permission and ACL resolution
- `docs/CRUD_SYSTEM.md` — API endpoints and request flow
- `docs/EVENT_ORCHESTRATION.md` — indexing and moderation orchestration
- `docs/rag/MODULE.md` — RAG-oriented platform overview
