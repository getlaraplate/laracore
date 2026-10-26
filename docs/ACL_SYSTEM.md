# ACL (Access Control List) System

## Overview

The ACL system provides **row-level security** (RLS) for the application, allowing fine-grained control over which records a user can access based on their roles and permissions.

## Key Concepts

### Permissions vs ACLs

| Component | Purpose | Scope |
|-----------|---------|-------|
| **Permission** | "Can user do this operation?" | Operation-level (SELECT, INSERT, UPDATE, DELETE) |
| **ACL** | "On which records?" | Row-level (filters applied to queries) |

### Example

```
Permission: default.orders.select
  └── "Can user SELECT from orders table?" → Yes/No

ACL attached to permission:
  └── "Which orders can they see?" → filters: [department_id = 5, status = 'active']
```

## Architecture

### Service Architecture

```mermaid
flowchart TD
    auth["AuthorizationService<br/>checkPermission(): can the user do this operation?<br/>ensurePermission(): check or throw an exception<br/>getAclFilters(): get the ACL filters for a permission<br/>injectAclFilters(): inject ACL into the request filters<br/>applyAclFiltersToQuery(): apply ACL directly to the query"]
    resolver["AclResolverService<br/>getEffectiveAcls(): resolve ACLs for user and permission<br/>getCombinedFilters(): combine ACLs with OR logic<br/>hasUnrestrictedAccess(): check whether the user has no restrictions"]
    qb["QueryBuilder<br/>prepareQuery(): build the query from request data<br/>applyFilters(): apply a FiltersGroup to the query"]
    crud["CrudService<br/>orchestrates AuthorizationService and QueryBuilder<br/>1. ensurePermission(): check permission<br/>2. injectAclFilters(): inject ACL into the request<br/>3. prepareQuery(): build the query<br/>4. execute and return: run the query, return the result"]
    auth --> resolver --> qb --> crud
```

### Data Model

```mermaid
erDiagram
    USER }o--o{ ROLE : "has roles"
    ROLE |o--o{ ROLE : "parent_id hierarchy"
    ROLE }o--o{ PERMISSION : "grants, inherited from parent roles"
    PERMISSION ||--o{ ACL : "row-level filters"
    ACL {
        json filters "JSON query builder"
        boolean unrestricted
        smallint priority
        boolean enabled
    }
```

### Database Schema

**Table: `acls`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `permission_id` | bigint | FK to permissions table |
| `filters` | json | Query builder filters (FiltersGroup) |
| `sort` | json | Optional default sorting |
| `description` | varchar(255) | Human-readable description |
| `unrestricted` | boolean | If true, no filters applied (full access) |
| `priority` | smallint | Higher priority evaluated first (default: 0) |
| `enabled` | boolean | If false, ACL is ignored (default: true) |
| `created_at` | timestamp | Creation timestamp |
| `updated_at` | timestamp | Last update timestamp |
| `deleted_at` | timestamp | Soft delete timestamp |

## Resolution Logic

The `AclResolverService` implements the following resolution rules:

### 1. Permission Inheritance (Additive)

Permissions follow **additive** inheritance through role hierarchy:

```
guest (root)
  └── permissions: [orders.select]
        │
        └── editor (child of guest)
              └── permissions: [orders.select (inherited), orders.insert]
                    │
                    └── admin (child of editor)
                          └── permissions: [all inherited + orders.delete]
```

**Rule**: Child roles inherit ALL permissions from parent roles.

### 2. ACL Resolution (Override)

ACLs follow **override** logic - the most specific wins:

```
guest
  └── Permission: orders.select
        └── ACL: { filters: [status = 'published'] }

editor (child of guest, NO ACL defined)
  └── Inherits ACL from guest → sees only 'published'

admin (child of editor)
  └── ACL: { unrestricted: true }
  └── Sees EVERYTHING (overrides parent)
```

