# API access control: developer and operator guide

How a request to `/api/v1` is authenticated, which permissions apply to it, which records and attributes it can
read, and how tokens work. Everything here is Core behaviour; CMS, ERP, SAO and MES only add routes, grants and
hidden attributes. The CRUD mechanics are in [CRUD_SYSTEM.md](../CRUD_SYSTEM.md), the row-level filters in
[ACL_SYSTEM.md](../ACL_SYSTEM.md).

## One model for every `/api` route

| Question | Answer |
|---|---|
| Is the API on? | `expose_api` (runtime setting, config `core.expose_api`, default `false`). Off, every `/api` route answers `403`, Core and modules alike. |
| Who is calling? | The holder of an `Authorization: Bearer` token, or the anonymous user. |
| Through which surface? | The `api` guard. `/app` (session, Filament) stays on `web`. |
| With which permissions? | The roles of the `api` guard, narrowed by the token's abilities, then the row-level ACLs. |
| From where? | Optional per-token network list (`allowed_cidrs`). |

Turning the API on exposes nothing that a role of the `api` guard has not been granted.

## Permissions exist per guard

`php artisan permission:refresh` creates every permission twice, `guard_name` `web` and `api`, with the same name
(`default.cms_contents.select`). Existing does not mean granted: a role holds the permissions of its own guard only.

- `User::hasPermission($name, $guard)` and `Role::hasPermission($name, $guard)` take the guard on every path: direct
  permissions, roles and ancestor roles. Only roles of that guard are considered. With no guard they use the request's
  (`Auth::getDefaultDriver()`).
- A permission that does not exist on the guard is "not granted": a `403` (or `401` for the anonymous user), never an error.
- `AuthorizationService` checks against the request's guard on every route. `/app` routes keep `web`; the `api` middleware
  switches the default guard to `api` for the request.
- Roles are identified by `(name, guard)`: the `guest` of `/app` and the `guest` of `/api` are two roles.

## The `/api` request lifecycle

`Modules\Core\Http\Middleware\AuthenticateApiRequest` (alias `api_access`) runs in the `api` group of Core and of
every module, right after `ApplyDatabaseSettingsOverlay` (which supplies the switch it reads) and before route model
binding, so a refused request never learns whether a record exists. In order:

1. The switch: `core.expose_api` false gives `403`.
2. `Auth::shouldUse('api')`, so every permission check of the request reads the `api` grants.
3. The caller:
   - **No `Authorization` header that mentions a bearer:** the request runs as the anonymous user, the account named by
     `permission.users.guest` (`GUEST_USER`, default `anonymous`). It holds the `guest` role of both guards.
   - **A bearer claim:** the token is resolved through the Sanctum guard. The principal comes from the token alone; a web
     session is never consulted. A token that does not resolve (malformed, unknown, expired, revoked) is `401` with
     `WWW-Authenticate: Bearer` and **never** falls back to the anonymous user. A token of a superadmin is `401`.
     A token whose `allowed_cidrs` do not contain the client address is `403`, logged with the token id, the owner id and
     the address, never the token.
4. The rate limit (limiter `api`): `core.api.rate_limit_per_minute` (default `600`) per token, or per client address when
   there is no token. Over the limit: `429`.

**Failed authentications are throttled before the token is looked up.** Every `401` and CIDR `403` of a bearer request
counts against the client address; an address that failed `core.api.rate_limit_per_minute` times in a minute gets `429`
before any lookup, so a token cannot be guessed at full speed. Successful requests do not consume that budget, so
legitimate tokens behind one address (a factory network) are limited only by their own per-token limit.

### Status codes

