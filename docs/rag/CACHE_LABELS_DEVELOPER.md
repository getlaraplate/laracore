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
| `row:{table}:{id}` | `CacheLabel::row()` | the value embeds the content of that one row | an update, delete, restore or force delete of that row |
| `rows:{table}` | `CacheLabel::rows()` | the value holds some row of the table (added by the index for every row table and row label) | a whole-table flush (`flushTable()`), used for writes that fire no model event |
| `user:{id}` | `CacheLabel::user()` | computed for that user | `Cache::clearByUser()`, a change of the user's roles or direct permissions |
| `role:{id}` | `CacheLabel::role()` | computed for a user holding that role | `Cache::clearByGroup()`, an ACL or permission change of the role or of a role above it, a change of its parent, its move to the bin, restore or deletion, or that of a role above it |
| `crud` | `CacheLabel::all()` | every cached CRUD response | a write of an ACL with no role |
| `stored` | `CacheLabel::stored()` | every entry stored through a guarded (Redis) index, added by `FailoverLabelIndex` | the recovery of the Redis index after an outage |
| `acl` | `CacheLabel::acl()` | every ACL resolution | an ACL write, a grant whose targets are unknown, a change of the role hierarchy, `AclResolverService::flushCache()` |
| `docs` | `CacheLabel::docs()` | merged documentation files | the swagger generate command |
| `translations` | `CacheLabel::translations()` | the translations payload | `translations:check` |
| `inspector` | `CacheLabel::inspector()` | schema inspector results | the schema commands (`CommandListenerProvider`) |
| `geocoding` | `CacheLabel::geocoding()` | geocoding answers | nothing by default: they expire by TTL, flush the label by hand to drop them |
| `dynamic_contents` | `CacheLabel::dynamicContents()` | the entity, preset and presettable lists of `DynamicContentsService` | a bump of their metadata generation (`DynamicContentMetadataObserver`, `FieldObserver`, the `clear*Cache()` methods): the generation moves at once, the label is flushed after the commit (or the rollback) of the write that bumped it |
| `models` | `CacheLabel::models()` | model class discovery (`HelpersCache`, the `models()` helper) | the end of a migration (`CommandListenerProvider`), `permission:refresh`, `HelpersCache::clearModels()` |

The settings caches (the groups and the name index of `PerModelSettingResolver`, the site configs) carry
`table:core_settings`, the anonymous user (`AuthorizationService::resolveAnonymousUser()`) the table label of the user
model, the cron list `table:core_cron_jobs`: all for `cache.duration.long`. A write to one setting through the model
reloads every settings group (the coordinator still forgets the group it names).

An update flushes both the table and the row label of its table, because an update can move a row into or out of a
filtered list. A creation flushes only the table label: no cached value can embed a row that did not exist. A detail
response carries the row label of its record and not the table label, so a write that names no row must flush
`rows:{table}` too: that is what `flushTable()` does.

## Drivers

The index is picked from the store by `CacheLabelIndexFactory::forStore()` and wrapped by `MeasuredLabelIndex`, which
counts hits, misses and flushes. Callers never branch on the driver.