**Rules**:
1. If role has ACL for permission → **use it** (overrides parent)
2. If role has NO ACL → **inherit from parent** role
3. If `unrestricted = true` → ACL is **transparent** (doesn't contribute filters to the query)

### 3. Unrestricted ACLs (Transparent)

ACLs with `unrestricted = true` are **transparent** - they don't contribute filters to the query.
This is useful for breaking inheritance without imposing new restrictions.

```
User "mario"
  ├── Role: sales (ACL: country = 'IT')           → contributes filter
  └── Role: supervisor (ACL: unrestricted = true) → does NOT contribute

Effective filter: country = 'IT'
Mario sees only Italian records.
```

If ALL ACLs are unrestricted (or no ACLs exist), user sees everything:

```
User "admin_user"
  └── Role: admin (ACL: unrestricted = true) → does NOT contribute

Effective filter: NONE
Admin sees all records.
```

**Key point**: `unrestricted = true` does NOT mean "ignore all filters". It means "this role branch doesn't add any restrictions". Other roles' filters still apply.

### 4. Multiple Roles (OR Union)

When a user has multiple non-hierarchical roles with filters, they are combined with **OR**:

```
User "mario"
  ├── Role: sales_it (ACL: country = 'IT')  → contributes
  └── Role: sales_de (ACL: country = 'DE')  → contributes

Effective filter: country = 'IT' OR country = 'DE'
Mario sees records from BOTH countries.
```

### 5. SuperAdmin Bypass

Users with the `superadmin` role bypass ALL ACL filters automatically.

A superadmin's permission list (`User::getPermissionsViaRoles()`, the `permissions` of the user info response) is every permission, read from Spatie's `PermissionRegistrar` cache and sorted by name. Spatie flushes that cache on every permission or role write, so no query runs per call.

## ACL Filter Injection

When ACL filters are injected into a request, they wrap the existing user filters:

```
Before injection:
  requestData->filters = user_filters

After injection:
  requestData->filters = {
    operator: AND,
    filters: [
      acl_filters,     // ACL constraints (user cannot bypass these)
      user_filters     // Original user filters (if any)
    ]
  }
```

This ensures that:
1. **Users cannot bypass ACL restrictions** - ACL filters are always applied
2. **User filters are additive** - Users can further restrict their view, but never expand it
3. **Filters are processed together** - Single query execution, no N+1 issues

### Example

```php
// User with ACL: department_id = 5
// User requests: status = 'active'

// Result query:
// WHERE (department_id = 5) AND (status = 'active')
```

## Filter Format

Filters use the same format as the CRUD query builder:

```json
{
  "filters": [
    {
      "property": "status",
      "operator": "=",
      "value": "active"
    },
    {
      "property": "department_id",
      "operator": "in",
      "value": [1, 2, 3]
    }
  ],
  "operator": "and"
}
```

### Supported Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `=` | Equals | `status = 'active'` |
| `!=` | Not equals | `status != 'deleted'` |
| `>` | Greater than | `amount > 100` |
| `>=` | Greater than or equal | `amount >= 100` |
| `<` | Less than | `amount < 100` |
| `<=` | Less than or equal | `amount <= 100` |
| `like` | LIKE pattern | `name like '%John%'` |
| `not like` | NOT LIKE pattern | `name not like '%test%'` |
| `in` | IN list | `status in ['active', 'pending']` |
| `between` | BETWEEN range | `created_at between ['2024-01-01', '2024-12-31']` |

### Nested Filters

Filters can be nested with AND/OR operators:

```json
{
  "filters": [
    {
      "property": "status",
      "operator": "=",
      "value": "active"
    },
    {
      "filters": [
        {
          "property": "department_id",
          "operator": "=",
          "value": 1
        },
        {
          "property": "region",
          "operator": "=",
          "value": "north"
        }
      ],
      "operator": "or"
    }
  ],
  "operator": "and"
}
```

Result: `status = 'active' AND (department_id = 1 OR region = 'north')`

### Relation Filters

An ACL can restrict a record by a condition on a **related record** (a `BelongsTo`, or a `MorphTo` with the types it accepts). A relation node carries the relation name, an optional `morph_types` list and a nested filters group evaluated on the related record, with the same operators and dynamic values (`@now`, `@today`, `@user.<attribute>`):

```json
{
  "filters": [
    {
      "relation": "model",
      "morph_types": ["Modules\\CMS\\Models\\Content"],
      "filters": {
        "filters": [
          { "property": "valid_from", "operator": "<=", "value": "@now" }
        ],
        "operator": "and"
      }
    }
  ],
  "operator": "and"
}
```

Result on `vend_media`: only media whose owner is a content valid at the time of the request. A relation node is a `whereHas`, or a `whereHasMorph` when `morph_types` is set (a media owned by any other type never matches).

- Only ACLs build relation nodes: filters sent in a request are parsed as column filters and cannot contain one.
- In search the engine receives column conditions only. A relation node is not pushed to the engine (inside an OR group the whole group is left out, so the engine never drops what the relation allows); the records behind the hits are reloaded with the ACL applied, which is where the relation condition takes effect.
- The ACL form edits the JSON; the validation rule accepts a relation node whose `filters` is a group.
- At save, `Rules\AclRelationFilters` checks what the node names: the relation must be a relation method of the entity the ACL's permission is about (nested relation nodes are checked against the related entity), a `MorphTo` needs `morph_types` and any other relation must not carry them, and every morph type must be a model class or morph alias. A failing check rejects the ACL.

#### Strict reading and failing closed

An ACL must never widen because of something it cannot read, so the cast that loads the `filters` JSON (`FiltersGroupCast`) refuses what it does not understand instead of dropping it:

- A node is exactly one kind with exactly its keys: a relation node (`relation`, `filters`, `morph_types`), a scope node (`scope`, `parameters`), a filter (`property`, `operator`, `value`) or a group (`operator`, `filters`). An unknown key, or keys of two kinds in one node, throws.
- The items of a group must be filters, groups, relation nodes or scope nodes (objects), the `filters` of a relation node must be a group (an object, not a bare list) that holds at least one condition, and `morph_types` must be a non-empty list.
- Undecodable JSON, or a value that is neither an object nor a list, throws. The ACL then fails to load and the request fails: it never runs unfiltered.
- A stored group that constrains nothing (empty, or made of empty groups) throws as well, because it would match every row. The `unrestricted` flag is the exemption: an ACL flagged `unrestricted` may store an empty group (it contributes no filters by design). A SQL `NULL`, an empty `filters` column or a stored JSON literal `null` keeps meaning "no filters".
- At query time every relation name goes through `Support\RelationGuard` (a public, non-static method with no required parameter and a declared `Relation` return type) before it is called, and a morph relation must name its morph types, never the wildcard. A filter that fails these checks throws, so the query fails closed.
- The strict reading runs when an ACL is saved (`AclRelationFilters` hydrates the filters through the cast, so an unreadable shape is rejected) and every time it is loaded. The `Rules\QueryBuilder` shape rule shared with `ListRequest` keeps its looser contract for request filters.

#### Relation filters in search

A relation node cannot be pushed to the search engine. The records behind the hits are reloaded from the database with the ACL applied (`CrudService::searchHitsQuery()`), which is where the relation condition takes effect. Consequences: a search page can hold fewer records than requested, and the search `totalRecords` is the engine's count, so it can count hits the caller may not see. A model that implements `IAuthorizesSearchRehydration` (media) adds its own guard on top.

#### ACLs per guard

An ACL is attached to a permission, and a permission belongs to one guard. The default ACLs for the anonymous reader sit on the `guest` role of the `api` guard (published contents and their references and media, public settings); the `guest` of `web` holds no permission. Role-scoped ACLs follow the role's guard like the permission they filter. See [rag/API_ACCESS_CONTROL.md](./rag/API_ACCESS_CONTROL.md).

#### ACLs of related records and parts

A related record loaded as a relation is narrowed by the ACL of its own entity, as a root query is. A **part of its parent** (a model implementing `Contracts\IsPartOfParent`: translations, document lines, CMS references and ratings) loaded from that parent inherits the parent's visibility and is not narrowed: an installation's own ACL on a part's permission applies only when the part is read directly, on its own endpoint, or reached from another model (then it is checked against its parent's permission and ACL).