| Situation | Status |
|---|---|
| Switch off | `403` |
| Bearer present but not valid, or superadmin token | `401` |
| Address outside the token's networks | `403` |
| Permission denied, anonymous caller | `401` (a login or a token may change the answer; the SPA re-authentication prompt keys on it) |
| Permission denied or ability missing, authenticated caller (session or token) | `403` |
| Relation the caller may not read, explicitly requested | same rule as the two lines above |
| Name in the request that is not a relation, a declared computed method or an attribute accessor | `400` |
| Facet field, group-by key, sort, filter property or aggregated column the caller does not receive | `400` |
| Field of an aggregate request (measure, group key, label, period field, filter property) the caller does not receive | `422` `UNREADABLE_FIELD`, in the aggregate route's own `{ "error": { "code", "message", "field" } }` envelope |
| Server error (`app.debug` off: the status text only, never the exception message) | `5xx` |
| Server error on the aggregate route (any `app.debug`) | `500` `{ "error": { "message": "The aggregate could not be computed." } }`, never the exception message |
| Too many requests, or too many failed authentications | `429` |
| Too many aggregate requests (`core.aggregate.rate_per_minute` a minute per user, per client address for the anonymous user) | `429`, Laravel's default `{ "message" }` body |

### Routes that carry their own credential

Some callers send a secret of their own in `Authorization: Bearer` (payment and e-invoice provider callbacks). Their
route declares `->middleware(SelfAuthenticatedApiRoute::class)`. For such a route the switch, the `api` guard and the
rate limit still apply, but the bearer is **not** read as a Laraplate token: the request runs as the anonymous user and
the route's own check authenticates it. Today these are the two ERP provider callbacks. The MES machine sources are not
among them: they send the Laraplate token of their service account.

A route without the marker treats every bearer as a Laraplate token. A webhook sender that sends an
`Authorization: Bearer` header to such a route (SAO webhooks verify custom headers, not `Authorization`) gets `401`.

## Default roles

Seeded by `CoreDatabaseSeeder` and the module seeders. `guest` is the role of the anonymous user.

- `guest` on `web`: **no permission**. `/app` is for authenticated users only; anonymous session reads answer `401`.
  Info routes (`/app/about`, `/app/auth/user/profile-information` for a guest) check no permission and keep working.
  An unauthenticated `/app` call is checked as the anonymous user, set on the guard for that request only and never
  signed in to the web session, so the `auth` group (preferences, first-login completion) stays closed to it.
- `guest` on `api`: the public list below, each entity narrowed by its ACL.
- `admin`, `publisher`, `system`: their `web` permissions and no `api` permission.
- `superadmin`: no permissions, full access by construction; cannot use tokens.
- Roles of the `api` guard for people and integrations are created by administrators when needed.

Public list of `guest` (`api`), all `select`:

| Entity | Row condition (ACL) | Hidden from anonymous |
|---|---|---|
| `core_settings` | `is_public` | |
| `cms_contents` | currently published (`valid_from <= @now` and `valid_to >= @now` or null) | |
| `cms_contents_references` | owner content published | |
| `vend_media` | owner is a published CMS content (relation filter with morph type) | safe media projection |
| `core_taxonomies` (CMS categories) | scopes `valid` and `active` plus a relation filter on the CMS entity type (the table is shared) | |
| `cms_tags`, `cms_locations` | none | |
| `cms_contributors` | none | `user_id`, `user`, `shared_components` |
| `cms_comments` | none (pending comments never reach the table) | `user_id` |
| `cms_contents_ratings` | none | `user_id` |

"None" means no default ACL, not that ACLs are excluded: an installation may add its own. A guard test fails when the
`api` guest grants differ from this list or when the `web` guest holds any permission.

The publisher (a `web` role) also holds `core_settings.select`, narrowed to public settings by an ACL, and
`core_entities.select`, narrowed to the CMS entity types: with related records obeying their own permission, the
content screens read those entities through the contents they load.

Anonymous responses are cached per user, so all anonymous callers share one cached response for an entity that uses the
response cache. A row that stops being valid stays visible for at most the configured TTL (`cache.duration`, its
`medium` value). CMS contents do not use the response cache; core settings do.

## Related records obey their own permission and ACL

Select, detail, search, tree, history, facets and aggregate apply one visibility rule: a relation to an independent entity is
loaded only if the caller holds that entity's `select` permission on the request's guard, and the related ACL narrows it,
exactly as it narrows a root query. The rule holds for `relations`, dotted `columns`, relation filters, sorts, group-by
keys and aggregates.

