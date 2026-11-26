---
module: core
audience: developer
---
# Hierarchies: giving a model a parent and a tree

Roles (`Core\Models\Role`), taxonomies (`Core\Models\Taxonomy`, so CMS categories) and comments
(`CMS\Models\Comment`) are hierarchies: each row has a `parent_id`. They are stored as an adjacency list and
queried through a **closure table**, a second table with one row for every ancestor and descendant pair. Reading a
whole branch or the ancestors of a node is then a join, never a recursive query, and the
SQL is the same on every driver. The package `staudenmeir/laravel-adjacency-list` is no longer used.

## Giving a model a hierarchy

1. The model table has a nullable `parent_id` column with a foreign key to the table itself, `nullOnDelete()`, and
   a big-integer `id` key.
2. The migration that creates the table calls the helper right after it:

```php
Schema::create('shop_departments', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('parent_id')->nullable()->constrained('shop_departments')->nullOnDelete();
    // ...
});

MigrateUtils::closureTable('shop_departments');
```

`MigrateUtils::closureTable(string $modelTable, ?ConnectionInterface $connection = null)` creates
`{modelTable}_closure` with `ancestor_id`, `descendant_id` (both foreign keys to the model table, cascading on
delete) and `depth` (edges between the two, `0` on the node's own row). The primary key is
`(ancestor_id, descendant_id)`; a second index on `(descendant_id, depth)` serves the walks towards the ancestors.
The closure table has no timestamps: its rows are derived from the parent column and can always be rebuilt.
The helper also creates `core_hierarchy_locks` on the same connection when it is missing (see
[Concurrent writers](#concurrent-writers)); Core's migration creates it on the default connection.

3. The model uses `Modules\Core\Models\Concerns\HasClosureTable`:

```php
final class Department extends Model
{
    use HasClosureTable;
}
```

Two names can be overridden. `getParentKeyName()` returns `parent_id` and `getClosureTable()` returns
`{table}_closure`; the key column is the model's own `getKeyName()`. The closure columns themselves
(`ancestor_id`, `descendant_id`, `depth`) are fixed.

## The relations

The trait gives twelve relations, all eager-loadable (`with('descendants')` or `with('bloodline')` on many parents
at once load each parent's own nodes):

| Relation | Nodes, in order |
|---|---|
| `parent` | the parent (`BelongsTo`) |
| `children` | the children (`HasMany`) |
| `ancestors` | the ancestors, closest first |
| `ancestorsAndSelf` | the node, then its ancestors, closest first |
| `descendants` | the descendants, nearest first |
| `descendantsAndSelf` | the node, then its descendants, nearest first |
| `parentAndSelf` | the node, then its parent |
| `childrenAndSelf` | the node, then its children |
| `bloodline` | the node and its descendants, nearest first, then its ancestors, closest first |
| `rootAncestor` | the root of the node's tree; null for a root |
| `siblings` | the other nodes under the same parent; the other roots for a root |
| `siblingsAndSelf` | the nodes under the same parent, the node included |

The nodes the closure relations load come by distance, nearest first, before any order a global scope adds, then by
key. Each carries a signed `depth`: negative above the node, `0` for the node, positive below. The depth is written
to the node as a raw attribute, so a model whose `setAttribute()` does work (Taxonomy's dynamic fields) pays nothing
per node. `siblings` and `siblingsAndSelf` read the parent column, not the closure table. The closure rows are
written only by the trait: the relations refuse pivot writes (`attach`, `sync`, ...).

Helpers: `getDepth()`, `isRoot()`, `isLeaf()`, `isDescendantOf()`, `isAncestorOf()`, `isSiblingOf()`, and
`moveTo(self $newParent): bool`, which saves the new parent in a transaction and returns false, with nothing
changed, for a move under the node itself or one of its descendants.

The hierarchy computes no path. Laraplate stays headless (decision of 2026-11-24): neither the tree payload of
`CrudService::tree()` nor a taxonomy carries one, so the dotted key path the adjacency-list engine gave each tree node
and the slug path a taxonomy built from its ancestors (`HasPath`, with `ids` and `full_name`) are gone. A client that
needs a breadcrumb or a URL builds it from the nodes and their signed `depth`, or from the ancestors it loads.

## Upgrading an existing install

The three closure tables (`vend_roles_closure`, `core_taxonomies_closure`, `cms_comments_closure`) are created
inside the create migrations of their model tables, so `php artisan migrate` creates none of them on an install
whose migrations already ran. Until the tables exist, every role read that walks the hierarchy (`Role::getAllPermissions()`,
`hasPermission()`, `AclResolverService`) fails with "table not found", and so does creating a category or a comment.

It does create `core_hierarchy_locks`, which every closure write needs (Core migration
`2024_03_15_000000_create_hierarchy_locks_table`, dated before the first closure table so a fresh install creates
it first; an install that already ran the later migrations gets it as a pending one).

The supported path while the project is pre-stable is to start again from the migrations:

```bash
php artisan migrate:fresh --seed
```

This drops all data. To keep the data, create the three closure tables by hand and fill them from the `parent_id`
columns. `MigrateUtils::closureTable()` creates `{modelTable}_closure` (its second argument, the connection, is
optional and defaults to the default connection; pass it when the model table lives on another one). Run it once,
for example from `php artisan tinker` or a one-off migration, and only when the table does not exist yet:

```php
use Illuminate\Support\Facades\Schema;
use Modules\CMS\Models\Category;
use Modules\CMS\Models\Comment;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Models\Role;

foreach (['vend_roles', 'core_taxonomies', 'cms_comments'] as $modelTable) {
    if (! Schema::hasTable($modelTable . '_closure')) {
        MigrateUtils::closureTable($modelTable);
    }
}

Role::rebuildClosure();
Category::rebuildClosure(); // any Taxonomy subclass: they share core_taxonomies_closure
Comment::rebuildClosure();
```

`rebuildClosure()` is a static method of `HasClosureTable`. It empties the model's closure table and rebuilds it
from the parent column in one transaction, soft-deleted rows included, so it can be run again at any time. Skip the
`Comment` lines when the CMS module is not installed. A project with its own hierarchies does the same for each.

Other things a consumer of the removed API must change:

- `Modules\Core\Models\Concerns\HasTypedRecursiveRelationships` is deleted. Models use `HasClosureTable`.
- Core no longer requires `staudenmeir/laravel-adjacency-list`, so it no longer arrives transitively. A project
  model that uses `HasRecursiveRelationships` directly must require the package itself or move to `HasClosureTable`.
- `HasClosureTable` lost `closure()`, `bloodlineAndSelf()`, the `withClosure`, `tree`, `withSiblings` and
  `withBloodline` scopes and the `newCollection()` override (`TreeCollection` is no longer the model collection).
  `bloodline()` is now a relation, not a collection, and `ancestors()` and `ancestorsAndSelf()` list the closest
  ancestor first, not the root.

## What the trait maintains

Model events keep the closure in step with the parent column, each write in a transaction on the model's
connection:

- `created`: the node's closure rows (its self row plus one row per ancestor of the parent). A parent the closure
  does not know, because a bulk insert added it without closure rows, is linked first from the parent column with
  its own unlinked ancestors, so the new node never lands under a missing parent (see below).
- `updating` with a new parent: a move under the node itself or one of its descendants is refused with
  `Modules\Core\Exceptions\HierarchyCycleException` before anything is saved.
- `updated` with a new parent: the whole subtree moves: its rows to the old ancestors are removed and its rows to
  the new parent's ancestors-and-self are inserted.
- `deleting`/`deleted`, when the row really goes: the node's own rows go with the cascading foreign keys; the
  surviving children become roots and their subtrees stay linked inside themselves. What the delete needs is read
  in `deleting` and written in `deleted`, so a listener that cancels the delete leaves the closure as it was. Inside
  a caller's transaction `deleting` takes the hierarchy lock before it reads, so the read cannot go stale before
  `deleted` writes from it.
- Soft deletes and restores leave the closure as it is. A trashed node hides only itself: its descendants stay
  reachable, and a trashed role between a user's role and its grandparent still lets the grandparent's permissions
  flow. `Modules\Core\SoftDeletes\RowRemoval::inDelete()` tells a delete that removes the row from one that only
  moves it to the bin.
- Emptying the bin (`Modules\Core\SoftDeletes\TrashedRowsPurger`, behind `model:soft-deletes-remove`, its
  migration and the soft deletes setting switched off) force deletes each row through its model, so these events
  keep the closure, and the closures an owner's cascade reaches, in step without a rebuild.

The maintenance works through the query builder, ignoring model scopes: trashed, inactive and expired rows stay in
the closure.

A plain `save()` or `delete()` opens no transaction around the model write, so the write and the closure step are
atomic only inside the caller's own transaction (`saveOrFail()`, `DB::transaction()`).

## What a bulk writer must do

Writes that bypass model events (a bulk `insert()`, `Model::query()->delete()`, an `update()` of `parent_id`, an
import, a raw migration) leave the closure behind. After such a write:

- `Model::rebuildClosure()` rebuilds the whole closure from the parent column. It is set-based, one statement per
  level of the deepest branch and none per node, and runs in a transaction.
- or, for a bulk delete, call `ClosureTableMaintainer::detachSubtreesOf(Closure $doomedKeys)` before it. The
  closure adds to a query builder the selection of the keys about to be deleted; the maintainer removes the links
  from every ancestor to the descendants of the doomed nodes that do not go too.
  `ClosureTableMaintainer::forModel($model)` builds the maintainer from the model's connection, table, closure
  table, key and parent column.

`ClosureTableMaintainer` (`Modules\Core\Support\Hierarchy`) owns every set-based statement on a closure table:
`insertNode()`, `moveSubtree()`, `detachSubtreesOf()`, `detachChildren()`, `childrenLeftBy()`, `rootParentless()`,
`rebuild()` and `lock()`.

**A parent missing from the closure is linked, not refused.** When `insertNode()` or `moveSubtree()` puts a node
under a parent that has no self row (a row a bulk insert added without its closure rows), the maintainer climbs
the parent column from that parent to the first node the closure knows, or past a root, and links that chain
top-down before placing the node: the result is what a rebuild would give for that branch. The cycle check of a
move runs after the link, so a missing parent that sits inside the moved subtree is still refused with
`HierarchyCycleException`. A parent column that loops, or names a row that does not exist, throws a
`LogicException` and nothing is written. Only the climbed chain is repaired: other rows a bulk write left without
closure rows stay so (their descendants are missing from `descendants`, for instance) until they are written
through the model or `rebuildClosure()` runs, which stays the repair after a bulk write. The check costs nothing
when the parent is known: the leaf insert reports how many rows it wrote, and a lone self row is the signal; a
move already checked the new parent's self row before. Before 2026-11-25 a move under such a parent threw a
`LogicException` asking for a rebuild, and an insert under it silently wrote the self row alone.

## Concurrent writers

Two transactions writing the same hierarchy at once (a move and a delete of overlapping subtrees, two moves whose
targets sit in each other's subtree) could each read the closure before the other committed and leave rows that
no longer match the parent column, or a cycle that each cycle check missed. Locking the closure rows alone does
not prevent it: under PostgreSQL's READ COMMITTED a statement does not see rows another transaction inserted
while it waited for the lock, and a cycle check reads rows the other move has not written yet.

Every write of `ClosureTableMaintainer` therefore runs in a transaction that first takes the **hierarchy lock**:
the row of its closure table in `core_hierarchy_locks` (`name` primary key, `version`), created on the first
write. The writers of one hierarchy run one at a time; readers never wait, they take no lock. Hierarchies that
share a closure table (every `Taxonomy` subclass) share the lock.

- When the maintainer opens the outermost transaction, nothing has been read in it yet, so one
  `update ... set version = version + 1` takes the lock: one extra statement per write.
- Inside a caller's transaction (`moveTo()`, `saveOrFail()`, `DB::transaction()`, the CRUD service), the
  transaction may have read before, and MySQL and MariaDB under REPEATABLE READ (their default) keep the snapshot
  of that first read for the whole transaction. `ClosureTableMaintainer::lock()` reads the version as the
  transaction sees it and bumps it only if it still holds that value. If the bump misses and a second read still
  shows the old version, the transaction cannot see the last write: it throws
  `Modules\Core\Exceptions\HierarchyWriteConflictException` before any closure row changes. Drivers that read the
  latest commit per statement (PostgreSQL, SQL Server and Oracle under READ COMMITTED, SQLite) see the new version
  on the second read and take the lock on the next round. Two extra statements per write.
- `HierarchyWriteConflictException` extends Laravel's `DeadlockException` with SQLSTATE `40001`, so Laravel treats
  it as a concurrency error: inside a nested transaction it reaches the caller as a `DeadlockException` whose
  previous exception is the conflict, and `DB::transaction($callback, $attempts)` with `$attempts > 1` reruns the
  whole callback on a fresh snapshot. Wrap a hierarchy write that runs inside a longer transaction in one with
  retries when a concurrent move is plausible.
- `lock()` is public for a caller that reads the hierarchy and writes from what it read: call it first in the
  transaction. It throws a `LogicException` outside a transaction, where the lock would end with the statement.
- A plain `save()` or `delete()` opens no transaction around the model write (see above), so a concurrent writer
  can still slip between the row write and the closure step; only a caller's transaction makes the two one step.
- A transaction that writes two hierarchies (an owner's cascade that reaches comments and taxonomies) takes two
  locks; two such transactions taking them in opposite orders are a deadlock the database detects and Laravel
  retries like any other.
- A hierarchy on another connection needs `core_hierarchy_locks` there: `MigrateUtils::closureTable()` creates it,
  `MigrateUtils::hierarchyLocksTable($connection)` creates it alone.

The tests pin the serialization structurally and the conflict logic by simulation (see [Tests](#tests)); the
suite runs on SQLite, which serializes writers itself, so the waiting of one real writer on another has not been
observed in the suite.

## Database portability

The maintainer's SQL is the same on every driver: query builder only, no recursive query, no driver branch, no
upsert or `insertOrIgnore` (SQL Server's grammar has none), no locking read clause (yajra's Oracle grammar
cannot combine `for update` with a row limit, and PostgreSQL refuses it with `distinct`). The review of
2026-11-25 checked these points against the installed grammars:

| Concern | How the maintainer meets it |
|---|---|
| IN-list limits: Oracle 1000 items per list, SQL Server 2100 parameters, older SQLite builds 999 | At most 100 ancestor and 400 descendant keys per delete (500 bound values); node lists are read and rooted in chunks of 400; a deleted node's children are read back in chunks of 400. yajra's `whereIn` also splits lists above 1000. |
| MySQL error 1093 (a `DELETE` reading its own table in a subquery) | Keys are selected first, then deleted by key. |
| Upserts | None. The lock row is created with a plain insert in a savepoint; a duplicate inserted by another writer fails alone and is read back. |
| Subquery aliases (Oracle refuses `as` before a table alias) | `crossJoinSub()` and `joinSub()` go through yajra's `OracleBuilder`, which writes the alias without `as`. |
| Raw select fragments (`depth + 1 as depth`, `0 as depth`) | Unquoted lowercase closure columns: Oracle folds them to upper case, the case yajra quotes every identifier in, so they match. |
| Rows written by `insert ... select` | `rebuild()` and the leaf insert read the affected-row count; it is reliable on every driver while SQL Server's `NOCOUNT` stays off (its default). |
| Isolation | See [Concurrent writers](#concurrent-writers): the lock row's version detects a MySQL snapshot older than the last write. |

What has run, and where:

- **SQLite**: the whole maintainer, trait, cascade and lock suite, on every test run.
- **MySQL 8.0.21**: the closure migrations, a fresh install with the seeds, and the benchmark cases (reads, leaf
  insert, a 200-node subtree move, a middle delete), measured for the performance gate of 2026-11-24 on a throwaway
  database. The lock (added on 2026-11-25) has not run on MySQL yet.
- **PostgreSQL, SQL Server, Oracle**: reviewed against the grammars above, never executed.


## The cascade guard

An owner's hard delete can remove hierarchy rows through cascading foreign keys, with no model event on them:
deleting a user removes their comments, deleting a content its comments, deleting an entity, preset, presettable or
template its taxonomies. The closure rows of the removed nodes go with the closure table's own foreign keys, but
the surviving children of those nodes would keep links to ancestors that no longer lead to them.

`Modules\Core\Support\Hierarchy\OwnerCascadeRegistry` is the Core-owned registry of these cascades, so Core stays
agnostic of the modules that own the hierarchies. A module registers one `OwnerCascade` per owner at boot (owner
class, owner table, hierarchy table, a closure giving the hierarchy's maintainer, and a closure narrowing a query
on the hierarchy table to the rows the owner's delete takes along); Core registers the taxonomy ones in
`CoreServiceProvider`, CMS the comment ones in `CMSServiceProvider`. The owners observed by `HierarchyOwnerObserver` are
User, Entity, Preset, Presettable and Template in Core (the `#[ObservedBy]` attribute), and CMS observes Content in its
provider with `Content::observe(HierarchyOwnerObserver::class)`: in `deleting` the observer only reads, when the row really goes,
the children each cascade leaves without a parent; in `deleted`, once the row is gone, it moves them to the roots.
A delete that a later listener cancels, or that fails, writes nothing to any closure. A new owner of a hierarchy
(a model whose hard delete reaches its rows by a foreign key) must be registered and observed the same way.

## Tests

`Modules/Core/tests/Integration/Support/Hierarchy/ClosureTableMaintainerTest.php` covers the maintainer (including
equality between incremental maintenance and a rebuild on a random 300-node tree, the linking of parents missing
from the closure, the lock taken before any closure statement of every write, and the conflict and retry paths of
`lock()` through `Modules/Core/tests/Stubs/Hierarchy/StaleSnapshotConnection.php`, a connection that reads the lock
row as an older snapshot would);
`Modules/Core/tests/Integration/Helpers/HasClosureTableTest.php` covers the trait and its relations; the CMS
characterization tests under `Modules/CMS/tests/Feature/Hierarchy` pin the tree payload (`depth`, order,
ACL hiding, no `path`). The benchmark `Modules/CMS/tests/Integration/Performance/HierarchyBenchmarkTest.php` runs only with
`HIERARCHY_BENCHMARK=1`; data it inserts in bulk must be followed by `rebuildClosure()`.