| Store | Index | Row labels | Behaviour |
|---|---|---|---|
| `redis` | `FailoverLabelIndex` around `RedisLabelIndex`: one sorted set per label (score is the expiry), one key per label with its last flush time (`labelflush:{label}`), expiring one second after the largest store window in use | yes | A flush is one atomic script when its labels hold up to 1000 members in all: it deletes the entries and removes them from the sets, then records the flush time of every label (deletes first, so a Redis over `maxmemory` with `noeviction`, which refuses a script whose first write is a `SET`, still deletes); labels holding more are paged by further scripts of up to 1000 members each. Redis drops the emptied set. A value is not stored when one of its labels was flushed after the lookup started (no stale write-back), nor when the lookup started more than the store window ago. Time comes from the Redis server clock. Degraded mode, below. |
| `array` | `ArrayLabelIndex`: in-process twin of the Redis index | yes | Same rules, lives for the process. |
| `file`, `database`, `memcached`, `dynamodb`, `octane` and any other | `VersionCounterLabelIndex`: a counter `labelv:{label}` per label, appended to the key | no | A flush increments counters; entries of older versions are never read and expire by TTL. A row label flush becomes a flush of its table: documented over-invalidation, never staleness. On `memcached` the index is guarded by `FailoverLabelIndex`: a flush the store refuses degrades it instead of failing the write. |
| `failover` | the index of its first store, read from the store's own `stores` entry in `config/cache.php` (any store name), guarded by `FailoverLabelIndex` | as the primary | Degraded mode, below, on a lost connection of a `redis` or `database` first store, and on a flush a `memcached` first store refuses (see "Known limits"). A failover store without a `stores` list throws. |
| `null` | `NullLabelIndex` | n/a | Nothing is stored; every lookup misses. |

**Degraded mode.** A Redis index is always guarded by `FailoverLabelIndex`, with a plain `redis` store as with a
`failover` one. While Redis throws a connection error, every lookup misses and nothing is stored, so no response or
resolution is cached, and every flush is dropped instead of failing the write that issued it: an outage degrades to "no
cache, writes succeed". Every entry stored through the guard carries the `stored` label, so the first successful call
after an outage flushes `stored` before doing anything else, which reaches every kind of entry (CRUD responses, ACL
resolutions, settings, the anonymous user, model discovery, dynamic content lists, cron list, docs, translations, inspector, geocoding) whose
flushes may have been lost. The outage
is remembered in a marker on the host-local `file` store, holding the time of the last failed call: a later request or
worker on the same host also flushes, and each process tries Redis at most once a second, so a request does not pay
one connection timeout per call. The marker is shared by the host, the pacing is per process: a process reads the marker
at most once a second, so processes that read it before another one's failure try Redis on their own, once a second
each. A host that never saw a failure may serve entries written before the outage until some
host has flushed `stored` (Redis is shared, one flush clears all). Only connection failures count as an outage: a Redis
or Predis exception, a flush script Redis answers with an error reply (phpredis returns false for it, such as `-OOM`;
the index throws `LabelIndexUnavailableException`, as the version counter index does for a flush its store refuses),
and a database exception Laravel's lost connection detector recognises (a failover cache whose first store is
`database`). Any other exception propagates.

Redis Cluster is not supported: the store and flush scripts touch an entry and its label sets, which live in different
hash slots.

**Store window.** `core.cache.label_store_window` (seconds, default 60) bounds the time between the lookup and the
store of a value on Redis. A store that comes later is refused: the value is computed again on the next read. Each
process announces its window in the key `labelwindow` before its first lookup and then once per window; the key keeps
the largest window announced (a lower one leaves it, an equal one extends it) and expires after two windows, so a window
no process uses any more is dropped. A flush keeps its records for the larger of its own window and the announced one,
plus one second, so every store they must refuse still finds them, whatever the window of the host that looked the
value up, and they take memory only for the labels flushed within that window. Hosts may therefore run with different
windows, during a rolling change of the setting as for good.

Raise the window if a cached computation can take longer than a minute; a value that never fits is never cached, and
nothing counts it: `store()` returns false and `cache:label-stats` shows only the misses, so an endpoint that is always
recomputed looks like one that is often flushed.

Entries are never stored without an expiry on any index. Version counters (`labelv:*`) are created with a ten-year
TTL, through the store's atomic `add`, so a counter never resets while entries of its versions live. Core caches
nothing with `Cache::forever` outside the index except such counters: the outage marker of `FailoverLabelIndex` and
the metadata generation of `DynamicContentsService`, whose entity, preset and presettable lists are kept in the index
under the `dynamic_contents` label for `cache.duration.long`, under keys that embed the generation.