- A relation the caller **explicitly requested** and may not read: `403`/`401` (see the table above). A relation the
  model loads on its own (default eager loads, the dependency of a computed column, an appended attribute such as
  `cover`) and the caller may not read is left out, or `null`.
- An entity with no registered `select` permission and outside `PermissionsRefreshCommand`'s deliberate blacklist
  fails closed. Of the blacklisted classes only two are read as relations: a pivot record reached through a plain
  relation (the `presettable` link a model loads for its dynamic fields) and a translation loaded from the model it
  translates. Versions, licenses and dynamic entities are read as a relation by a superadmin only; model embeddings
  (vectors, model keys, content hashes) and personal access tokens never, whoever asks; modifications follow the
  pending-changes rule below.
- **Parts of a parent** implement `Modules\Core\Contracts\IsPartOfParent::parentRelation()`: records that only exist
  inside their parent (translations, document lines, BOM lines, quality plan characteristics, approvals, CMS references
  and ratings). Loaded from their own parent they inherit its visibility and are not checked separately, although a
  permission exists for their table: an installation's own ACL on a part applies only when the part is read directly
  (its own endpoint), or reached from any other model, where it is checked against its parent's permission and ACL.
  The per-record check run when a model is hydrated agrees: for reading, a part passes when the caller holds (on the
  request's guard, within the token's abilities) the `select` of any model along its parent chain: its own, that of
  every intermediate part, or that of the first owner up the chain that is not a part. So a token carrying only
  `cms_contents.select` reads a content's references and ratings, a role holding only `shop_product_variants.select`
  reads a variant's items (parts of the variant, itself a part of its product), and a caller holding only a part's
  own permissions passes the check on the part's own endpoint (detail, update, delete); a caller holding none does
  not hydrate the part (the refusal names every table checked). A model of the chain with no registered permission
  adds nothing; a morph or undeclared parent relation, a cycle or a chain longer than eight parts leaves only the
  part's own permission in charge. Translations are covered: outside the permission scheme
  themselves, they are hydrated by whoever may read the translated model. A side-effect read the application takes on
  its own behalf (CMS re-indexing a content after a reference changed) runs as a system read
  (`RetrievedSelectGuard::forSystemRead()`), so a caller holding only a reference's permissions can update or delete it
  end to end. Writes to a part keep the part's own
  permission. A model qualifies when it has a non-nullable foreign key to its parent with cascade delete and no
  Filament resource of its own.
- **Names are checked before anything is called.** `Support\RelationGuard::relationOf()` accepts a name only when it is a
  public, non-static method with no required parameter and a declared `Relation` return type: a relation method without
  a return type answers `400` until it is typed (for example `notifiable` on notifications). The relation black list
  (`history`, the version relations, `tokens`, `embeddings`, the tree-walk relations) is dropped, at any depth, from the
  relations a select, a detail or a search loads; grouping on one is `400`. A relation the model loads on its own
  whose name does not resolve to a relation (an untyped method, a path through a morph relation) is not loaded.
- **Computed `method` columns need a declaration.** A request may call a model method as a column only if the method is a
  key of the model's `crudComputedDependencies()` map, is not inherited from Eloquent, its builders or a vendor package,
  is not a relation and not a write (`delete`, `save`, `sync`, `truncate` are refused even if declared). No production
  model declares one, so every client `method` column is refused today. An appended column needs a real accessor, and a
  group-by key may not name a method.
- **Pending modifications** (`modifications`, the values somebody proposed and nobody approved) are readable only by a
  caller holding the parent entity's `update` or `approve` permission on the request's guard: others get the relation
  omitted when it is appended or default, and `403`/`401` when explicitly requested.
- **History** (`/api/v1/history/{entity}`) needs the entity's `update` or `approve` permission, like the pending
  modifications, because versions hold pre-publication drafts. The `record` is serialized through the model, so hidden
  attributes and the media projection apply; history rows carry no nested copy of the record. Tree and history reads apply
  the root entity's ACL to the nodes and versions they return.
