# CRUD System

## Overview

The CRUD system provides a **dynamic, entity-agnostic** API for Create, Read, Update, and Delete operations on any Eloquent model. It supports advanced features like filtering, sorting, pagination, relations, computed columns, and integrates with the ACL system for row-level security.

## Key Features

- **Dynamic Entity Resolution**: Works with any Eloquent model via `DynamicEntity`
- **Advanced Filtering**: Nested filters with AND/OR logic
- **Relation Support**: Eager loading with column selection
- **Pagination**: Multiple pagination strategies (page-based, from-to, limit)
- **Computed Columns**: Support for appends and method calls
- **ACL Integration**: Automatic row-level security filtering, on related records too
- **API Access Control**: One guard, one switch and one optional token authentication for every `/api` route
- **Caching**: Built-in response caching
- **Versioning**: History tracking for models with `Versionable` trait
- **Approval Workflow**: Support for modification approval

## Architecture

### Service Architecture

```mermaid
flowchart TD
    controller["CrudController<br/>routes HTTP requests to CrudService<br/>list(): ListRequest → ListRequestData<br/>detail(): DetailRequest → DetailRequestData<br/>insert(): ModifyRequest → ModifyRequestData<br/>update(): ModifyRequest → ModifyRequestData<br/>delete(): ModifyRequest → ModifyRequestData<br/>history(): HistoryRequest → HistoryRequestData<br/>tree(): TreeRequest → TreeRequestData"]
    service["CrudService<br/>orchestrates CRUD operations<br/>uses AuthorizationService for permissions and ACL<br/>uses QueryBuilder for query preparation<br/>returns CrudResult with data and metadata"]
    auth["AuthorizationService<br/>ensurePermission()<br/>injectAclFilters()<br/>applyAclFiltersToQuery()"]
    qb["QueryBuilder<br/>prepareQuery()<br/>applyFilters()"]
    controller --> service
    service --> auth
    service --> qb
```

### Request Flow

```mermaid
flowchart TD
    http(["HTTP request"])
    form["1. FormRequest (ListRequest, DetailRequest, ...)<br/>validates input<br/>calls parsed() to create RequestData"]
    data["2. RequestData (ListRequestData, DetailRequestData, ...)<br/>resolves the entity via DynamicEntity<br/>normalizes columns, filters, sorts, relations"]
    service["3. CrudService<br/>checks permission (AuthorizationService)<br/>injects ACL filters into RequestData<br/>builds the query (QueryBuilder)<br/>executes the query<br/>returns CrudResult"]
    response["4. ResponseBuilder<br/>formats the response (JSON/XML)<br/>applies caching<br/>returns the HTTP response"]
    http --> form --> data --> service --> response
```

## API Endpoints