## What triggers flushes

- **Eloquent events.** One listener (`FlushCacheLabelsOnModelWrite`) on `created`, `updated`, `deleted`, `restored`
  and `forceDeleted` of every model flushes `table:{table}` and, except for a creation, `row:{table}:{id}`. A model
  needs no trait for this; `HasCache` only opts it into the CRUD response cache.
- **Pivots.** The `BelongsToMany` and `MorphToMany` overrides (`FlushesCacheLabelsOnMutation`) flush after `attach`,
  `detach`, `sync`, `toggle` and `updateExistingPivot`, once per outer call (a `sync` calls `attach` and `detach`
  internally; they add their keys to the one flush of the `sync`): the tables of the pivot and of both sides, plus the
  scope labels of a grant:
  - roles or permissions of a user: `user:{id}`;
  - permissions of a role, roles of a permission: `role:{id}` of those roles and of every descendant role, and `acl`;
  - users of a role or permission: `user:{id}` of those users, or `acl` when the touched users are unknown (a detach
    of all).
- **ACL writes.** `AclObserver` flushes `acl`, the role labels (with descendants) of the old and new role, or, for an
  ACL with no role, `crud` and the table label of the permission's entity: such an ACL narrows the entity wherever a
  response embeds it, under row labels the table label does not reach. ACL writes are rare admin operations.
- **Role hierarchy.** `RoleObserver` flushes `acl` and the role labels of the role and of every role below it (roles in
  the bin included) when a role's `parent_id` changes, and when a role is moved to the bin, restored or deleted for
  good; the labels of a deleted role are read before the deletion.
- **CRUD API writes.** `CrudController` flushes nothing after insert, update, delete, activate, inactivate, approve,
  disapprove, lock and unlock: those write through model instances, so the model events flush `table:` and `row:` and
  the details of other rows stay cached. Writes that change no record row (a capture answering 202, a disapproval, a
  withdrawal) are covered by `FlushCacheLabelsOnModelWrite`: a created, updated or deleted `Modification` flushes
  `table:` and `row:` of its modifiable record, since previews and `PendingDeletionStrategy::Hide` make reads depend
  on pending requests. `HasLocks::writeLockColumns()` writes with a query, so it flushes the row and
  table labels itself. `domainAction` and `clearModelCache` call `Cache::clearByEntity()`, a full table flush, because
  what a handler wrote is unknown.
- **Commands and helpers.** `Cache::clearByEntity()` (the whole table: `table:` and `rows:`, for writes whose rows are
  unknown; not needed after a write through models), `clearByUser()`, `clearByGroup()`, `HasCache::invalidateCache()`,
  `AclResolverService::clearCacheFor*()` and `flushCache()`, `translations:check`, the schema commands.
- **Core mass writes.** The settings rows deleted by `model:locked-remove`, `model:optimistic-lock-remove` and
  `model:soft-deletes-remove`, the rows moved by `SettingsCleaner`, and the pending modifications a
  record deleted for good closes (`HasApprovals`), flush their whole table with `flushTable()`.
- **After commit.** When the owning model has an open transaction, `CacheLabelInvalidator` defers the flush to
  `afterCommit`, so a reader cannot re-cache old rows between the flush and the commit. The labels of every write of
  the transaction are collected (`PendingLabelFlushes`, per connection) and flushed together, once, deduplicated: a
  transaction of twenty updates costs one flush. A rollback flushes them too, once the connection has no transaction
  left, since a read inside the transaction may have cached rows the rollback removed. Labels of a write rolled back
  with a savepoint stay collected and are flushed when the transaction ends (an extra miss, never a stale read). A
  callback Laravel runs at the commit of another connection's transaction waits for its own connection's. With no
  transaction it flushes at once.

## Writing outside Eloquent events