- **Partial relations are declared.** On `detail`, a relation the related ACL narrowed carries
  `meta.relations.{name}.hidden`, the number of related records left out (one aggregate count per filtered relation, none
  when no ACL narrows it). `list` and `search` do not carry it.
- **Scoped sync (writes).** Syncing a many-to-many relation on `update` works only within the related records the writer
  can read: hidden records stay attached with their pivot rows untouched, additions and removals are computed on the
  visible subset, and attaching an id the writer cannot read (hidden or nonexistent) is `403` and rolls the save back.
  Parts of the parent sync as before. `HasMany` relations are not syncable (only many-to-many relations a model whitelists
  in `syncableRelations()`).

### Search

Search applies the same rule. ACL column conditions go to the engine; a relation filter or a scope filter cannot (the
engine takes column conditions only, and an OR group holding one of them is left out of the engine query), so it is
applied when the hits are reloaded from the database. A search page can therefore hold fewer records than requested, and
`totalRecords` of a search can count hits the caller may not see. Relations of the reloaded records go through the same
authorizer and black list as select.

### Aggregate

The aggregate route (`GET|POST /app/crud/aggregate/{module}/{entity}` and `/api/v1/aggregate/{module}/{entity}`, route
names `core.crud.aggregate` and `core.api.aggregate`) computes counts, sums, averages, minima and maxima, by group or by
time bucket. It adds no permission: it needs the `select` permission on the entity, on the request's guard and within the
token's abilities, as the list does (`403`, `401` for the anonymous user). The row-level ACL and the request filters bound
every figure, so a total never includes a row the list would not show, and an anonymous `api` caller aggregates only what
the `api` guest may select, narrowed by its ACL. A relation used in a filter, as a group (`customer.country`) or as a group
label must be one the caller may select, and the related ACL narrows it as it narrows a root query. Every field of the
request must be a column the caller receives (see Readable columns). The route is under the `expose_api` switch with the
rest of `/api/v1`, and carries its own limiter on top of the `api` one. Developer reference: `docs/CRUD_SYSTEM.md#aggregate-route`.

## Scope filters in ACLs

An ACL (and a client request, see below) can name a **model scope** instead of repeating its conditions:
`{"scope": "valid"}`, `{"scope": "expiring", "parameters": [48]}`. The full reference is
`Modules/Core/docs/ACL_SYSTEM.md#scope-filters`; what an operator or integrator needs:

- **What can be named.** Only a local scope whose method carries `#[FilterScope]` (a module declares its own, a trait's
  declaration holds for every model using it). Declared: the validity scopes of `HasValidity` (`valid`, `published`,
  `draft`, `scheduled`, `expired`, `validAt(date)`, `expiredAt(date)`, `expiring(hours)`) usable by clients, and `active`,
  `inactive` of `HasActivation`, ACL-only. A model overriding a trait scope without the attribute undeclares it for itself
  (`License::expired`).
- **Client exposure rule.** `#[FilterScope(client: true)]` allows CRUD requests; the default is ACL-only, because a scope
  may read columns a client cannot filter on (hidden or anonymous-hidden columns) and would let it probe them. A client
  scope may also hold no subquery (`whereHas`, `has`, `whereExists`, a subquery comparison) and no raw SQL: the rows it
  would reach are never checked against their own ACL, so the request is `422` (an ACL keeps them). A request
  naming an ACL-only or undeclared scope, or a wrong parameter, is `422`. The request parser is the gate, so the in-app
  assistant's CRUD tool is bound by it too. Requests get no placeholders; the scope is ANDed with the caller's ACL and
  never widens it.
- **Parameters.** Scalars and ISO 8601 dates, typed from the method signature; strings are strict (an integer is refused
  for a string parameter); arrays and objects, missing and extra parameters are refused. An ACL parameter may be `@now` or
  `@today` (date positions only) or `@user.<attribute>` (any position).
