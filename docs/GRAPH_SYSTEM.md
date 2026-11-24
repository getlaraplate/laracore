# Core Graph System

Core Graph is the CRUD-aligned graph layer for Laraplate. It is owned by the Core module and is available for every CRUD-resolvable entity unless normal CRUD resolution, authorization, validation, or optional provider rules reject the request. CMS is the first provider and consumer, but it does not own traversal, authorization, request parsing, or response contracts.

Core Graph, an AI Graph tool, and a RAG knowledge graph are different concepts. Core Graph is the authorized relation API described here. The AI module may expose read-only wrappers around these operations to the authenticated in-app assistant, preserving the request user's permission and row ACL checks. Graphify/GraphRAG-style knowledge graphs would instead be retrieval storage or preprocessing choices; they are not required by Core Graph and are not selected dependencies.

## Public Routes

API routes are mounted under the CRUD namespace because graph operations extend the CRUD model rather than introducing a separate module API surface:

| Operation | API route | Web route |
| --- | --- | --- |
| Expand one record | `GET /api/v1/crud/graph/expand/{module}/{entity}/{id}` | `GET /app/crud/graph/expand/{module}/{entity}/{id}` |
| Search graph seeds | `GET /api/v1/crud/graph/search/{module}/{entity}` | `GET /app/crud/graph/search/{module}/{entity}` |
| Stats for one expansion | `GET /api/v1/crud/graph/stats/{module}/{entity}/{id}` | `GET /app/crud/graph/stats/{module}/{entity}/{id}` |

`expand` is a detail extension: it starts from one authorized center record and can traverse requested relations. `search` is a search extension: it reuses CRUD search through `CrudService::search()` and returns graph-compatible nodes for search results, optionally expanding requested relations from each seed. `stats` derives counts from the same authorized graph expansion that `expand` would return.

## Request Semantics

`ExpandGraphRequest` extends `DetailRequest`. Supported graph parameters are:

- `relations[]`: explicit relation paths to traverse, such as `tags`, `categories.children`, or `locations`.
- `depth`: maximum relation path depth.
- `limit`: maximum total nodes in an expand response.
- `relation_limit`: maximum targets loaded per relation step.
- `node_detail`: `minimal`, `summary`, or `full`.

`SearchGraphRequest` extends `SearchRequest`. It keeps CRUD search semantics, including `qs`, `mode`, pagination, filters, sorts, and `limit` as the search result limit. Graph-specific search parameters are additive: `relations[]`, `depth`, `relation_limit`, and `node_detail`. Search responses use `center: null` because there can be multiple search-result seeds.

Relation paths are never auto-discovered. If `relations[]` is present, only those paths are traversed. If it is absent, Core asks the optional provider for default relations. If no provider defaults exist, expand returns only the center node and search returns only graph-compatible search result nodes.

## Authorization And Filtering

Graph uses the same `AuthorizationService` model as CRUD. If the user cannot view the center record of an expand or stats request, the request fails like CRUD detail. During traversal, unauthorized neighbor nodes are omitted and `graphMeta.filteredByAcl` is set. Cross-module traversal uses the target node's real module/entity identity and that entity's CRUD permission rules.

Invalid relation paths, excluded relations, provider rule violations, and depth violations return validation-style errors. Truncated graph responses remain successful but set `graphMeta.truncated` and `graphMeta.truncatedBy` so callers do not treat partial graphs as complete.

## Providers

Providers are optional refinements. A module can register a `GraphProviderInterface` implementation in the `GraphProviderRegistryInterface` for a whole module or for a specific entity. Providers can supply default relations, summary fields, edge labels, and excluded relations.

A provider can also implement `GraphProviderRulesInterface` to restrict otherwise generic behavior:

- `allowedRelationPaths()` limits accepted explicit relation paths.
- `maxDepth()` narrows maximum traversal depth for a module/entity.
- `maxRelationLimit()` limits the number of targets for a specific source entity relation.

Providers are not required for graph availability. Without a provider, Core uses CRUD entity resolution, generic serialization, explicit `relations[]`, and global graph config limits.

## Response Shape

Graph responses expose:

- `center`: node id for expand/stats, or `null` for search.
- `nodes`: graph nodes identified as `{module}:{entity}:{id}`.
- `edges`: deterministic relation edges.
- `graphMeta`: requested relations, depth, truncation, ACL filtering, cycles, and deduplication metadata.
- `searchMeta`: search-only metadata for graph search.
- `stats`: stats-only counts for graph stats.

Node details are controlled by `node_detail`. Summary serialization uses provider summary fields when available and generic model attributes otherwise (`title`, `name`, `label`, `slug`, `status`, `type`, `code`, `created_at`, `updated_at`); `minimal` carries the `slug` alone. No node carries a hierarchy `path`: Laraplate stays headless and computes none.

## Performance Boundary

Runtime traversal is the only implementation of expand, search, and stats: there is no materialized edge store. One was evaluated on 2026-09-30 and not built. The cost of a graph request tracks its query count, not the size of the graph: an expand costs 16 to 32 queries whether CMS holds 40 or 250 contents, and on a remote PostgreSQL each query adds roughly 12 to 14 ms on top of the request's own baseline. Materialized edges would cut those queries at the price of a table, invalidation hooks and staleness checks; batching the traversal's queries reaches the same saving without them, and is where performance work should start.

Reopen the question only if a real workflow shows expand cost growing with graph size. Any materialized layer must then preserve the public response contract, have an accepted invalidation and freshness strategy for each module/entity/relation it covers, and fall back to runtime traversal whenever freshness cannot be proven. The CMS runtime benchmark (`Modules/CMS/tests/Benchmark/CmsGraphRuntimeBenchmarkTest.php`) produces that evidence.

## AI Tool Boundary

The in-app assistant receives request-local `graph_search`, `graph_expand`, and `graph_stats` tools only under the `InAppAssistance` profile and only when the compiled server policy allows them. These tools call the authorized Core Graph gateway directly; they do not accept user ids, tenant ids, roles, permissions, ACL filters, model classes, table names, or system instructions. They are read-only and cannot create action requests or mutations.

Application content retrieval is a second, independent read-only tool surface. It finds bounded textual evidence owned by a module, while Core Graph expands known authorized relations. An assistant turn may use both, but neither surface bypasses the other's authorization rules and neither is documentation RAG.

## Tests

Graph coverage lives under:

- `Modules/Core/tests/Feature/Graph/`
- `Modules/CMS/tests/Feature/Graph/`

Run graph-focused checks with:

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/Graph Modules/CMS/tests/Feature/Graph
```

Run affected CRUD request checks with:

```bash
rtk php artisan test --compact Modules/Core/tests/Integration/Http/Requests/AuthAndSearchRequestsTest.php Modules/Core/tests/Feature/Api/CrudApiTest.php
```