Mass `Model::query()->update()`, `delete()`, `insert()`, `upsert()`, raw `DB::` statements and `newPivotQuery()` fire no
model event and no relation override. A module that does this must flush what it wrote, once per batch or run, not
per row:

```php
use Modules\Core\Cache\Labels\CacheLabelInvalidator;

$invalidator = resolve(CacheLabelInvalidator::class);

// $owner is any model of the connection that holds the transaction.
$invalidator->flushTable('cms_contents', $owner);                // table:cms_contents and rows:cms_contents
$invalidator->flushTables(['cms_contents', 'cms_tags'], $owner); // several whole tables in one flush call
$invalidator->flushRecord($model);                                // table and row of one model
$invalidator->flushLabels($owner, CacheLabel::table('cms_tags'), CacheLabel::user(5));
```

Rules:

- Pass an owner model: the flush then waits for the commit of its connection's open transaction. Without an owner it
  runs immediately, which is only right outside a transaction.
- Flush every table the write touched, pivots included (the table of a relation is `$model->relation()->getTable()`).
  `flushTable()` flushes the whole table, the cached details of its rows included; flushing only `table:{table}` by
  hand would leave the details of updated or deleted rows cached.
- Flush at the end of the run, with one `flushTables()` call. A failed run is worth flushing too: a flush that was not
  needed only costs a miss.
- The CMS import is the reference: `ImportPipeline::flushWrittenTables()` (there a defensive guarantee, since the import
  writes through Eloquent).

When a pivot is changed by a raw query, use `flushPivot($relation, $related_keys)` instead of flushing tables by hand if
the pivot is a grant of roles or permissions, so the scope labels are flushed too.

## Caching a value of your own

Use `Modules\Core\Cache\Labels\LabelledCache` (resolve it from the container; it wraps the index singleton). It does
the lookup, the compute and the store in one call:

```php
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\LabelledCache;

$report = resolve(LabelledCache::class)->remember(
    'my-module:report:' . $id,
    [CacheLabel::table('cms_contents')],   // or a CacheDependencies
    300,                                   // ttl in seconds, required: a value is never kept forever
    fn (): array => $this->compute(),
    is_array(...),                         // optional: a hit it rejects is computed again, a value it rejects is not stored
);
```

- `refresh()` takes the same arguments and computes and stores whatever the cache holds, for warmers.
- `forget($key, $dependencies)` drops one value; pass the dependencies it was remembered with, since a version counter
  store embeds their versions in the stored key.
- It stores nothing while a failover index is degraded, when a label was flushed while the value was computed, and
  when the dependencies are incomplete.

A value that carries row labels known only after the query (the CRUD response cache) uses the index directly, lookup,
compute, store:

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