### Read Operations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/select/{module}/{entity}` | GET, POST | List records with filtering/pagination |
| `/api/v1/detail/{module}/{entity}` | GET | Get single record by primary key |
| `/api/v1/history/{module}/{entity}` | GET | Get record with version history (needs `update` or `approve` on the entity) |
| `/api/v1/tree/{module}/{entity}` | GET | Get hierarchical record (ancestors/descendants) |
| `/api/v1/aggregate/{module}/{entity}` | GET, POST | Counts, sums, averages, minima and maxima, by group or time bucket (see [Aggregate Route](#aggregate-route)) |

### Write Operations

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/insert/{module}/{entity}` | POST | Create new record |
| `/api/v1/update/{module}/{entity}` | PATCH | Update existing record(s) |
| `/api/v1/delete/{module}/{entity}` | DELETE | Hard delete record(s) |

#### Relation sync on update

`update` writes only fillable columns. To reassign many-to-many relations in the same
call, send a `relations` map: `{ "id": 5, "relations": { "tags": [1,2], "categories": [] } }`.
A model opts in by implementing `Contracts\ProvidesSyncableRelations::syncableRelations()`,
which whitelists the relations a client may `sync` — that whitelist is the authorization
boundary. `CrudService::update` rejects any relation not whitelisted or not a
`BelongsToMany`/`MorphToMany` with `422`/`400`, then syncs each present relation in the
update transaction (a present key syncs wholesale, empty clears, an omitted key is untouched).

The sync works only within the related records the writer can read (their `select` permission
and ACL on the request's guard): related records the writer cannot see stay attached with their
pivot rows untouched, additions and removals are computed on the visible subset, and submitting
an id the writer cannot read (hidden or nonexistent) answers `403` and rolls the save back. A
relation whose related entity the writer may not select at all cannot be synced. Parts of the
parent (`IsPartOfParent`) sync as before. See [Related records](#related-records).

#### Role-scoped ACLs and dynamic placeholders

An `acls.role_id` (nullable) scopes an ACL to a single role: set, it applies only to that
role (paired with `permission_id`, i.e. the `role_has_permissions` pair); null keeps the
legacy behavior (applies to every role holding the permission). This lets two roles that
share a permission carry different row-level filters — e.g. the `guest` role of the `api`
guard (the anonymous reader) is restricted while staff roles are not.

ACL filter values may use dynamic placeholders resolved at query-build time: `@now` and
`@today` become the live `Carbon::now()` / `Carbon::today()`. This is what lets a stored
ACL express a moving publication window.

An ACL can also name a model scope instead of the columns (`{"scope": "valid"}`, see
[ACL_SYSTEM.md](./ACL_SYSTEM.md#scope-filters)); the CMS categories ACL of the anonymous reader does. Scope parameters
accept the same placeholders.

Together these replace what used to be a per-model validity global scope: `CMS\Content`
publication filtering is an ACL of the `api` `guest` on `cms_contents.select` whose `valid_from <=
@now AND (valid_to >= @now OR valid_to IS NULL)` filter limits the public reader to
published content, while staff (no such ACL) read everything. The same window reaches the
references and media of those contents through relation filters (see
[ACL_SYSTEM.md](./ACL_SYSTEM.md#relation-filters)).

Role inheritance never leaks an unwanted restriction: a role with its own ACL for a
permission uses it and ignores any ancestor ACL. So a child role that would otherwise
inherit a restrictive parent ACL can carry its own higher-priority `unrestricted = true`
ACL — which contributes no filters — to override the inherited one and read everything.

Effective ACLs are cached per user/permission (`AclResolverService`, one-hour TTL).
`AclObserver` flushes that cache on every ACL create/update/delete/restore, so an ACL
change (including flipping `unrestricted`) takes effect immediately instead of after the
TTL expires.

### Internal-only Operations

These are registered in `routes/web.php`, not in the shared `routes/crud.php`. They are
therefore reachable on the session-based `/app` surface only and are never exposed on
`/api/v1`, regardless of `core.expose_api`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/app/crud/activate/{module}/{entity}` | PATCH | Restore soft-deleted record |
| `/app/crud/inactivate/{module}/{entity}` | PATCH | Soft delete record |
| `/app/crud/approve/{module}/{entity}` | PATCH | Approve pending modifications of the record `id` |
| `/app/crud/disapprove/{module}/{entity}` | PATCH | Reject pending modifications of the record `id` |
| `/app/crud/withdraw/{module}/{entity}` | PATCH | Withdraw your own pending modification (`modification` = its id) |
| `/app/crud/pending-approvals` | GET | Every pending modification you can vote on, on any entity, plus your own: each row carries `entity`, `operation`, `is_mine` and `can_vote` |
| `/app/crud/lock/{module}/{entity}` | PATCH | Lock record for editing |
| `/app/crud/unlock/{module}/{entity}` | PATCH | Unlock record |

Operation pairs share a single permission: `approve` governs `disapprove`, and `lock`
governs `unlock`. Requesting an operation whose target state already holds — locking a
locked record, unlocking an unlocked one — returns `304 Not Modified`.

`activate`/`inactivate` are the exception: they do **not** share a permission. `activate`
restores a soft-deleted record and requires the `restore` permission; `inactivate`
soft-deletes a live record and requires the `delete` permission (hard delete via
`/api/v1/delete` and `/app/crud/delete` requires `forceDelete`).

### Domain Actions

The verbs above are generic: they act on structures Core attaches to any record. Modules
also need verbs that act on the record itself — posting an invoice, closing a fiscal
period, reversing a journal entry. One route serves all of them:

```
POST /app/crud/{action}/{module}/{entity}      id and action payload in the body
```

`POST` rather than `PATCH` because a domain action invokes an operation rather than
patching a representation, and several are not idempotent.

The route is declared **last** in Core's `crud` group. Laravel matches in registration
order with no notion of specificity, so every literal verb above must be tried first; the
graph group carries an extra path segment and never reaches it. Use
`php artisan route:check <url> --method=POST` to see which route actually answers a URL —
`route:list` sorts by URI and hides the ordering that decides the match.

#### The registry decides what exists

A module registers its actions at boot; the route table knows nothing about them:

```php
$registry->register(Invoice::class, 'post', function (Model $record, array $payload, User $user): Model {
    $record->update(['posted_at' => now()]);

    return $record->fresh();
});
```

Handlers stay thin. Business rules, locking and state guards belong to the services and
policies the module already has — a handler that added a rule of its own would create a
second truth. An action nobody registered answers `404`, not `403`: replying `403` would
claim it exists.

#### Authorization goes through the policy

Generic CRUD authorizes on a permission name alone. Domain actions authorize through the
`Gate`, because their guard is intrinsic: posting an already-posted invoice is not a
permission problem. The module policy combines the state predicate with the permission,
and `force_post` in snake_case resolves to the `forcePost` policy method.

A model must therefore have a registered policy for its domain actions to be reachable.
Without one the Gate has nothing to consult and denies.

#### Overriding a generic verb

Where a module needs a generic verb to mean something else for one entity, the model
declares it:

```php
final class ReturnOrder extends Model implements OverridesGenericCrudActions
{
    public static function overriddenCrudActions(): array
    {
        return ['approve'];
    }
}
```

Core's `approve` votes on a pending `Modification`; `ReturnOrder`'s advances the document
from Draft to Approved. Only one meaning can win per entity, so `DomainActionRegistry`
refuses to register a generic verb unless the model declares the override, and refuses it
outright if the model also uses the trait giving that verb its generic meaning
(`HasApprovals` for `approve`/`disapprove`, `HasLocks` for `lock`/`unlock`, `SoftDeletes`
for `activate`/`inactivate`). The check runs at registration, which happens at boot, so a
contradiction stops the application on start rather than surfacing when one record is
first touched.

#### Restricting generic writes

Some models must never be written through the generic verbs: posted accounting vouchers,
stock movements and other rows derived by a service that enforces invariants CRUD cannot.
Such a model implements `Modules\Core\Contracts\RestrictsCrudWrites` and lists the
operations it denies in `deniedCrudWrites()`, a subset of `insert`, `update`, `delete`,
`forceDelete`, `restore`, `approve`, `disapprove`, `lock` and `unlock`. The
`DeniesGenericCrudWrites` trait denies all of them.

```php
final class StockMovement extends Model implements RestrictsCrudWrites
{
    use DeniesGenericCrudWrites;
}
```

`CrudService` checks the contract before it touches the record, ahead of the permission
check, and throws `CrudWriteNotAllowedException`, which `CrudController` answers with `403`.
A model that does not implement the contract is unaffected, and services and domain actions
that write the model directly are not subject to it.

#### Responses

A handler returning a `Symfony\Component\HttpFoundation\Response` is passed through
untouched — that is how file exports stream and how multipart uploads are consumed.
Anything else is wrapped in a `CrudResult`, so a domain action looks like every other CRUD
response. Authorization and the state guard run before the first byte, so a refusal is
still a JSON error rather than a corrupt download.

`ValidationException` maps to `422` and `DomainException` to `409`.

## API Access Control

Every route under `/api` (Core CRUD and graph, and the `/api` routes of ERP, SAO and MES) shares one
authentication, one switch and one guard. The full guide, with the operator checklist, is
[rag/API_ACCESS_CONTROL.md](./rag/API_ACCESS_CONTROL.md); this section is the CRUD-facing summary.

### Guards and per-guard permissions

`php artisan permission:refresh` creates every permission for both guards, `web` and `api`, with the same
name. A role holds the permissions of its own guard, and roles are identified by `(name, guard)`.
`AuthorizationService` checks the guard of the current request on every path (direct permission, role,
ancestor role): `/app` routes run on `web`, `/api` routes on `api`. A permission that does not exist on
the guard is a denial, never an error.

### `AuthenticateApiRequest`

The middleware (alias `api_access`) runs in the `api` group of Core and of every module, right after
`ApplyDatabaseSettingsOverlay` (which supplies the switch it reads) and before route model binding, in this
order:

1. `core.expose_api` (the `expose_api` setting, default off) false: `403`.
2. `Auth::shouldUse('api')`.
3. The caller. **No bearer:** the request runs as the anonymous user (`permission.users.guest`), which holds
   the `guest` role of both guards. **A bearer token** (optional on every route): resolved by the Sanctum
   guard, from the token alone and never from a web session. Unknown, malformed, expired or revoked: `401`,
   never the anonymous user. A superadmin's token: `401`. An address outside the token's `allowed_cidrs`:
   `403`, logged without the token.
4. Rate limit: `core.api.rate_limit_per_minute` (default `600`) per token, or per client address without one.

Failed bearer authentications are counted per client address before the token is looked up; an address
over the limit gets `429`, and successful requests do not consume that budget.

**Self-authenticated routes.** A route whose callers send a secret of their own as the bearer (the ERP
payment and e-invoice provider callbacks) declares
`->middleware(SelfAuthenticatedApiRoute::class)`. The switch, the `api` guard and the rate limit still
apply; the bearer is not read as a Laraplate token, the request runs as the anonymous user, and the route's
own check authenticates it.

**403 versus 401.** A denied permission, a missing token ability or a forbidden relation answers `401` for
the anonymous user (a login or a token may change the answer) and `403` for any authenticated principal, a
session user or a token user.

### Errors

A server error (`5xx`) answers the status text (`Internal Server Error`) instead of the exception message
when `app.debug` is off, for every controller built on `ResponseBuilder` (CRUD, graph, media, settings,
users): the message of a failed query carries its SQL and bindings. The exception is still reported. Client
errors (`4xx`) keep their message. The aggregate route has its own envelope and its own generic `500` message (see
[Aggregate errors](#aggregate-errors)).

### Tokens

Users create personal tokens (`/app/auth/user/tokens`, always expiring within
`core.api_tokens.max_lifetime_days`, default 365); administrators create service accounts and their tokens
in the Filament user resource. A token's abilities are `api` permission names (`*` is refused), and a
request needs the role permission and the ability, evaluated per request. The model events and scopes that
ask about the acting user (the per-row select check, the write checks, the approval credit of a writer, the
hiding of records pending deletion) ask through `AuthorizationService::userHolds()`, on the request's guard and
within the token's abilities, never through the Gate. A cached response is keyed by the token as well as by the
user and roles, so two tokens of one user never share one. Superadmins cannot hold tokens.
Details in [rag/API_ACCESS_CONTROL.md](./rag/API_ACCESS_CONTROL.md#tokens).

### Related records

Select, detail, search, tree, history, facets and aggregate share one visibility rule for related records. A relation
to an independent entity is loaded only if the caller holds that entity's `select` permission on the
request's guard, and its query receives that entity's ACL, as a root query does. This holds for `relations`,
dotted `columns`, relation filters, sorts, group-by keys and aggregates, and for search, which reloads the
hits through the same relation handling.

- A relation the request **explicitly asked for** and the caller may not read answers `403` (`401` for the
  anonymous user). A relation the model loads on its own (default eager loads, a computed column's
  dependency, an appended attribute such as a content's `cover`) is left out, or the attribute is `null`.
- A model that is a **part of its parent** implements `Contracts\IsPartOfParent::parentRelation()`
  (translations, document lines, BOM lines, approvals, CMS references and ratings). Loaded from its own parent
  it inherits the parent's visibility and is not checked, even when a permission exists for its table: an
  installation's own ACL on a part applies only when the part is read directly (its own endpoint, or reached
  from another model, where it is checked against its parent's permission and ACL by `RelationAuthorizer`).
  The per-record check run when a model is hydrated (`HasValidations`) agrees: for `select` a part passes when
  the caller holds, on the request's guard and within the token's abilities, the `select` of any model along
  its chain: the part's own, that of every intermediate part, or that of the first owner up the chain that is
  not a part (`Authorization\PartOwnerResolver`, which memoizes the chain per class). The check stops at the
  first one held. A token carrying only the content ability reads the content's references and ratings, a role
  without the references permission still reads them through the content, a role holding only a Shop variant's
  `select` reads the variant's items (parts of the variant, itself a part of its product), and a caller holding
  only the part's own permissions passes the check on the part's own endpoint (detail, update, delete). A
  caller holding none is refused, and the message names every table checked. A model of the chain without a
  registered permission adds nothing; when the chain does not resolve (a morph or undeclared parent relation, a
  cycle, more than eight parts), only the part's own permission is checked. Translations are parts too: outside the permission scheme themselves,
  they are hydrated by whoever may read the translated model. Writes to a part keep the part's own permission.
  A read the application takes on its own behalf as a side effect of a write, such as CMS re-indexing a content
  after one of its references changed, runs inside `Authorization\RetrievedSelectGuard::forSystemRead()`: the
  per-row select check is lifted for every class within the callback, so the caller's own permissions on the part
  suffice end to end; writes inside it stay guarded, and search results are filtered again when served.
- An entity with no registered `select` permission and outside `PermissionsRefreshCommand`'s deliberate
  blacklist fails closed. Of the blacklisted classes, only a pivot record reached through a plain relation
  (the `presettable` link a model loads for its dynamic fields) and a translation loaded from the model it
  translates are read as relations. Versions, licenses and dynamic entities are read as a relation by a
  superadmin only; model embeddings and Sanctum tokens never, whoever asks; modifications follow the
  pending-changes rule below.
- Every relation name from a request, from a dotted column, filter or sort, or from an ACL goes through
  `RelationGuard`: it must be a public, non-static method with no required parameter and a declared
  `Relation` return type, otherwise the request answers `400` before anything is called. A relation method
  without a return type is therefore not usable by clients until it is typed.
- The relation black list (`history`, the version relations, `tokens`, `embeddings`, the tree-walk relations)
  is dropped from every path a read loads, in select, detail and search.
- A relation a query loads on its own whose name does not resolve to a relation (an untyped relation method, a
  path through a morph relation) cannot be checked and is not loaded.
- **Pending modifications.** The `modifications` relation holds proposed, unapproved values. It is returned
  only to a caller holding the parent entity's `update` or `approve` permission on the request's guard,
  token abilities included: others get it omitted when appended or default, `403`/`401` when requested.
- **History.** `GET /api/v1/history/{module}/{entity}` needs the entity's `update` or `approve` permission,
  because versions hold pre-publication drafts. The `record` is serialized through the model (hidden
  attributes and media projection apply) and history rows carry no nested copy of it.
- **Partial relations.** On `detail`, a relation the related ACL narrowed is declared in
  `meta.relations.{name}.hidden`, the number of related records left out, so a client can warn before
  editing. `list` and `search` do not carry it.
- **Search.** ACL column conditions go to the engine; relation filters and scope filters cannot, and take
  effect when the hits are reloaded. A page can hold fewer records than requested, and a search's
  `totalRecords` can count hits the caller may not see.

### Computed `method` columns

A `method` column calls a model method on every returned row, so a request may only name a method the
model declares in its `crudComputedDependencies()` map (and it must be public, written in the application,
not a relation and not a write). Anything else answers `400`. No production model declares a computed
method today, so every client `method` column is refused; declare the method there first when a client needs
it (see [Computed Columns](#computed-columns)). An appended column needs an attribute accessor, and a
group-by key may not name a method.

### Columns returned

- **Hidden attributes.** A model that implements `Contracts\IHidesAttributesFromAnonymous` lists attributes
  removed from every serialization for the anonymous user, as an entity or as a relation: CMS contributors
  (`user_id`, `user`, `shared_components`), comments and ratings (`user_id`). Staff and token users see them.
- **Media projection.** On the `api` guard a `Media` is returned as `id`, `collection_name`, `mime_type`,
  `size`, `width`, `height`, `alt_text`, `url` and a `conversions` map, with no `custom_properties`, `disk`,
  `conversions_disk` or `file_name`, unless the caller holds `vend_media.update` on the `api` guard. URLs
  are plain for public disks, temporary (valid `core.media.signed_url_ttl_minutes`, default 60) on disks
  that can sign, and `null` otherwise. `/app` is unchanged.
- **Readable columns.** A column the serialization withholds from the caller (hidden from the anonymous user,
  a media column outside the projection) cannot be read through the query either. A facet field (flat or
  open, its `fields` and `labelField` included), a group-by key, a sort, a filter property or an aggregated
  column naming one answers `400` before anything is queried, on the main model and, for a dotted path, on
  each related model along it (`Support\ReadableColumnGuard`). The aggregate route applies the same guard to every
  field of its request (measure, group key, label, period field, filter property) and answers `422`
  `UNREADABLE_FIELD` with the path of the field instead of `400`. A projected media caller may name only
  `id`, `collection_name`, `mime_type` and `size`. The ACL filters are exempt: they name columns on their own
  authority.
- **Graph nodes.** The label and summary fields of a graph node are read only from attributes the model's
  serialization returns to the caller: not in `$hidden`, not hidden from the anonymous user, within the media
  projection.
- **Version contents.** A version's `contents` and `original_contents` mask (`[hidden]`) every attribute in
  the versioned model's `$hidden` (passwords, remember tokens, two-factor secrets), the attributes it hides from
  the anonymous user when the anonymous user reads them, and any password hash (bcrypt or argon2). Exception:
  the storage columns of a model using `HasDynamicContents` (`components`, `shared_components`, `entity_id`,
  `presettable_id`) are in `$hidden` only because the model's serialization flattens them into fields; they
  hold the record's editorial values and stay visible in its history (an anonymous-hidden one, such as a
  contributor's `shared_components`, is still masked for the anonymous user).
- **Cover preload.** Models that implement `Contracts\IPreloadsAppendedRelations` have the relations their
  appended attributes read added to the query once per result set, narrowed by the related ACL, instead of
  one query per row.

## Request Parameters

### List Request

```json
{
  "connection": "default",
  "columns": ["id", "name", "created_at"],
  "relations": ["author", "categories"],
  "filters": {
    "operator": "and",
    "filters": [
      { "property": "status", "operator": "=", "value": "published" },
      { "property": "created_at", "operator": ">=", "value": "2024-01-01" }
    ]
  },
  "sort": [
    { "property": "created_at", "direction": "desc" }
  ],
  "pagination": 25,
  "page": 1
}
```

### Detail Request

```json
{
  "connection": "default",
  "id": 123,
  "columns": ["id", "name", "content", "author.name"],
  "relations": ["author", "categories", "comments"]
}
```

### Modify Request (Insert/Update)

```json
{
  "connection": "default",
  "id": 123,
  "changes": {
    "name": "Updated Title",
    "status": "published"
  }
}
```

## Column Types

Columns can be of different types:

| Type | Description | Example |
|------|-------------|---------|
| `column` | Standard database column | `name`, `created_at` |
| `append` | Eloquent accessor (appended attribute); the model must have the accessor | `full_name` |
| `method` | Model method call; only a method the model declares in `crudComputedDependencies()` | `getFormattedDate()` |
| `count` | Aggregate count | `comments:count` |
| `sum` | Aggregate sum | `items:sum:quantity` |
| `avg` | Aggregate average | `reviews:avg:rating` |
| `min` | Aggregate minimum | `prices:min:amount` |
| `max` | Aggregate maximum | `prices:max:amount` |

### Column Syntax

```json
{
  "columns": [
    "id",
    "name",
    { "name": "full_name", "type": "append" },
    { "name": "author.name", "type": "column" },
    { "name": "comments", "type": "count" }
  ]
}
```

A "dotless" aggregate names a relation on the main model itself: `{ "name": "comments", "type": "count" }` runs `withCount('comments')` and adds a `comments_count` attribute to each row (`<relation>_count`; `sum`/`avg`/… follow Eloquent's `<relation>_<agg>_<column>` naming). It applies whether or not other relations are eager-loaded. A dotted aggregate (`author.comments:count`) instead counts a sub-relation inside the loaded `author` relation. Columns are namespaced to the model's real table, so this works for entities whose route alias differs from their table (e.g. the `locations` alias over `cms_locations`). An aggregate whose name is not a real relation fails fast with a clear error. Each relation-count subquery is constrained by the related entity's read ACL, so a `*_count` never counts rows the viewer is not permitted to see.

## Filters

### Filter Operators

| Operator | Description | Example Value |
|----------|-------------|---------------|
| `=` | Equals | `"active"` |
| `!=` | Not equals | `"deleted"` |
| `>` | Greater than | `100` |
| `>=` | Greater than or equal | `100` |
| `<` | Less than | `100` |
| `<=` | Less than or equal | `100` |
| `like` | LIKE pattern | `"%john%"` |
| `not like` | NOT LIKE pattern | `"%test%"` |
| `in` | IN list | `["active", "pending"]` |
| `between` | BETWEEN range | `["2024-01-01", "2024-12-31"]` |

### Nested Filters

Filters can be nested with AND/OR logic:

```json
{
  "operator": "and",
  "filters": [
    { "property": "status", "operator": "=", "value": "active" },
    {
      "operator": "or",
      "filters": [
        { "property": "priority", "operator": "=", "value": "high" },
        { "property": "urgent", "operator": "=", "value": true }
      ]
    }
  ]
}
```

Result: `status = 'active' AND (priority = 'high' OR urgent = true)`

### Relation Filters

Filter on related models using dot notation:

```json
{
  "filters": [
    { "property": "author.country", "operator": "=", "value": "IT" },
    { "property": "categories.slug", "operator": "in", "value": ["news", "blog"] }
  ]
}
```

### Scope Filters in Requests

A filter can also name a **model scope**, `{ "scope": "valid" }` or `{ "scope": "expiring", "parameters": [48] }`, anywhere a filter or a group may appear (`select`, `search`, `facets` and `aggregate` requests share the `filters` vocabulary, and so does a tool call of the in-app assistant):

```json
{
  "operator": "and",
  "filters": [
    { "scope": "valid" },
    { "property": "status", "operator": "=", "value": "active" }
  ]
}
```

- Only scopes the model exposes to clients work: the method must carry `#[FilterScope(client: true)]`. The declared ones are the validity scopes of `HasValidity` (`valid`, `published`, `draft`, `scheduled`, `expired`, `validAt(date)`, `expiredAt(date)`, `expiring(hours)`) on every model using it. `active` and `inactive` (`HasActivation`) are ACL-only, because a scope may read columns a client cannot filter on. How a module declares its own, the parameter rules (scalars and ISO 8601 dates, strict types) and the purity rule are in [ACL_SYSTEM.md](./ACL_SYSTEM.md#scope-filters).
- A request gets **no placeholders**: `@now`, `@today` and `@user.*` are taken literally.
- The scope narrows the caller's ACL, never widens it: the request filters are ANDed with the ACL filters.
- **`422`** when the scope is not declared, is ACL-only, takes a wrong, missing or extra parameter, does more than add where conditions, adds no condition with the given parameters, or reads through a subquery or raw SQL (a relation it would reach escapes the related ACL). The message names the filters path, the scope and the reason (for example `Scope "active" of this entity can be used only in ACLs.`), with the model shown as "this entity" and never a parameter value. `ListRequest` validates the tree (`Rules\ClientScopeFilters`); `FacetsRequest`, `SearchRequest` and `AggregateRequest` inherit it (the aggregate route answers it in its own envelope, `INVALID_FIELD` on `filters`).
- **The parser is the gate.** `ListRequestData::conformScopeNode()` asserts the client rule for every scope node it builds, because every tree it parses is client input and not every caller validates first. The in-app assistant's CRUD tool builds `ListRequestData` from the model's tool call without a `ListRequest`, so an ACL-only scope is refused there by the same parser.
- In search the scope is left out of the engine filters and enforced when the hits are reloaded (see [ACL_SYSTEM.md](./ACL_SYSTEM.md#scope-filters-in-search)), so a page can hold fewer records than requested and `totalRecords` can count hits the scope removed.

### Filter groups are always nested

`QueryBuilder::applyFilters()` applies the group it is given inside one nested `where`, so an OR group (a request filter, an ACL, a stored filter) can never escape the conditions already on the query. The facets endpoint (counts, values, relation facets) once applied a request's root OR group next to the ACL without nesting (`where acl and a or b`), which counted hidden rows and returned their keys; it now goes through `applyFilters()`. The list query (`QueryBuilder::prepareQuery()`) and the filters of an eager-loaded relation are nested the same way: the list group joins whatever the query already holds as one condition, and a relation's filter group joins the relation's own constraint (the parent keys) and the related ACL as one condition, where a root OR group once escaped them (`model_id = ? and name = ? or name = ?`). The CMS and SAO application content retrieval providers, the media owner lookup and the hit reload of search apply their filters through it too.

### Translated and Dynamic Fields

A client names a property without knowing how the entity stores it. A flat property (letters, digits and underscores, no dot) is resolved on the model being filtered in this order:

1. **A column** of the model's table: filtered as is.
2. **A translatable field** (a fillable column of the translation table, such as `title` or `slug` on contents): the filter applies to the translation in the **locale of the request** (the user's language). A record without a translation in that locale is not matched; there is no search across languages and no fallback to the default locale, even for a model whose reads fall back to it.
3. **A dynamic field**, on a model using `HasDynamicContents`: any other name is looked for in the JSON containers that can hold dynamic fields. Whether a field was configured translatable or shared is not known per record, so every container that exists is searched with an OR: `shared_components` of the main row (or `components` on a model without translations) and `components` of the request-locale translation. Presets are not consulted, because one recordset mixes entities with different presets and fields: a record that does not have the field simply does not match.

```json
{ "property": "title", "operator": "like", "value": "%moon%" }
{ "property": "color", "operator": "=", "value": "red" }
```

4. **An attribute mounted from a related record**, on a model implementing `IMountsRelatedAttributes` (a shop `Product` presents the title of its content as its own): a property the model does not own becomes the same filter on the relation the model mounts, which resolves it there. `title` on `products` is `content.title`.

The same rule applies to the leaf of a relation path (`categories.name` resolves `name` on the category). Limits: dynamic fields are compared as strings with the operators above (`=`, `!=`, `like`, `not like`, `in`, null checks); no numeric or date comparison, sorting, grouping or facets on them, and a misspelled dynamic name returns no rows instead of an error. Full text relevance search is a different endpoint (`/search/{module}/{entity}`, `Searchable` models only). The resolution lives in `Services/Crud/ModelPropertyResolver` and `QueryBuilder::applyFilter`.

## Pagination

### Page-Based Pagination

```json
{
  "pagination": 25,
  "page": 2
}
```

Response includes:
- `meta.totalRecords` - Total count
- `meta.currentRecords` - Records in current page
- `meta.currentPage` - Current page number
- `meta.totalPages` - Total pages
- `meta.pagination` - Items per page

### From-To Pagination

```json
{
  "from": 50,
  "to": 100
}
```

### Limit-Based

```json
{
  "limit": 10
}
```

## Relations

### Simple Relation

```json
{
  "relations": ["author", "categories"]
}
```

### Nested Relations

```json
{
  "relations": ["author.profile", "categories.parent"]
}
```

### Relation with Column Selection

Specify columns for relations using dot notation:

```json
{
  "columns": [
    "id",
    "title",
    "author.id",
    "author.name",
    "categories.id",
    "categories.name"
  ],
  "relations": ["author", "categories"]
}
```

Every relation named here, in a dotted column, in a filter or in a sort obeys the related entity's `select`
permission and ACL; see [Related records](#related-records).

## Response Format

### Success Response

```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Article 1", "author": { "id": 5, "name": "John" } },
    { "id": 2, "name": "Article 2", "author": { "id": 3, "name": "Jane" } }
  ],
  "meta": {
    "totalRecords": 150,
    "currentRecords": 25,
    "currentPage": 1,
    "totalPages": 6,
    "pagination": 25,
    "from": 1,
    "to": 25,
    "class": "App\\Models\\Article",
    "table": "articles",
    "cachedAt": "2024-01-15T10:30:00Z"
  }
}
```

### Error Response

```json
{
  "success": false,
  "error": "User not allowed to access this resource",
  "statusCode": 403
}
```

## Dynamic Entity Resolution

The CRUD system uses `DynamicEntity` to resolve model classes from entity names:

```php
// Entity name → Model class
"users"     → App\Models\User
"articles"  → Modules\CMS\Models\Article
"orders"    → Modules\Shop\Models\Order
```

Resolution is based on:
1. Registered entity mappings
2. Table name matching
3. Class name convention

## ACL Integration

For read operations, ACL filters are automatically injected:

```
User Request: { filters: [status = 'active'] }
ACL Filters:  { filters: [department_id = 5] }

Combined:     { filters: [department_id = 5] AND [status = 'active'] }
```

This ensures users can only see records they're authorized to access, regardless of what filters they specify.

See [ACL_SYSTEM.md](./ACL_SYSTEM.md) for details.

## Computed Columns

Models can define dependencies for computed columns:

```php
class Article extends Model
{
    protected $appends = ['full_title'];
    
    public function getFullTitleAttribute(): string
    {
        return $this->title . ' by ' . $this->author->name;
    }
    
    public function crudComputedDependencies(): array
    {
        return [
            'full_title' => [
                'columns' => ['title'],
                'relations' => ['author'],
            ],
        ];
    }
}
```

This allows QueryBuilder to optimize column selection and eager loading.

The same map is the allow list for `method` columns: a request may call a model method as a column only
when the method is a key of this map (see [Computed `method` columns](#computed-method-columns)). Relations a
computed column depends on are loaded under the related permission and ACL and left out when the caller may
not read them.

## Model Features

### Versionable (History)

Models using `Versionable` trait support history tracking:

```php
class Article extends Model
{
    use Versionable;
    
    protected $versionable = ['title', 'content', 'status'];
}
```

Access via `/api/v1/history/{module}/{entity}`.

### HasRecursiveRelationships (Tree)

Models using `HasRecursiveRelationships` support hierarchical queries:

```php
class Category extends Model
{
    use HasRecursiveRelationships;
}
```

Access via `/api/v1/tree/{module}/{entity}` with `parents` and/or `children` parameters.

### HasLocks (Locking)

Models using `HasLocks` trait support record locking:

```php
class Article extends Model
{
    use HasLocks;
}
```

Lock/unlock via `PATCH /app/crud/lock/{module}/{entity}` and `PATCH /app/crud/unlock/{module}/{entity}`.
Both are governed by the `{connection}.{table}.lock` permission.

### HasApprovals (Approval Workflow)

Models using Core's `HasApprovals` trait send the writes their author may not apply alone
(creates, updates, deletes, force deletes, restores) to approval instead of applying them:

```php
class Article extends Model
{
    use HasApprovals;

    protected function requiresApprovalWhen(array $modifications): bool
    {
        return isset($modifications['status']);
    }
}
```

What the API answers:

| Situation | Status | Body `data` |
|-----------|--------|-------------|
| Insert, update, delete, activate or inactivate captured for approval | `202 Accepted` | `{modification, operation}` for one record; `{modifications: [...], applied}` when a multi-record write captured some of them |
| Save of a record whose deletion waits for approval (`Block` models) | `409 Conflict` | error message |
| Withdraw by the request's author | `200` | `{withdrawn: id}` |
| Withdraw by anybody else | `403` | error message (an `AuthorizationException` answers 403 to an authenticated user and 401 to the anonymous user) |
| Withdraw of a request already decided | `409 Conflict` | error message |

Pending requests are listed by `GET /app/crud/pending-approvals` (every entity) and
`GET /app/crud/pending-approvals/{module}/{entity}` (one entity, `approve` required). Who sees what:

- a superadmin sees every pending request;
- a user holding `approve` or `disapprove` on a table sees the requests on that table, narrowed by
  the table's ACL on `approve` to the records they may decide on (a pending create, which has no
  record yet, is always listed);
- everybody sees their own requests (`is_mine`), never offered a vote on them (`can_vote` false);
- on top of all this, the ACL of `core_modifications` on `select` applies to the roles holding
  that permission, for restrictions such as `modifier_id = @user.id` or a department filter.

Approve, reject and withdraw via `PATCH /app/crud/approve|disapprove|withdraw/{module}/{entity}`.
A pending create has no record yet: vote on it with `modification` and no `id` (the vote then
looks only at the entity's pending creates). Every model with approvals validates a write before
capturing it, so an invalid insert or update answers 422 and never becomes a pending request.
A vote acts on one record (`id`): without `modification` it votes on every active request of
that record, oldest first; with `modification` (one id or a list of ids) only on those, which
must all belong to the record (`404` otherwise) and still be pending (`409` otherwise). An
optional `reason` (max 255 characters) is stored on each vote.
Withdraw needs `select` on the entity and the `modification` id; only the request's author may
withdraw it. See the approvals section of `docs/rag/MODULE.md` for who is captured and what a
pending deletion does to the record.

## File Structure

### Controllers

| File | Purpose |
|------|---------|
| `Http/Controllers/CrudController.php` | Main CRUD controller |

### Services

| File | Purpose |
|------|---------|
| `Services/Crud/CrudService.php` | CRUD business logic orchestrator |
| `Services/Crud/QueryBuilder.php` | Eloquent query preparation |
| `Services/Authorization/AuthorizationService.php` | Permissions + ACL |

### Request Data

| File | Purpose |
|------|---------|
| `Casts/CrudRequestData.php` | Base request data class |
| `Casts/SelectRequestData.php` | Read operations base |
| `Casts/ListRequestData.php` | List with filters/pagination |
| `Casts/DetailRequestData.php` | Single record detail |
| `Casts/HistoryRequestData.php` | Record with history |
| `Casts/TreeRequestData.php` | Hierarchical record |
| `Casts/ModifyRequestData.php` | Insert/Update/Delete |

### DTOs

| File | Purpose |
|------|---------|
| `Services/Crud/DTOs/CrudResult.php` | Operation result wrapper |
| `Services/Crud/DTOs/CrudMeta.php` | Result metadata |

### Form Requests

| File | Purpose |
|------|---------|
| `Http/Requests/CrudRequest.php` | Base validation |
| `Http/Requests/ListRequest.php` | List validation |
| `Http/Requests/DetailRequest.php` | Detail validation |
| `Http/Requests/ModifyRequest.php` | Modify validation |
| `Http/Requests/HistoryRequest.php` | History validation |
| `Http/Requests/TreeRequest.php` | Tree validation |

## Usage Examples

### List with Filters

```bash
curl -X POST /api/v1/select/articles \
  -H "Content-Type: application/json" \
  -d '{
    "columns": ["id", "title", "author.name", "created_at"],
    "relations": ["author"],
    "filters": {
      "operator": "and",
      "filters": [
        { "property": "status", "operator": "=", "value": "published" }
      ]
    },
    "sort": [{ "property": "created_at", "direction": "desc" }],
    "pagination": 10,
    "page": 1
  }'
```

### Get Single Record

```bash
curl /api/v1/detail/articles?id=123&columns[]=id&columns[]=title&columns[]=content
```

### Create Record

```bash
curl -X POST /api/v1/insert/articles \
  -H "Content-Type: application/json" \
  -d '{
    "changes": {
      "title": "New Article",
      "content": "Article content...",
      "status": "draft"
    }
  }'
```

### Update Record

```bash
curl -X PATCH /api/v1/update/articles \
  -H "Content-Type: application/json" \
  -d '{
    "id": 123,
    "changes": {
      "title": "Updated Title",
      "status": "published"
    }
  }'
```

### Delete Record

```bash
curl -X DELETE /api/v1/delete/articles \
  -H "Content-Type: application/json" \
  -d '{ "id": 123 }'
```

## Best Practices

### 1. Specify Columns

Always specify the columns you need to optimize query performance:

```json
{
  "columns": ["id", "name", "status"]
}
```

### 2. Use Pagination

Always paginate large datasets:

```json
{
  "pagination": 25,
  "page": 1
}
```

### 3. Filter Early

Apply filters to reduce dataset size before processing:

```json
{
  "filters": [
    { "property": "status", "operator": "=", "value": "active" }
  ]
}
```

### 4. Eager Load Relations

Include relations in request to avoid N+1 queries:

```json
{
  "relations": ["author", "categories"],
  "columns": ["id", "title", "author.name", "categories.name"]
}
```

### 5. Define crudComputedDependencies

For models with computed columns, define dependencies to optimize queries. Declaring the key is also what
allows a client to request that method as a `method` column:

```php
public function crudComputedDependencies(): array
{
    return [
        'computed_field' => ['columns' => ['field1', 'field2']],
    ];
}
```

## Faceted Counts

The standalone `crud/facets/{module}/{entity}` endpoint reuses the whole list
vocabulary (`filters`, `sort`, `pagination`) and serves two shapes on one route:

- **Tier 1 — enumerable counts.** Send `columns[]`; each column becomes a flat
  facet dimension returning every distinct value with a `total`/`count` pair.
- **Tier 2 — open facet.** Send a singular `facet` object to page, search and sort
  one high-cardinality dimension. The double counter reports `total` (ACL only)
  next to `count` (ACL + the request filters, minus the facet's own selection so
  cross-filtering stays live).

`facet` fields:

| Field | Meaning |
| --- | --- |
| `groupBy` | Key column grouped and counted on (e.g. `category_id`). |
| `fields[]` | Display fields resolved per key: base columns, or single-hop `relation.column`. |
| `labelField` | A label to search/sort by instead of the raw key; enables `label_asc`/`label_desc`. |
| `relation` | A BelongsToMany/MorphToMany relation to facet over its pivot instead of a base column. |
| `groupBy: relation.column` | A single-hop to-one (`BelongsTo`) column to group by via a join (e.g. `place.country`); the value is its own label. |
| `page`, `perPage`, `search`, `sort` | The facet's own window, value search and ordering. |

### Label resolution

A facet key labels through one of three sources, decided before the query by
whether the label is materialised in a DB column:

1. **Base column** — the key column itself, or a base column on the grouped model.
2. **Foreign-key label** — a single-hop `BelongsTo` keyed by `groupBy`
   (`license_id` → `license.uuid`). When the key is exposed only through an
   accessor (no `BelongsTo`), a model declares the mapping via
   `ProvidesFacetLabelSources::facetLabelSources()`, returning a `FacetLabelSource`
   (related class + foreign key). Content maps `entity` → `entity_id` this way so
   the content type labels from the entity name. A declared source wins over a
   same-named `BelongsTo`, and may point the label at the related model's
   locale-scoped translation (`translationRelation` + `translationColumn`) — so a
   base-column FK facet can label, search and sort by a **translated** name, keyed
   by the group key. Category maps `parent` → `parent_id` labelled from the parent's
   translated `name`.
3. **Translated label** — for a relation facet, a `relation.column` label field
   whose relation is the related model's `HasMany` translation relation
   (`translations.name`) is joined locale-scoped, enabling display, search and
   sort by the translated name.

Labels that live only in a PHP accessor (not materialised in any DB column) cannot
be searched or sorted; expose the underlying column through one of the sources
above to facet by it.

A facet's `groupBy` (and `relation`) is validated up front: if it resolves to a
column that does not exist — e.g. a magic accessor whose value lives on another
table, like `country` on a `HasPlace` model — the request fails fast with a clear
error naming the real path to use (`place.country`), never a cryptic SQL error.

### Related-column facets

A dotted `groupBy` such as `place.country` groups over a column reached through a
single-hop `BelongsTo`: the parent rows are joined to the related table and grouped
by the related column, counting distinct parents per value (the value is its own
label). This facets a list by a column that lives one hop away — e.g. Locations by
their place's country/province. Its selection folds back as a `relation.column`
membership filter.

### Relation facets

With `relation` set, keys are the related model ids and the double counter counts
distinct parent rows per related key. Parent ACL and filters are enforced through
a bounded id subquery (never a join into the aggregated query, so parent scopes
never collide with related columns), the MorphToMany morph constraint is applied,
and related soft-deletes are honoured. Content facets `categories` and `tags` this
way.

## Aggregate Route

`GET|POST /app/crud/aggregate/{module}/{entity}` (route `core.crud.aggregate`) and
`GET|POST /api/v1/aggregate/{module}/{entity}` (route `core.api.aggregate`) answer "how many, how much, and how it
changed over time" for one entity. It is a sibling of the [facets](#faceted-counts) route and follows the list's rules:

- The caller needs the `select` permission on the entity, as for the list. Without it the route answers `403`
  (`401` for the anonymous user), with the usual refusal body of the CRUD routes (`error` is a string).
- The row-level ACL and the request `filters` bound every figure: a count, a sum or an average never includes a row
  the list would not show. A relation the request names (in `filters`, as a group or as a label) obeys the related
  entity's `select` permission and ACL, like any [related record](#related-records).
- Every field the request names must be a column the caller can read ([readable columns](#columns-returned)). A
  hidden one is refused, never answered with another number.
- The response is never cached.
- On `/api/v1` the route sits behind the same `expose_api` switch, `api` guard and `api` rate limit as the rest of the
  CRUD API, plus its own limiter ([limits](#aggregate-limits-and-rate-limit)).

The examples below use an illustrative `shop/orders` entity with the columns `status`, `total` (decimal), `currency`,
`quantity`, `ordered_at` (date-time) and `customer_id`.

### Aggregate request

| Field | Meaning |
| --- | --- |
| `measures` | Required list of 1 to `max_measures` objects `{ "fn", "field"? }`. `fn` is `count`, `sum`, `avg`, `min` or `max`. `field` is a column of the entity itself and may be left out only for `count`, which then counts rows. A dotted field is refused (no measure on a related entity) and so is a figure asked twice. A measure is named `count` or `<fn>_<field>` in the result: `sum_total`, `count_total`. |
| `group_by` | Optional, one dimension `{ "field", "bucket"?, "label"? }`: an own column; a column one `BelongsTo` hop away (`customer.country`), which is its own label; a foreign-key column with `label` naming a relation column (`customer_id` with `customer.name`); or a date column with `bucket` (`day`, `week`, `month`, `quarter`, `year`). |
| `period` | `{ "field", ... }` over a date column of the entity, with exactly one of `last: { "n", "unit" }`, `preset`, or `from` with `to` ([periods](#aggregate-periods-and-time-zone)). |
| `filters` | The list vocabulary, see [Filters](#filters): conditions, nested AND/OR groups, relation filters and [model scopes](#scope-filters-in-requests). |
| `sort` | `{ "by": "measure" \| "key", "dir": "asc" \| "desc" }`; `measure` is the first measure. `by` defaults to `key` for a bucketed group and to `measure` otherwise, and `dir` to `asc` for `key` and `desc` for `measure`. |
| `limit` | Show only the first N groups of a column (at least 1, at most `max_groups`). |
| `other` | `true` with a `limit`: add the figures of the groups left out. |
| `compare` | `"previous_period"` (or `true`): add the figures of the previous period. Needs a `period`. |

A `POST` body, the request behind the first result example below:

```json
{
  "measures": [{ "fn": "count" }, { "fn": "sum", "field": "total" }],
  "group_by": { "field": "status" },
  "filters": [{ "property": "currency", "operator": "=", "value": "EUR" }],
  "limit": 2,
  "other": true
}
```

A `GET` caller sends `measures`, `period`, `filters`, `sort` and `group_by` as JSON strings in the query string
(the client URL-encodes them), `other` as text (`true`, `false`, `1`, `0`, `yes`, `no`, `on`, `off`) and `compare` as
`previous_period`. A `limit` and the `n` of `last` written as digits are read as the integers they spell:

```
GET /app/crud/aggregate/shop/orders
    ?measures=[{"fn":"sum","field":"total"}]
    &group_by={"field":"ordered_at","bucket":"month"}
    &period={"field":"ordered_at","last":{"n":6,"unit":"month"}}
```

Rules of the request, each broken one answering `INVALID_FIELD` on the part that breaks it:

- One grouping dimension: `group_by` is an object, never a list.
- A bucketed group needs a `period` and must group the period's own field, so the buckets are those of the period.
  A bucket has no `label`.
- `limit` and `other` apply only to the groups of a column. A request with no group, or a bucketed one (whose
  buckets are all shown), cannot carry them. `other` needs a `limit`.
- `compare` needs a `period`.

`AggregateQuery::fromArray()` is the one validator of the request: the route and any other caller build the same value
with it, so a request is refused the same way wherever it comes from.

### Aggregate result

```json
{
  "data": {
    "series": [
      { "key": "paid", "label": null, "values": { "count": 3, "sum_total": 61.25 } },
      { "key": "shipped", "label": null, "values": { "count": 2, "sum_total": 9.75 } }
    ],
    "total": { "count": 6, "sum_total": 171.5 },
    "other": { "count": 1, "sum_total": 100.5, "groups": 1 }
  },
  "meta": {
    "row_count": 6,
    "group_count": 3,
    "truncated": true,
    "currency": "EUR",
    "generated_at": "2026-10-21T08:30:00Z"
  }
}
```

- `data.series` is a list of `{ key, label, values }`, one per group, empty when the request has no group. `values`
  holds one entry per measure, by name. A value is a number, a string or `null`: a decimal is a JSON number, and the
  `min` or `max` of a date column is the text the database stores (`"2026-01-15 12:00:00"`). `null` is a figure no row
  gave (`sum`, `avg`, `min`, `max` over no value) or one a [mixed currency](#aggregate-currency) took away.
- `data.total` is the figures of every row the request covers, not only of the groups shown.
- `data.other` is flat, `{ <measure names>..., "groups": N }` with `N` the number of groups left out, and is present
  only when `other` was asked and some groups were left out. It is computed by the database over the rest of the
  rows, so the average of the rest is a real average, not derived from the groups shown.
- `data.compare` is present only when `compare` was asked: `{ total }`, and `series` too for a bucketed request.
- `meta.row_count` is the number of rows behind `total`. `meta.group_count` is the number of groups (the buckets of
  a bucketed request, `0` with no group). `meta.truncated` is true when groups were left out of `series`, whether or
  not `other` shows them.
- `meta.resolved_period` (`{ field, from, to, timezone }`, the days as `Y-m-d`) is present for a request with a period,
  and `meta.bucket` for a bucketed one. `meta.currency`, `meta.currencies` and `meta.warnings` are present when the
  [currency rules](#aggregate-currency) have something to say. `meta.generated_at` is an ISO 8601 UTC moment.

### Aggregate groups

- **An own column** keys a group by the stored value and gives it no label. A `null` value is a group of its own,
  with the key `null` and no label.
- **A relation column** (`customer.country`) groups the rows joined to the related table, as the
  [related-column facets](#related-column-facets) do: one `BelongsTo` hop only, a multi-hop path is refused. The value
  is its own label (as text). The join is an inner one: a row with no related row, or whose related row the caller
  may not see, is in `total` but in no group, so `series` plus `other` can add up to less than `total`. Without
  `select` on the related entity the request is refused like a relation of the list (`403`, `401` for the anonymous
  user).
- **A foreign-key column with `label`** (`{ "field": "customer_id", "label": "customer.name" }`) keys the groups by
  the key and labels the groups shown in one bounded read, under the permission and ACL of the related entity. The
  label is a column of the `BelongsTo` whose foreign key is the group field, or of a label source the model declares
  (`ProvidesFacetLabelSources`, translated labels included). A group whose related row the related ACL hides, that has
  no related row, or whose label is `null`, is labelled with its key (as text). The permission rule is the one above:
  no `select` on the related entity is refused, and a label column the caller may not read is `UNREADABLE_FIELD` on
  `group_by.label`.
- **Order.** `sort.by = key` orders by the value, with the `null` group last in both directions. `sort.by = measure`
  orders by the first measure, ties broken by key ascending with the `null` group after the groups it ties with. A
  group whose first measure is `null` sits where the database puts a null.
- **Limit and rest.** `limit` keeps the first N groups in that order. A `key` sort with a `limit` can fold the `null`
  group into `other`, since it comes last.
- **No limit.** A grouping that gives more than `max_groups` groups is `TOO_MANY_GROUPS`, and the message asks to
  narrow the request or to set a `limit`.

### Aggregate periods and time zone

A period names a date column of the entity (`field`) and gives its days in one of three ways, all of them days of the
application time zone (`app.timezone`, reported in `meta.resolved_period.timezone`):

| Form | Meaning |
| --- | --- |
| `last: { "n", "unit" }` | `n` from 1 to 100000 and `unit` one of `day`, `week`, `month`, `quarter`, `year`. Ends today and starts at the beginning of the unit `n - 1` units before the current one: `last 12 months` is twelve calendar months, the current one included and partial. |
| `preset` | `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_quarter`, `last_quarter`, `this_year`, `last_year`. A `this_*` preset is the whole unit, the days to come included. A week starts on Monday. |
| `from` and `to` | Two dates, `YYYY-MM-DD`, `from` not after `to`. `to` is inclusive. |

The period becomes a half-open range on the field, `field >= from 00:00:00 and field < to + 1 day`, in `Y-m-d H:i:s`,
added to the rows the ACL and the filters leave. The stored values are taken to be in the application time zone and
no conversion is made, which keeps the query portable (no `CONVERT_TZ`, no time zone tables). A per-user time zone
does not exist.

A malformed period, a field that is not a column of the entity or not a date column, is `INVALID_FIELD` (`period` or
`period.field`).

**Time buckets.** The database groups the rows by day, with one expression per driver (`Support\DayTruncation`), and
PHP rolls the days up into the bucket with decimal-safe arithmetic (`BucketRollup`), so money adds up exactly. The key
of a bucket is the date of its first day, `Y-m-d`, and its `label` is `null`: a week starts on Monday, a quarter on the
first day of its first month. The first and the last bucket can start outside the period (a period that begins in
mid-month gets the month bucket keyed by the first of that month), but only the rows of the period are counted.

- Every bucket of the period is in `series`, in the order of the sort (chronological by default). An empty bucket has
  `count` and `sum` at `0` and `avg`, `min` and `max` at `null`.
- A bucket's `sum` is the sum of its days, `min` and `max` are taken over the days (numbers as numbers, dates as text),
  and `avg` is the sum over the number of non-null values, at four decimals rounded half up, so it is weighted by the
  rows of each day and never an average of daily averages.
- A series sorted by measure puts a `null` first when ascending, and keeps the chronological order between ties.
- A stored value the database cannot read as a day fails the request (`500`, reported) instead of leaving the row out
  of the series while `total` counts it. A day outside the buckets of the period, which a stored value carrying its
  own offset can give, is left out of the series.

**Comparison.** `compare: "previous_period"` adds the figures of the period of the same length immediately before.
A period of whole calendar months (it starts on the first day of a month and ends on the last day of a month) shifts by
that many months: `last_month` is the month before, `this_quarter` the quarter before. Any other period shifts by its own
length in days: a partial `last 12 months` (from the first day of a month eleven months back to today, 354 days on
2026-10-20) compares with as many days immediately before it, never with twelve months nobody asked for.

- `data.compare.total` always exists. `data.compare.series` exists only for a bucketed request and is built by the same
  rules (every bucket filled), but is **always chronological**, whatever the sort of `series`.
- `compare.series[i]` stands beside `series[i]` only while `series` is chronological, which is the default. When the
  request sorts `series` by a measure, or by key descending, a consumer aligns the two by key.
- The two series are as long as their periods have buckets: when the period is not whole months, the previous one can
  have one bucket more or fewer.
- The previous period obeys `max_points` too (`PERIOD_TOO_LONG`, the message starts with "The previous period"). A
  mixed currency is looked for in the previous period as well.

A bucketed request with a comparison (`period: { "field": "ordered_at", "from": "2026-04-01", "to": "2026-06-30" }`,
`group_by: { "field": "ordered_at", "bucket": "month" }`, `measures: [{ "fn": "count" }]`):

```json
{
  "data": {
    "series": [
      { "key": "2026-04-01", "label": null, "values": { "count": 1 } },
      { "key": "2026-05-01", "label": null, "values": { "count": 0 } },
      { "key": "2026-06-01", "label": null, "values": { "count": 3 } }
    ],
    "total": { "count": 4 },
    "compare": {
      "total": { "count": 3 },
      "series": [
        { "key": "2026-01-01", "label": null, "values": { "count": 1 } },
        { "key": "2026-02-01", "label": null, "values": { "count": 0 } },
        { "key": "2026-03-01", "label": null, "values": { "count": 2 } }
      ]
    }
  },
  "meta": {
    "resolved_period": { "field": "ordered_at", "from": "2026-04-01", "to": "2026-06-30", "timezone": "Europe/Rome" },
    "bucket": "month",
    "row_count": 4,
    "group_count": 3,
    "truncated": false,
    "generated_at": "2026-10-21T08:30:00Z"
  }
}
```

### Aggregate measurable columns

`Support\ColumnKinds` says what a column is: `numeric`, `temporal` or `other`. The cast of the model decides first
(`int`, `integer`, `float`, `double`, `real`, `decimal` are numeric; `date`, `datetime`, `immutable_date`,
`immutable_datetime`, `timestamp` are temporal) and the schema type second, with its precision cut
(`decimal(15,4)` is `decimal`) and the PostgreSQL names (`int4`, `float8`) known. `tinyint` is never numeric, because
MySQL stores a boolean in it. A temporal cast on a column the schema stores as a number (unix seconds cast as
`timestamp`) is neither a date column nor a number.

| Function | Accepts |
| --- | --- |
| `count` | Any column the caller can read, or no field (all rows). A `count` over a field counts its non-null values. |
| `min`, `max` | A numeric or a temporal column, a key included. |
| `sum`, `avg` | A numeric column that is not a key: neither a primary key (every column of a composite one) nor the local side of a foreign key. |

Anything else is `NOT_MEASURABLE`. A name that is not a column of the table (an accessor, a relation, a dotted path)
is `INVALID_FIELD`, a column the caller cannot read is `UNREADABLE_FIELD`.

### Aggregate currency

`Support\CurrencyColumns` pairs an amount with its currency by name: an amount `<x>_<suffix>` takes the column
`currency_<suffix>` when the table has it, and otherwise the column `currency`. The suffix is the text after the last
underscore: `unit_price` is paired with `currency_price` and `total_net_amount` with `currency_amount`. A field with no
underscore is paired by its whole name (`total` takes `currency_total`, then `currency`). A table with neither has no
currency to check.

Only `sum`, `avg`, `min` and `max` over a numeric column that is not a key, on a table that has a paired currency
column, are amounts. The rule is by type, not by meaning: a `sum(quantity)` on a table with a `currency` column is
treated as an amount too (a known false positive), while `count`, dates and keys never are. Nothing is converted.

- A figure whose rows hold more than one currency is `null`: a group, a bucket, `other`, the total, and the figures
  of the previous period alike. One warning per measure, however many cells mix, goes to `meta.warnings` as
  `{ "code": "MIXED_CURRENCY", "field": "<measure name>", "message": "..." }`, and `meta.currencies` lists the
  currencies of the rows of the result (at most 20, ascending) when its total is mixed. `meta.currency` is absent.
- A cell with one currency keeps its value. When the whole result, the previous period of a comparison included, has
  one currency and no cell mixes, `meta.currency` carries it. A previous period in a single currency other than the
  current one leaves `meta.currency` out, with no warning, since the result then names two currencies.
- `null` and the **empty string** are not currencies: rows with one of them are ignored like `NULL`, a cell with one
  currency beside such rows keeps its value, and a cell with only such rows has no currency. Values are not trimmed: a
  value of spaces still counts as a currency, and a padded `'EUR '` next to `'EUR'` counts as two.
- **Grouping by the currency column of the amount** (`group_by: { field: "currency" }`) makes every group
  single-currency: the group values stay numbers, but `total` and `other`, which add the groups, are `null`, **without
  a warning**, and the result has no `meta.currency`. A `null` total with `meta.row_count > 0` and no warning means the
  groups are the currencies.
- A mix found only in the previous period gives a warning and a `null` in `compare`, but no `meta.currencies` and no
  `meta.currency`.

```json
{
  "data": { "series": [], "total": { "count": 3, "sum_total": null } },
  "meta": {
    "row_count": 3,
    "group_count": 0,
    "truncated": false,
    "currencies": ["EUR", "USD"],
    "warnings": [
      {
        "code": "MIXED_CURRENCY",
        "field": "sum_total",
        "message": "\"sum_total\" adds amounts in more than one currency, so it has no value: group by \"currency\" to get one value per currency."
      }
    ],
    "generated_at": "2026-10-21T08:30:00Z"
  }
}
```

### Aggregate limits and rate limit

The `aggregate` block of `config/config.php` (`core.aggregate.*`) holds the ceilings. Each is set from an environment
variable (see the Core README); a value below 1 or not numeric falls back to its default, so a wrong setting never
switches a limit off. The ceilings are checked before any query runs, except the number of groups of a request with no
`limit`, which one count query settles.

| Key | Default | Effect |
| --- | --- | --- |
| `max_measures` | `3` | Measures one request may hold. More is `INVALID_FIELD` on `measures`. |
| `max_groups` | `50` | Groups of a column one request may show. A `limit` above it is `TOO_MANY_GROUPS` on `limit`; with no `limit`, a grouping that gives more groups is `TOO_MANY_GROUPS` on `group_by.field`. |
| `max_points` | `400` | Buckets of one series. The series of the period and, with `compare`, the series of the previous period are each held to it, so a result can hold up to twice this many. More is `PERIOD_TOO_LONG` on `group_by.bucket`, and the message names a coarser bucket that fits or asks for a shorter period. |
| `max_period_days` | `1096` | Days of the requested period, both ends counted. More is `PERIOD_TOO_LONG` on `period`. The previous period is not measured by it. |
| `max_objects_per_message` | `6` | Reserved: nothing reads it yet. The route ignores it; `AggregateLimits` carries it for a later consumer. |
| `rate_per_minute` | `60` | Requests a minute to the route, per user. |

The rate is a named limiter, `aggregate`, defined next to the `api` limiter in `CoreServiceProvider` and attached by
`throttle:aggregate` in `routes/crud.php`, so it covers `/app` and `/api/v1` alike (on `/api/v1` it adds to the `api`
limiter). It counts per signed-in user, and per client address for the anonymous user, a user with no email, or no user
at all. The ceiling is read when a request arrives. Over it the route answers `429` with Laravel's default `{ message }`
body, outside the `{ error }` envelope of this route.

There is no hard timeout in v1: the code `TIMEOUT` is reserved and never emitted.

### Aggregate errors

A refusal of the contract is a `422` with `{ "error": { "code", "message", "field"? } }`. `field` is the path of the part
of the request that caused it, for example `measures`, `measures.0.fn`, `measures.1.field`, `group_by`, `group_by.field`,
`group_by.bucket`, `group_by.label`, `period`, `period.field`, `sort`, `sort.by`, `sort.dir`, `limit`, `other`, `compare`,
`filters`, `filters.<property>`. The other answers (`403`, `401`, `429`, `500`) have bodies of their own, listed below.

| Code | When |
| --- | --- |
| `INVALID_FIELD` | A malformed request (the first message and first key of the validation), a field that is not a column of the entity (or of a to-one relation, for a group), a period or bucket field that is not a date column, a bucket without a period or on another field than the period's, a combination the rules above refuse. |
| `UNREADABLE_FIELD` | The caller cannot read the field. A filter column is reported as `filters.<property>`. |
| `NOT_MEASURABLE` | The function does not suit the column (`sum` of a string or of a key, `min` of a string). |
| `UNSUPPORTED_FUNCTION` | `fn` is not one of the five (`measures.N.fn`), or the database driver has no day expression for a bucket (`group_by.bucket`). |
| `TOO_MANY_GROUPS` | A `limit`, or an unlimited grouping, above `max_groups`. |
| `PERIOD_TOO_LONG` | A period above `max_period_days`, or a series above `max_points`. |
| `TIMEOUT` | Reserved, never emitted. |

`MIXED_CURRENCY` is in the enum but is a warning of a `200`, not an error.

Other answers:

- **Filters.** A filter column the caller cannot read is `UNREADABLE_FIELD` on `filters.<property>`, and an unknown plain
  column (or the JSON path of one) is `INVALID_FIELD` there. A property the list reads from outside the columns
  (a translated or a dynamic field) still narrows the figures. A dotted path is checked for readability only: an
  unknown column at its end is not refused up front, as in the list. A scope filter the list refuses (an undeclared
  scope, for instance) is `INVALID_FIELD` on `filters`.
- **`403` and `401`.** A missing `select` permission, or a related entity the caller may not select (a relation group, a
  label, a relation in `filters`). The body is the refusal body of the list, not the envelope: `error` is a string and
  `data` is `[]`.
- **An `InvalidArgumentException`** from the list machinery (its guards name fields, never SQL) is `422` `INVALID_FIELD`
  with its message and no `field`, and is logged at notice level (`Aggregate request refused`, with the exception
  class, message, module and entity).
- **A deployment fault** (an unsupported database driver for a bucket) is the same `422`, and is also reported to the log
  at error level. A client fault is not logged.
- **`500`.** Any other failure answers `{ "error": { "message": "The aggregate could not be computed." } }`, with no
  exception text (a failed query's message holds its SQL) whatever `app.debug` says. The exception is reported.
- **`429`.** Over the rate, as above. The body is Laravel's default `{ "message" }`, outside the envelope.

### Aggregate database support

SQL never receives a field of the request as text: a field is used only once it is a column of the model (or of the model
a to-one relation reaches), qualified by its table and wrapped by the grammar of the connection; functions and buckets
are closed lists; figures are selected under positional aliases (`m0`, `m1`) and named in PHP.

The day expression of a bucket is `date(col)` (sqlite), `DATE(col)` (mysql, mariadb), `CAST(col AS date)` (pgsql,
sqlsrv) and `TRUNC(col)` (oracle). A bucket on any other driver is refused before any query. The tests and CI run on
SQLite only: the other expressions are covered as generated strings, and the engine still needs a run against a real
MySQL and PostgreSQL server before the route is called supported there.

Known limits:

- On SQLite, a `date` column that holds a bare `Y-m-d` written outside Eloquent loses the first day of a period and
  gains the day after `to`, because the bounds are compared as text in `Y-m-d H:i:s`. MySQL and PostgreSQL compare it
  correctly.
- The engine groups by one dimension, with no measure on a related entity, no distinct counts and no currency
  conversion.
- A `sum(quantity)` is treated as an amount when the table has a currency column.

### Aggregate code map

Paths are under `app/`.

| Piece | Role |
| --- | --- |
| `Http/Requests/AggregateRequest` | Extends `ListRequest`. Shape rules, the JSON strings of a `GET`; a failed validation is an `AggregateException`. |
| `Services/Crud/Aggregate/AggregateQuery` | The validated request as a value (with `AggregateMeasure`, `AggregateGroup`), shared by every caller. `canonical()` gives one string for two spellings of a request. |
| `Services/Crud/CrudService::aggregate()` | Permission, the field and filter checks, then the engine over an `AggregateScope` of closures on the facets' helpers (ACL query, list filters, related visibility, labels). |
| `Services/Crud/Aggregate/AggregateEngine` | Dispatches to the ungrouped, column-group and bucketed paths. |
| `PeriodPlan`, `AggregatePeriod`, `PeriodComparison`, `BucketSeries`, `BucketRollup` | The period, the bucket series, the previous period and the decimal roll-up. |
| `MeasureSql`, `CurrencyCheck` | The SQL of the measures and the currency partials, and the mixed-currency rule. |
| `Support/ColumnKinds`, `CurrencyColumns`, `DayTruncation` | What a column is, which column holds the currency of an amount, and the driver expression for a day. |
| `Exceptions/AggregateException`, `Enums/AggregateErrorCode` | The refusal: a code, a message without SQL, a `field`, and whether it is a deployment fault to report. |
| `Services/Crud/Aggregate/AggregateLimits` | The `core.aggregate.*` ceilings. |

Contract covered by `tests/Feature/Controllers/CrudAggregateTest.php` and the `Aggregate*` tests of
`tests/Integration` and `tests/Unit`.

## List Freshness

`GET crud/freshness/{module}/{entity}` is a lightweight fingerprint of a list page,
used by the UI to tell whether the rows on screen are stale without reloading them.
It takes the same `ListRequest` vocabulary as `select` (`filters`, `sort`, `page`,
`pagination`, `from`/`to`, `limit`) and runs through the same pipeline: `select`
permission on the entity table, ACL filter injection, filters and sort. It is never
cached.

Only the primary key and, when the model uses timestamps, its `updated_at` column
are selected, read through the base query builder (no Eloquent hydration, no eager
loads), so model scopes that expect wider selects cannot interfere.

Optional `check_ids[]` (max 100, scalar ids of the client's snapshot page) asks the
server to classify each id against the **filtered set without the page window**:

| `status` | Meaning |
| --- | --- |
| `on_page` | Still matches the filters and is in the current page window. |
| `off_page` | Still matches the filters but moved to another page (sort or insert shift). |
| `gone` | No longer in the filtered set: deleted, soft-deleted, or filtered out by ACL or a changed value. |

Response `data`:

```json
{
  "total": 42,
  "items": [{ "id": 7, "updated_at": "2026-08-06T10:15:00+00:00" }],
  "presence": [{ "id": 7, "status": "on_page", "updated_at": "2026-08-06T10:15:00+00:00" }]
}
```

- `total` is the filtered count across all pages; `items` is the current page only,
  in the same order as `select`.
- `updated_at` is normalised to ISO-8601 UTC so clients can compare it with Eloquent
  JSON timestamps. It is `null` for models without timestamps (only presence and
  `total` can then signal drift) and for `gone` entries.
- `presence` is empty when `check_ids` is omitted, and for composite primary keys.

The response carries the usual list `meta` (`totalRecords`, `currentPage`, ...).
Contract covered by `tests/Feature/Controllers/CrudFreshnessTest.php`.

## Related Documentation

- [ACL System](./ACL_SYSTEM.md) - Row-level security
- [API access control](./rag/API_ACCESS_CONTROL.md) - Guards, tokens, default roles, related records, media projection
- [Permissions](./PERMISSIONS.md) - Permission system
- [Roles](./ROLES.md) - Role hierarchy
