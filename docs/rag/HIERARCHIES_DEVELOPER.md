---
module: core
audience: developer
---
# Hierarchies: giving a model a parent and a tree

Roles (`Core\Models\Role`), taxonomies (`Core\Models\Taxonomy`, so CMS categories) and comments
(`CMS\Models\Comment`) are hierarchies: each row has a `parent_id`. They are stored as an adjacency list and
queried through a **closure table**, a second table with one row for every ancestor and descendant pair. Reading a
whole branch, the ancestors of a node or the path of a node is then a join, never a recursive query, and the
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

`Modules\Core\Support\Hierarchy\ClosurePaths` reads the dotted `path` (`1.2.4`, from the node the walk started at)
that `CrudService::tree()` returns next to the signed `depth`: one query per 500 nodes on the closure table. The
path comes from the closure rows, so a node a scope or an ACL hides still stands in the path of the nodes beyond it.
Above a starting node the rows the relation already loaded are enough when no ancestor is missing from them, so a
tree with parents only, nothing hidden, reads no further row.
Its `chainsOf()` gives each node's ancestors by distance from the same rows.

A taxonomy's own `path` accessor (`HasPath`) is a different value: the translated slugs of its ancestors, root
first, then its own (`Taxonomy::getPath()`), wrapped by `getFullPath()`. Read on taxonomies one by one, it loads the
ancestors of each. `Taxonomy::assignPaths($taxonomies)` gives the whole collection that value at once: the closure
rows of all of them in one query, then their distinct ancestors with their translations once, read through the
model's global scopes as the `ancestors` relation reads them. `getPath()` returns the assigned value when there is
one and builds it from the ancestors otherwise. The value belongs to that load: a taxonomy moved afterwards keeps it
until it is loaded again. The search indexing of contents uses it (`Content::makeSearchableUsing()`).

## What the trait maintains

Model events keep the closure in step with the parent column, each write in a transaction on the model's
connection:

- `created`: the node's closure rows (its self row plus one row per ancestor of the parent).
- `updating` with a new parent: a move under the node itself or one of its descendants is refused with
  `Modules\Core\Exceptions\HierarchyCycleException` before anything is saved.
- `updated` with a new parent: the whole subtree moves: its rows to the old ancestors are removed and its rows to
  the new parent's ancestors-and-self are inserted.
- `deleting`/`deleted`, when the row really goes: the node's own rows go with the cascading foreign keys; the
  surviving children become roots and their subtrees stay linked inside themselves. What the delete needs is read
  in `deleting` and written in `deleted`, so a listener that cancels the delete leaves the closure as it was.
- Soft deletes and restores leave the closure as it is. A trashed node hides only itself: its descendants stay
  reachable, and a trashed role between a user's role and its grandparent still lets the grandparent's permissions
  flow. `Modules\Core\SoftDeletes\RowRemoval::inDelete()` tells a delete that removes the row from one that only
  moves it to the bin.

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
`insertNode()`, `moveSubtree()`, `detachSubtreesOf()`, `detachChildren()`, `childrenLeftBy()`, `rootParentless()`
and `rebuild()`. Moving a node under a parent that has no self row throws a `LogicException` asking for a rebuild:
that is how a closure left behind by a bulk seed shows itself.

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
equality between incremental maintenance and a rebuild on a random 300-node tree);
`Modules/Core/tests/Integration/Helpers/HasClosureTableTest.php` covers the trait and its relations; the CMS
characterization tests under `Modules/CMS/tests/Feature/Hierarchy` pin the tree payload (`depth`, `path`, order,
ACL hiding). The benchmark `Modules/CMS/tests/Integration/Performance/HierarchyBenchmarkTest.php` runs only with
`HIERARCHY_BENCHMARK=1`; data it inserts in bulk must be followed by `rebuildClosure()`.