$index->store($lookup, $value, 300, [CacheLabel::row('core_users', $author->getKey())]);
```

Notes:

- `labels` are known before the query. `row_tables` names tables whose row labels the entry may carry; on a version
  counter store they become table labels. The `row_labels` argument of `store()` carries the labels known only after
  the query: row labels, and, on an index with row labels, any other label (the CRUD cache passes the tables of the
  `morphTo` targets it found). The store checks their flush records like those of the lookup labels. Stores without row
  labels ignore it, so a value whose labels are known only after the query must not be stored there.
- `store()` returns false and stores nothing when a label of the entry was flushed after the lookup started, or, on
  Redis, when the lookup started more than the store window ago; do not retry it.
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
  read from the stats counters. Use it to decide whether a coarse table label deserves a narrower one. Flushes count
  flush calls: a transaction flushes once after its commit, whatever the number of its writes. Labels that grow with
  the data are counted in buckets: every row label of a table as `row:{table}:*`, every user label as `user:*`, so the
  counters stay as many as the tables and the fixed labels. On a Redis stats store the counters of one lookup or flush
  cost one script; on other stores each counted label costs an `add` and an `increment`. The label list is written
  only the first time a label is seen, under a lock of the stats store. Stats are best effort: a failing stats store
  is reported and pauses the counting for a minute (then it is tried again), it never fails the read or write being
  measured, and nothing is counted while the Redis index is degraded.
- `cache:warm` warms, through the same index, the settings name index and every settings group the resolver reads
  (the version strategies are the `versioning` group) and the cron list under the table label of the cron table.

## Opting a model into the response cache

Before giving a model `HasCache`, check its ACL filters. ACL filters are runtime data written by an administrator: the
relation paths they name are not in the request, so the response does not carry the labels of those relations' tables.
Either no ACL filter on the model names a relation whose changes should change the response, or the model declares the
tables through `IDeclaresCacheDependencies`. Otherwise a change of the related rows leaves a filtered response stale
until its TTL. The same goes for global scopes and appended accessors that read other tables.

## Known limits

- With `permission.teams` enabled, Spatie's `removeRole()` and `revokePermissionTo()` on a user bypass the relation
  detach and so the flush. Teams are off in Laraplate; enabling them needs a flush added there first.
- History reads are cached with the label of the requested record and the set labels of the versions and modifications
  tables; a write to those tables that bypasses Eloquent events must flush them as any other.
- Search is never cached (see `CRUD_SYSTEM.md`), so it needs no flush on reindex: `scout:index`, `scout:import`,
  `scout:queue-import`, `scout:sync`, `scout:reindex` and `scout:rebuild` write the search engine, not the model's rows,
  and flush nothing.
- Stores without sets (`file`, `database`, `memcached`) over-invalidate: a change to one row flushes every response of
  its table, and old entries stay until their TTL.
- Laravel's lost connection detector, which decides an outage of a `database` first store, also matches some errors
  that are not outages (a read-only replica, a deadlock avoided, access denied, a conflict with recovery). They degrade
  the index for at least a second and the recovery purges `stored`, which on a version counter store is every entry:
  over-invalidation, never a stale read.
- A `memcached` store throws nothing when its server is down: reads miss and writes answer false. A flush whose
  counter can be neither incremented nor created throws `LabelIndexUnavailableException`, so under a `failover` cache
  the index degrades on the first refused flush and purges `stored` on recovery; until a flush is refused, the outage
  only shows as misses.
- A Redis flush may delete an entry stored while the flush was paging its label (over-invalidation, never staleness).
- After a Redis outage, a host that never saw the outage may serve entries stored before it until some host flushes
  `stored`.
- ACL filters that name relations are not labelled (see "Opting a model into the response cache").
- Redis Cluster is not supported, and nothing checks for it: the scripts fail at runtime with a CROSSSLOT error.
- On Redis a flush costs one round trip for the index and one for the stats, for one write outside a transaction as
  for a whole transaction (measured by `RedisLabelWritePathTest`; it was 7 for one update and 140 for a transaction of
  twenty). Writes outside a transaction still flush one by one: wrap a burst of writes in a transaction to pay once.
- A value whose computation takes longer than the store window is never stored on Redis (see "Store window").
- Script cost on Redis: a flush script `DEL`s up to 1000 cached values in one call, freed synchronously unless
  `lazyfree-lazy-user-del` is on (large CRUD snapshots make it slower); a store script reads the flush record of
  every label of the entry in one `MGET`, so an entry with several thousand labels (a large list with per-row labels)
  fails to store, which only leaves it uncached.
- The ordering of lookups and flushes uses the clock of the one Redis primary. A Sentinel failover can lose flush
  records that were not replicated, and the new primary's clock may lag the old one's, so a store started before the
  failover may be accepted after a flush that should have refused it: flush `stored` after a Redis failover.
- Upgrading from v1.80.x or older: old workers flush cache tags, not labels, so while old and new workers run side by
  side (a multi-host deploy, queue workers that restart only after their current job) a write of an old worker does not
  reach the entries a new worker stored. Flush `stored` (or clear the cache) once the last old worker has stopped.
