---
module: core
audience: developer
---
# Cache membership labels: store a value, invalidate it by label

Core caches through `Modules\Core\Contracts\ICacheLabelIndex`. A value is stored under a plain key; the labels it
carries are memberships used only to find and delete it when something it depends on changes. Laravel cache tags are
not used for this, because with tags the tag set is part of the key: you need every tag to read or forget an entry, and
a tag cannot be added after the value is computed.

The CRUD response cache is described in `docs/CRUD_SYSTEM.md` ("Response cache"), the ACL resolution cache in
`docs/ACL_SYSTEM.md` ("Caching").

## Label vocabulary

Label strings are built only by `Modules\Core\Cache\Labels\CacheLabel`; never write one by hand.

| Label | Builder | Meaning | Flushed by |
|---|---|---|---|
| `table:{table}` | `CacheLabel::table()` | the value depends on which rows of the table exist or match | any create, update, delete, restore or pivot write on the table, any mass write that flushes it |
| `row:{table}:{id}` | `CacheLabel::row()` | the value embeds the content of that one row | an update, delete or restore of that row |
| `user:{id}` | `CacheLabel::user()` | computed for that user | `Cache::clearByUser()`, a change of the user's roles or direct permissions |
| `role:{id}` | `CacheLabel::role()` | computed for a user holding that role | `Cache::clearByGroup()`, an ACL or permission change of the role or of a role above it |
| `crud` | `CacheLabel::all()` | every cached CRUD response | the recovery of a failover cache |
| `acl` | `CacheLabel::acl()` | every ACL resolution | an ACL write, a grant whose targets are unknown, `AclResolverService::flushCache()` |
| `docs` | `CacheLabel::docs()` | merged documentation files | the swagger generate command |
| `translations` | `CacheLabel::translations()` | the translations payload | `translations:check` |
| `inspector` | `CacheLabel::inspector()` | schema inspector results | the schema commands (`CommandListenerProvider`) |
| `geocoding` | `CacheLabel::geocoding()` | geocoding answers | nothing by default: they expire by TTL, flush the label by hand to drop them |

An update flushes both the table and the row label of its table, because an update can move a row into or out of a
filtered list. A creation flushes only the table label: no cached value can embed a row that did not exist.

## Drivers

The index is picked from the store by `CacheLabelIndexFactory::forStore()` and wrapped by `MeasuredLabelIndex`, which
counts hits, misses and flushes. Callers never branch on the driver.

| Store | Index | Row labels | Behaviour |
|---|---|---|---|
| `redis` | `RedisLabelIndex`: one sorted set per label (score is the expiry), a hash with the last flush time of each label | yes | A flush deletes the entries and their set. A value is not stored when one of its labels was flushed after the lookup started (no stale write-back). Time comes from the Redis server clock. |
| `array` | `ArrayLabelIndex`: in-process twin of the Redis index | yes | Same rules, lives for the process. |
| `file`, `database`, `memcached`, `dynamodb`, `octane` and any other | `VersionCounterLabelIndex`: a counter `labelv:{label}` per label, appended to the key | no | A flush increments counters; entries of older versions are never read and expire by TTL. A row label flush becomes a flush of its table: documented over-invalidation, never staleness. |
| `failover` | `FailoverLabelIndex` around the index of its first store | as the primary | Degraded mode, below. |
| `null` | `NullLabelIndex` | n/a | Nothing is stored; every lookup misses. |

**Failover degraded mode.** While the primary (Redis) throws a connection error, every lookup misses and nothing is
stored, so no response or resolution is cached and nothing is read from the fallback array. Flushes issued during the
outage cannot reach Redis, so the first successful call after it flushes `crud` before doing anything else. The outage
is remembered in a marker on the host-local `file` store, so a later request or worker on the same host also flushes.
A host that never saw a failure may serve entries written before the outage until some host has flushed `crud` (Redis
is shared, one flush clears all). Only connection failures count as an outage; any other exception propagates.

Entries are never stored without an expiry on any index.

## What triggers flushes

- **Eloquent events.** One listener (`FlushCacheLabelsOnModelWrite`) on `created`, `updated`, `deleted`, `restored`
  and `forceDeleted` of every model flushes `table:{table}` and, except for a creation, `row:{table}:{id}`. A model
  needs no trait for this; `HasCache` only opts it into the CRUD response cache.
- **Pivots.** The `BelongsToMany` and `MorphToMany` overrides (`FlushesCacheLabelsOnMutation`) flush after `attach`,
  `detach`, `sync`, `toggle` and `updateExistingPivot`: the tables of the pivot and of both sides, plus the scope
  labels of a grant:
  - roles or permissions of a user: `user:{id}`;
  - permissions of a role, roles of a permission: `role:{id}` of those roles and of every descendant role, and `acl`;
  - users of a role or permission: `user:{id}` of those users, or `acl` when the touched users are unknown (a detach
    of all).