#### ACL filters and readable columns

An ACL may filter on any column, including one the caller never receives (a column hidden from the anonymous user, a media column outside the projection): it names columns on its own authority. The filters, sorts, group-by keys and facet fields a request sends may not (`400`): they are checked before the ACL filters are merged in. See [CRUD_SYSTEM.md](./CRUD_SYSTEM.md#columns-returned).

### Scope Filters

A filter can name a **local scope of the filtered model** instead of repeating its conditions column by column. A scope node is `{"scope": "<name>", "parameters": [...]}`; `parameters` is optional and, when present, a list (the arguments are positional, after the query builder). The node has exactly these two keys and a non-empty `scope`; anything else is refused by the cast, as for the other nodes.

```json
{
  "filters": [
    { "scope": "valid" },
    { "scope": "active" },
    { "scope": "expiring", "parameters": [48] }
  ],
  "operator": "and"
}
```

The anonymous reader's category ACL is `valid` + `active` + a relation filter on the CMS entity type: when the model changes what "valid" means, the ACL follows it instead of keeping a stale copy of the window.

#### Declaring a scope

A scope is usable as a filter only when its method carries `#[Modules\Core\Attributes\FilterScope]`, on a `#[Scope]` method or a legacy `scopeXxx` method (it must not be private). The name and the parameter types come from the method signature, read by reflection once per model class (`Support\ScopeGuard`), so the declaration cannot drift from the code. A scope declared in a trait is declared for every model using the trait.

- `#[FilterScope]` is **ACL-only**; `#[FilterScope(client: true)]` also allows CRUD requests (see [CRUD_SYSTEM.md](./CRUD_SYSTEM.md#scope-filters-in-requests)). A scope may read columns a client cannot filter on (hidden or anonymous-hidden columns) and would let a client probe their values; ACLs are written by administrators and do not carry that risk. Expose a scope to clients only when the columns it reads are already serialized and it reads no relation: a scope applied from a client request may hold no subquery (`whereHas`, `has`, `whereExists`, `whereIn` or a comparison with a subquery) and no raw SQL, because the rows it reaches that way are never checked against their own ACL. Such a scope is refused with a `422`; in an ACL it keeps its subqueries.
- A module declares its own by adding the attribute to its scope method, or by putting the attribute on a scope of a trait it shares. Core needs no change and no registry.
- Declared in v1: `HasValidity`, with `client: true` (its columns are serialized): `valid`, `published`, `draft`, `scheduled`, `expired`, `validAt(date)`, `expiredAt(date)` and `expiring(hours)`. `HasActivation`, ACL-only because the activation column is hidden from serialization: `active`, `inactive`. Not declared: `validityOrdered` (it orders) and `withValidityFilter` (a duplicate of `validAt`).
- A model that **overrides** a trait scope without repeating the attribute undeclares it for itself: the declaration belongs to the method that wins. `License` overrides `expired` and so does not offer it as a filter. Put the attribute on the overriding method if the scope must stay usable.
- Only local scopes can be named. A signature a filter cannot carry (no query builder, a variadic, by-reference, untyped, union or non-scalar non-date parameter, or a date class that is not a `Carbon` supertype such as `CarbonImmutable`) is refused when the node is used, naming the parameter.

#### Parameters and placeholders

- Parameters are scalars (`string`, `int`, `float`, `bool`, `null`) and dates. Arrays and objects are refused, a missing required parameter or an extra one is refused, and a parameter with a default may be left out.
- Values are checked against the declared type. `string` is strict: an integer is refused for a string parameter. An `int` or `float` also accepts its numeric string, a `bool` the usual boolean spellings, `null` only a nullable parameter.
- A date parameter is an ISO 8601 string (a calendar date, optionally with time and offset) turned into an `Illuminate\Support\Carbon`. A parameter typed `CarbonInterface`, `Carbon\Carbon`, `DateTimeInterface` or `DateTime` receives that `Illuminate\Support\Carbon` (any type it satisfies is accepted); `CarbonImmutable` and other classes are not supported.
- In an ACL, a parameter accepts the placeholders of a filter value: `@now` and `@today` **only in a date position**, `@user.<attribute>` in any scalar or date position (its value is typed when the scope is used). They are resolved when the ACL is applied.
- At save, a placeholder cannot be resolved, so the purity run below uses the parameter's default or, when it has none, a sample of its declared type. A scope that is impure only on a branch the default does not take (say, an `if` on the argument) passes at save and is refused only when used.
- Client requests get no placeholders: a parameter is taken literally, so `@now` is refused for a date parameter.

#### Purity

A scope runs inside its **own nested `where`** (`where(fn ($q) => $q->scope(...))`), in AND and OR groups alike, so its conditions never leak out of their group. After the call the nested query may hold only `where` conditions: joins, orders, selected columns, distinct, groups, havings, limits, offsets, unions, aggregates, locks, eager loads, query callbacks, a global scope added on the nested query, or a returned builder other than the one it was given, refuse the filter. A scope that does more than narrow rows cannot be a filter even if declared.

A scope run that adds **no condition** is refused too ("adds no condition"): an empty nested `where` is dropped from the query, so the node would match every row. This is the `when($id, ...)` idiom with a null parameter, for instance an `@user.<attribute>` that does not resolve: the save is refused when the default or sample run adds nothing, and otherwise the read is denied when it is used.

A scope node only adds conditions. It **never removes or negates a global scope** of the filtered model, which stays applied as the model decides (a scope that calls `withoutGlobalScope` acts on its own nested query, whose global scopes are never applied: `validAt` does it, and the outer query keeps its own). There is no `not`: the opposite of a scope is declared as its own scope (`active` / `inactive`).

#### Relation filters

A scope node inside a relation node is resolved and checked against the **related** model, and against each morph type of a morph relation. `{"relation": "model", "morph_types": ["Modules\\CMS\\Models\\Content"], "filters": {"filters": [{"scope": "valid"}]}}` narrows the media to those of a valid content.

#### Errors: fail closed

| When | Undeclared, impure or badly parameterised scope |
|---|---|
| ACL save (Filament, CRUD on `core_acls`) | Validation error on `filters` naming the scope and the reason (`Rules\AclScopeFilters`, with `AclRelationFilters` for the relations). The Filament form shows it. |
| ACL use (CRUD read, ACL-checked read, search rehydration, media owner lookup, the application content retrieval of the assistant) | The read is denied (`AuthorizationException`: `403` for an authenticated principal, `401` for the anonymous user) and `Support\AclScopeCheck` logs a warning with the ACL id, the permission, the scope and the reason, never a parameter value. The application content retrieval of the assistant turns the denial into `ApplicationContentUnavailableException`: the content is reported unavailable, not as a `403` / `401`, and the warning is still logged. Every walker of the tree refuses a node kind it does not know instead of skipping it. |
| CRUD request | `422`, see [CRUD_SYSTEM.md](./CRUD_SYSTEM.md#scope-filters-in-requests). |

The check at use runs each scope node on a throwaway query, with its placeholders resolved, before the real query is built.

#### Scope filters in search

A search engine never receives a scope node. It is left out of the engine filters exactly like a relation filter (dropped from an AND group, the whole OR group left out) and enforced when the hits are reloaded from the database. The database engine does not apply a scope in its own SQL either: it takes no scope node, and the scope is enforced at the reload. Consequences, the same as for relation filters: a search page can hold fewer records than requested, and the search `totalRecords` is the engine's count, so it can count hits the caller may not see. An OR group that holds a scope and also column conditions is re-applied at the reload with the database's rules, so a field that only the index knows (an index-only field) inside such a group is evaluated by the database: the results can be narrower than the engine's, or, on a model without dynamic contents where that field is not a column, the reload fails with a server error (`500`, whose SQL message reaches the client only with `app.debug`). Never wider: it fails closed. That is why the CMS keeps the anonymous contents ACL on columns (see `Modules/CMS/README.md`): a scope in it would skew the totals and page sizes of every anonymous content search.

## Usage

### Automatic Integration (CrudService)

ACL filters are automatically applied in `CrudService` for all read operations:
- `list()` - List records (ACL injected into request filters)
- `detail()` - Get single record (ACL applied to query)
- `history()` - Get record history (ACL applied to query)
- `tree()` - Get hierarchical records (ACL applied to query)

The flow for `list()` operations:
1. **Check permission** - Verify user can perform the operation
2. **Inject ACL filters** - Modify `requestData->filters` to include ACL constraints
3. **Build query** - QueryBuilder applies all filters (including ACLs)
4. **Execute** - Run query and return results

```php
// In CrudService::list()
$permission_name = $this->auth->ensurePermission($request, 'orders', 'select');
$this->auth->injectAclFilters($requestData, $permission_name);  // Modifies filters
$this->query_builder->prepareQuery($query, $requestData);       // ACL now included
```

### Manual Integration

For custom queries, use `AuthorizationService`:

```php
use Modules\Core\Services\Authorization\AuthorizationService;

$auth = app(AuthorizationService::class);

// Option 1: Inject into request (for ListRequestData)
$auth->injectAclFilters($requestData, 'default.orders.select');
// Now $requestData->filters includes ACL constraints

// Option 2: Apply directly to query (for custom queries)
$query = Order::query();
$auth->applyAclFiltersToQuery($query, 'default.orders.select');
$orders = $query->get();
```

### Direct AclResolver Usage

For advanced scenarios, use `AclResolverService` directly:

```php
use Modules\Core\Services\AclResolverService;
use Modules\Core\Models\Permission;

$resolver = app(AclResolverService::class);
$permission = Permission::findByName('default.orders.select');

// Get all effective ACLs for user
$acls = $resolver->getEffectiveAcls($user, $permission);

// Get combined filters (returns FiltersGroup or null)
$filters = $resolver->getCombinedFilters($user, $permission);

// Check if user has unrestricted access
$hasFullAccess = $resolver->hasUnrestrictedAccess($user, $permission);
```


## Configuration Examples

### Scenario 1: Department-Based Access

```
Role: sales_dept_1
  └── Permission: default.orders.select
        └── ACL: { filters: [department_id = 1] }

Role: sales_dept_2
  └── Permission: default.orders.select
        └── ACL: { filters: [department_id = 2] }
```

### Scenario 2: Status-Based Access

```
Role: guest
  └── Permission: default.articles.select
        └── ACL: { filters: [status = 'published'] }

Role: editor (child of guest)
  └── Permission: default.articles.select
        └── ACL: { filters: [status IN ('published', 'draft')] }

Role: admin (child of editor)
  └── Permission: default.articles.select
        └── ACL: { unrestricted: true }
```

### Scenario 3: Owner-Based Access

For user-specific access (e.g., "see only your own records"), you would need to implement dynamic filter resolution. This is a planned feature.

```json
{
  "filters": [
    {
      "property": "created_by",
      "operator": "=",
      "value": "{user.id}"
    }
  ]
}
```

> **Note**: Dynamic placeholders like `{user.id}` are not yet implemented. This is documented as a future enhancement.

## Caching

ACL resolutions are cached for performance:

- **Cache key**: `acl:resolved:user:{user_id}:perm:{permission_id}`
- **TTL**: 1 hour (3600 seconds)

### Cache Invalidation

Cache is cleared when:
- User roles change
- ACL records are modified
- Permissions are modified

Use these methods to manually clear cache:

```php
$resolver->clearCacheForUser($user);
$resolver->clearCacheForPermission($permission);
```

## Best Practices

### 1. Design ACLs from Least to Most Permissive

Start with restrictive ACLs at the root role, then expand access down the hierarchy:

```
guest → sees only published
  └── user → sees published + own drafts
        └── editor → sees all statuses
              └── admin → unrestricted
```

### 2. Use `unrestricted` Explicitly

When you want a role to have full access, set `unrestricted: true` explicitly rather than leaving filters empty. This makes the intent clear.

### 3. Document ACL Descriptions

Always fill the `description` field with a human-readable explanation:

```json
{
  "description": "Sales team can only see orders from their assigned region",
  "filters": [...]
}
```

### 4. Use Priority for Conflict Resolution

When multiple ACLs could apply, use `priority` to ensure deterministic behavior:

```
ACL 1: priority = 10, filters = [status = 'active']
ACL 2: priority = 20, filters = [status = 'pending']  ← This wins
```

### 5. Test ACL Logic

Write tests to verify ACL behavior:

```php
it('sales user can only see own department orders', function () {
    $sales_user = User::factory()->create();
    $sales_user->assignRole('sales_dept_1');
    
    actingAs($sales_user);
    
    $response = $this->getJson('/api/v1/select/orders');
    
    $response->assertOk();
    $orders = $response->json('data');
    
    // All orders should be from department 1
    foreach ($orders as $order) {
        expect($order['department_id'])->toBe(1);
    }
});
```

## File Structure

### Services

| File | Purpose |
|------|---------|
| `Services/Authorization/AuthorizationService.php` | Permission checks, ACL injection, query filtering |
| `Services/AclResolverService.php` | Resolves effective ACLs for user/permission with inheritance |
| `Services/Crud/QueryBuilder.php` | Builds Eloquent queries from request data |
| `Services/Crud/CrudService.php` | Orchestrates CRUD operations |

### Models

| File | Purpose |
|------|---------|
| `Models/ACL.php` | ACL model with filters, unrestricted flag, priority |
| `Models/Permission.php` | Spatie permission extended with ACL relationship |
| `Models/Role.php` | Hierarchical role with recursive relationships |

### Migrations

| File | Purpose |
|------|---------|
| `migrations/2025_02_25_225037_make_acl_table.php` | Creates `acls` table |
| `migrations/2026_01_29_100000_add_acl_inheritance_fields.php` | Adds `unrestricted`, `priority`, `enabled` |

## Future Enhancements

1. **Dynamic Placeholders**: Support for `{user.id}`, `{user.department_id}` in filter values
2. **Named Scopes**: Ability to reference Eloquent scopes in ACL configuration
3. **Time-Based ACLs**: ACLs that are only active during certain time periods
4. **Audit Logging**: Track when ACL filters are applied and their impact
5. **Filament UI**: Admin interface for managing ACLs visually

## Related Documentation

- [Permission System](./PERMISSIONS.md)
- [Role Hierarchy](./ROLES.md)
- [CRUD Operations](./CRUD.md)