- **Purity.** A scope runs inside its own nested `where` in AND and OR groups alike and may add only where conditions
  (no joins, orders, columns, groups, limits, unions). A run that adds no condition at all (a `when()` on a null
  parameter, an `@user.<attribute>` that does not resolve) is refused too, since it would match every row: at save when
  the default or sample run adds nothing, otherwise by denying the read. A filter never removes or negates a global scope of the model; there
  is no `not`.
- **Relation filters.** A scope inside a relation filter is checked and applied on the related model (each morph type).
- **Fail closed.** Saving an ACL with an undeclared, impure or badly parameterised scope is a validation error. An ACL
  whose scope stops resolving later (the method was renamed, the signature changed) **denies the read** (`403`, `401` for
  the anonymous user) and logs a warning with the ACL id and the reason, never a parameter value. The save-time purity run
  uses the parameter defaults or samples, so a scope that is impure only off the default branch is caught only when used.
- **Search.** Engines never receive a scope. It is dropped from an AND group, the whole OR group holding it is left out, and
  the condition is enforced when the hits are reloaded from the database (the database engine does the same). Totals and
  page sizes can therefore drift from the engine's counts, and an index-only field inside an OR group re-applied at the
  reload is evaluated by the database's rules, so the results can be narrower, or the reload fails with a server error
  when the field is not a column of a model without dynamic contents (never wider: it fails closed).
- **CMS defaults.** The anonymous categories ACL is `valid` + `active` + the CMS entity relation filter. The anonymous
  contents, references and media ACLs stay on columns so that anonymous content search keeps exact totals. Existing
  installations keep their stored ACLs (the seeder never overwrites one).

## Columns returned

### Hidden attributes for the anonymous user

A model implements `Contracts\IHidesAttributesFromAnonymous::anonymousHiddenAttributes()` (trait
`Models\Concerns\HidesAttributesFromAnonymous`). The listed attributes are filtered out of the serialization when the
authenticated user of the current guard `isGuest()`, on every route and as a relation of another entity; staff, tokens
and Filament see them. The filter sits in `getArrayableItems()` and never alters the model's `$hidden`, so one instance
serialized for the anonymous user and then for staff is not left stripped. Declare columns or relations of the model
itself: fields that the translation and dynamic-content traits merge in afterwards are not covered. With nobody acting
(console, queue, indexing) the record is serialized in full.

`User::isGuest()` is true for the account named by `permission.users.guest` (by name or username) and for a user with no
email; a service account is never a guest unless it carries the guest name.

### Readable columns