- **ACL writes.** `AclObserver` flushes `acl`, the role labels (with descendants) of the old and new role, or the table
  label of the permission's entity for an unscoped ACL.
- **Commands and helpers.** `Cache::clearByEntity()`, `clearByUser()`, `clearByGroup()`, `HasCache::invalidateCache()`,
  `AclResolverService::clearCacheFor*()` and `flushCache()`, `translations:check`, the schema commands.
- **After commit.** When the owning model has an open transaction, `CacheLabelInvalidator` defers the flush to
  `afterCommit`, so a reader cannot re-cache old rows between the flush and the commit. With no transaction it flushes
  at once.

## Writing outside Eloquent events

Mass `Model::query()->update()`, `delete()`, `insert()`, `upsert()`, raw `DB::` statements and `newPivotQuery()` fire no
model event and no relation override. A module that does this must flush what it wrote, once per batch or run, not
per row:

```php
use Modules\Core\Cache\Labels\CacheLabelInvalidator;

$invalidator = resolve(CacheLabelInvalidator::class);

// $owner is any model of the connection that holds the transaction.
$invalidator->flushTable('cms_contents', $owner);          // table:cms_contents
$invalidator->flushRecord($model);                          // table and row of one model
$invalidator->flushLabels($owner, CacheLabel::table('cms_tags'), CacheLabel::user(5));
```

Rules:

- Pass an owner model: the flush then waits for the commit of its connection's open transaction. Without an owner it
  runs immediately, which is only right outside a transaction.
- Flush every table the write touched, pivots included (the table of a relation is `$model->relation()->getTable()`).
- Flush at the end of the run. A failed run is worth flushing too: a flush that was not needed only costs a miss.
- The CMS import is the reference: `ImportPipeline::flushWrittenTables()`.

When a pivot is changed by a raw query, use `flushPivot($relation, $related_keys)` instead of flushing tables by hand if
the pivot is a grant of roles or permissions, so the scope labels are flushed too.

## Caching a value of your own

Resolve the index from the container (it is a singleton) and follow lookup, compute, store:

```php
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;

$index = resolve(ICacheLabelIndex::class);

$lookup = $index->lookup(
    'my-module:report:' . $id,
    new CacheDependencies([CacheLabel::table('cms_contents')], row_tables: ['core_users']),
);

if ($lookup->hit) {
    return $lookup->value;
}

$value = $this->compute();

// The ttl is in seconds and required; a value is never kept forever.
$index->store($lookup, $value, 300, [CacheLabel::row('core_users', $author->getKey())]);
```

Notes:

- `labels` are known before the query. `row_tables` names tables whose row labels the entry may carry; on a version
  counter store they become table labels. The `row_labels` argument of `store()` carries the rows known only after the
  query; stores without row labels ignore it.
- `store()` returns false and stores nothing when a label of the entry was flushed after the lookup started; do not
  retry it.
- The key you pass is not the key stored on a version counter store: use `$lookup->key` for `forget()`:
  `$index->forget($lookup)`.
- Set `complete: false` in `CacheDependencies` when you cannot name everything the value depends on; such a value must
  not be stored, so skip the store.
- Cache only what is the same for everyone the key serves. Anything that depends on the caller carries the `user:{id}`
  and `role:{id}` labels and has them in the key.

## Commands

- `cache:prune-labels`: removes expired entries and memberships from the label sets. It is scheduled hourly with
  `onOneServer()` by `CoreServiceProvider`. It does nothing on version counter stores, where entries expire by TTL.
- `cache:label-stats`: a table of hits, misses (counted per first table label of the entry) and flushes per label,
  read from the stats counters. Use it to decide whether a coarse table label deserves a narrower one.
- The cron list is warmed by `cache:warm` under the table label of the cron table through the same index.

## Known limits

- A role whose parent changes, or a role that is deleted, does not flush the `acl` resolutions that were computed
  through it: they follow the change at the end of their TTL (1 hour). The CRUD responses are not affected, their key
  holds the caller's roles.
- With `permission.teams` enabled, Spatie's `removeRole()` and `revokePermissionTo()` on a user bypass the relation
  detach and so the flush. Teams are off in Laraplate; enabling them needs a flush added there first.
- History reads are cached with the label of the requested record and the set labels of the versions and modifications
  tables; a write to those tables that bypasses Eloquent events must flush them as any other.
- Search is never cached (see `CRUD_SYSTEM.md`), so it needs no flush on reindex.
- Stores without sets (`file`, `database`, `memcached`) over-invalidate: a change to one row flushes every response of
  its table, and old entries stay until their TTL.
- A flush that failed on a Redis outage is lost; the failover wrapper covers it by flushing `crud` on recovery, but the
  other labels (`acl`, `docs`, ...) are covered only by their TTL.