What the serialization withholds cannot be read through the query either: counts, buckets and orderings would answer
it. A facet field (flat `columns`, or an open facet's `groupBy`, `fields`, `labelField` and `relation`), a `group_by`
key, a `sort`, a filter property or an aggregated column (`sum`, `average`, `min`, `max` of a related column) that
names a column the caller does not receive answers `400` before anything is queried. A dotted path is checked on each
model along it. The aggregate route applies the same rule to every field of its request (measure, group key, label, period
field, filter property) and answers `422` `UNREADABLE_FIELD` with the path of the field (`measures.0.field`,
`filters.<property>`) instead of `400`. It checks one column the request does not name as well: the currency column
paired with an amount (`currency`, or `currency_<suffix>`), whose values reach the result as `meta.currency`,
`meta.currencies` and the warnings. When the caller cannot read it, a `sum`, `avg`, `min` or `max` over a numeric column
that is not a key and is paired with it is `UNREADABLE_FIELD` on `measures.N.field` (a `count` is not affected). A
`relation` filter node (`{ "relation", "filters" }`) is a different refusal: it is `INVALID_FIELD` on `filters`, at any
depth, because the list would read it as a group of the main record. The rule (`Support\ReadableColumnGuard`) is:

- the anonymous user may not name an attribute or relation the model lists in `anonymousHiddenAttributes()`
  (`facets/cms/comments?columns[]=user_id`, `contributors.shared_components`);
- a caller who receives the media projection may name only `id`, `collection_name`, `mime_type` and `size` of a media
  (`Contracts\IRestrictsReadableColumns`, `MediaProjection::QUERYABLE_COLUMNS`); a holder of `vend_media.update` on
  the `api` guard, within its token's abilities, may name every column.

The ACL filters are exempt: they name columns on their own authority and are merged after the check.

Graph nodes follow the same rule: their label and summary fields are read only from attributes the model returns to
the caller (not in `$hidden`, not hidden from the anonymous user, within the media projection).

Versions (history) mask `[hidden]` in their `contents` and `original_contents` every attribute in the versioned model's
`$hidden` (passwords, remember tokens, two-factor secrets of a versioned user), the attributes the model hides from
the anonymous user when the anonymous user reads them, and any bcrypt or argon2 password hash. The storage columns of
a model using `HasDynamicContents` (`components`, `shared_components`, `entity_id`, `presettable_id`) are the exception:
they are in `$hidden` only because the model's serialization flattens them into fields, they hold the record's
editorial values, and they stay visible in its history, except an anonymous-hidden one for the anonymous user.

### Media projection and signed URLs

On the `api` guard a `Media` record is serialized as a projection, as an entity, as a relation or as the `cover` of its
owner: `id`, `collection_name`, `mime_type`, `size`, `width`, `height`, `alt_text`, `url` and a `conversions` map
(name to URL). `custom_properties`, `disk`, `conversions_disk` and the original `file_name` are never returned. `alt_text`
comes from `custom_properties.alt_text` and the dimensions from `custom_properties.metadata.width/height`.

A caller holding `vend_media.update` on the `api` guard (within its token's abilities) gets the full record. `/app` and
the `web` guard are unchanged, and so is code running off the `api` guard with no acting user (console, queue, search
indexing). On the `api` guard a request with no authenticated user still receives the projection.

URLs come from `Media\MediaUrls::safeUrl()`: a disk configured with `visibility` `public` gives its plain URL; a disk
whose driver supports temporary URLs (private S3, or a local disk with `serve` on) gives a temporary URL valid for
`core.media.signed_url_ttl_minutes` (default `60`); any other disk, and a missing one, gives `null`.

Media are readable through `select/core/media` as well as search. Their visibility is the ACL of the `api` guest (media
of published CMS contents only, through a relation filter), so a SAO ticket attachment or a media of an unpublished
content is never public.

### Appended relations (`cover`)

A model that implements `Contracts\IPreloadsAppendedRelations` names the relations its appended attributes read. The CRUD
read adds them to the query once per result set, narrowed by the related permission and ACL, instead of one lazy load per
row; when the caller may not read the related entity the appended attribute is `null`. CMS contents, contributors and
categories use it for `cover` (the first media of the `cover` collection).

## Tokens

`Modules\Core\Models\User` holds Sanctum tokens (`HasApiTokens`). `personal_access_tokens` has `allowed_cidrs` (json,
nullable).

- **Abilities** are `api` permission names (`default.cms_contents.select`). The wildcard `*` is refused. A request passes
  when the user holds the permission on the `api` guard (through a role, an ancestor role or directly) **and** the token
  carries it as an ability. Both are evaluated on every request, so revoking the permission from the role denies the next
  request even for a token issued earlier. The model events and scopes that ask about the acting user (the per-row
  select check, the write checks, the write-time approval credit, the hiding of records pending deletion) ask the
  same way, through `AuthorizationService::userHolds()`: a token without the `approve` ability gives its owner no
  approval credit and no sight of records pending deletion, whatever the owner's roles grant.
- **Cached responses** are keyed by the token as well as by the user and roles, so two tokens of one user never share
  a cached response.
- **Expiry.** A personal token always expires, at most `core.api_tokens.max_lifetime_days` (default `365`) from now. A
  service-account token may be non-expiring.
- **Networks.** `allowed_cidrs` is a list of addresses or CIDR networks; empty or null means any address; a list that
  cannot be read refuses every address.
- A superadmin cannot create tokens, and a superadmin token that exists is refused at use. The anonymous account cannot
  have tokens.

### Personal tokens

Session routes under `/app/auth/user/tokens` (the `auth` group; a bearer token never manages tokens):

| Route | Name | Purpose |
|---|---|---|
| `GET /app/auth/user/tokens` | `core.auth.tokens.list` | the caller's tokens: id, name, abilities, `allowed_cidrs`, `last_used_at`, expiry. Never the token. |
| `POST /app/auth/user/tokens` | `core.auth.tokens.create` | body `name`, `abilities[]`, `expires_at`, optional `allowed_cidrs[]`. The plain token is in the `201` response only (`Cache-Control: no-store`). |
| `DELETE /app/auth/user/tokens/{token}` | `core.auth.tokens.revoke` | revokes one of the caller's tokens; the next `/api` call with it is `401`. |

Abilities outside the `api` permissions the user holds, `*`, a missing or past expiry, or an expiry beyond the maximum
life are `422`.

### Service accounts

`users.is_service_account` (boolean, default false) marks a non-interactive user. A service account has no usable
password, cannot sign in (refused by `Auth\Concerns\ValidatesUserAccount`, which the Fortify credentials, Socialite and
passkey sign-in paths go through, and by `AuthenticationService`), cannot open a Filament panel, cannot be impersonated, and authenticates with tokens only. Administrators create service accounts, assign their
`api` roles, and issue and revoke their tokens (non-expiring allowed) in the Filament user resource, where the token list
shows `last_used_at`, expiry and networks.

The Core seeder creates the system user (`permission.users.system`) as a service account, and flags it on a re-seed
when it was created before. It is the actor of system-level operations such as the AI moderation votes and never signs
in to a panel. The anonymous user is not a service account: a service account cannot take the guest identity, and the
backoffice refuses the flag on an account named like the guest.

## Operator checklist

1. Turn the API on only when a consumer needs it: the `expose_api` setting (Filament > Settings). It also gates ERP
   payment and e-invoice callbacks, SAO webhooks and MES machine data.
2. Review what the `api` guest and any `api` role you created can read; each role's grants and ACLs are the only exposure.
3. Set `core.api.rate_limit_per_minute`, `core.api_tokens.max_lifetime_days` and `core.media.signed_url_ttl_minutes` if
   the defaults do not fit (config keys, no env variable). The aggregate route has its own ceilings and rate, set by
   the `CORE_AGGREGATE_*` environment variables (see the Core README).
4. After pulling this behaviour into an existing installation, rebuild it with `php artisan migrate:fresh --seed`: there is
   no migration of roles or permissions, and re-seeding never revokes grants. An installation that is only re-seeded keeps
   the old `guest` role on `web` with its `select` permissions, which no longer matter to `/api` but still let an
   anonymous `/app` session read those entities. The anonymous user is cached (`anonymous_user.{name}`): clear the cache
   after seeding if it was cached before its roles existed.
5. CMS search indexes need a rebuild (see the CMS README).

## FAQ prompts for RAG

- Why does `/api/v1` answer 403 on every route?
- Why does a request with an invalid token answer 401 instead of acting as anonymous?
- Which permissions does the anonymous user hold on `/api`?
- What is the difference between a 401 and a 403 on `/api`?
- How do I give an integration read access to some entities only?
- Why can a token not use a permission its role holds?
- How do I issue a non-expiring token for an integration?
- Why does a client `method` column answer 400?
- What does `meta.relations.media.hidden` mean?
- Why does a media come back without `custom_properties` on the API?
- Why does a facet, sort or filter on `user_id` answer 400 to the anonymous user?
- Why does a server error say only "Internal Server Error"?
- Why does an aggregate answer 422 `UNREADABLE_FIELD` where a facet answers 400?
- Why does an aggregate answer 500 with "The aggregate could not be computed." and no detail?
- Why does a filter `{"scope": "active"}` answer 422 in a request but work in an ACL?
- Why does a read answer 403 after a model scope was renamed?
- Why can a search page hold fewer records than requested when an ACL uses a scope?
