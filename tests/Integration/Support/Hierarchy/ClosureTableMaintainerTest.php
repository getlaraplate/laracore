<?php

declare(strict_types=1);

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Exceptions\HierarchyCycleException;
use Modules\Core\Exceptions\HierarchyWriteConflictException;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;
use Modules\Core\Tests\Stubs\ClosureTreeStubModel;
use Modules\Core\Tests\Stubs\Hierarchy\StaleSnapshotConnection;

const MAINTAINER_NODES = 'closure_maintainer_nodes';
const MAINTAINER_CLOSURE = 'closure_maintainer_nodes_closure';

beforeEach(function (): void {
    Schema::dropIfExists(MAINTAINER_CLOSURE);
    Schema::dropIfExists(MAINTAINER_NODES);

    Schema::create(MAINTAINER_NODES, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });

    MigrateUtils::closureTable(MAINTAINER_NODES);

    $this->maintainer = new ClosureTableMaintainer(DB::connection(), MAINTAINER_NODES, MAINTAINER_CLOSURE);

    /**
     * Inserts a node row and its closure rows, returning its key.
     */
    $this->addNode = function (?int $parentId): int {
        $id = (int) DB::table(MAINTAINER_NODES)->insertGetId(['parent_id' => $parentId]);
        $this->maintainer->insertNode($id, $parentId);

        return $id;
    };

    /**
     * Changes a node's parent in the model table and in the closure.
     */
    $this->moveNode = function (int $id, ?int $newParentId): void {
        $this->maintainer->moveSubtree($id, $newParentId);
        DB::table(MAINTAINER_NODES)->where('id', $id)->update(['parent_id' => $newParentId]);
    };
});

/**
 * Every closure row as "ancestor>descendant:depth", sorted.
 *
 * @return list<string>
 */
function maintainerClosureRows(string $table = MAINTAINER_CLOSURE, ?string $connection = null): array
{
    $rows = DB::connection($connection)->table($table)->get()
        ->map(static fn (object $row): string => sprintf('%d>%d:%d', $row->ancestor_id, $row->descendant_id, $row->depth))
        ->all();
    sort($rows);

    return $rows;
}

/**
 * The version of a closure table's hierarchy lock; null before its first write.
 */
function maintainerLockVersion(string $closureTable = MAINTAINER_CLOSURE, ?string $connection = null): ?int
{
    $version = DB::connection($connection)->table(CoreTables::HierarchyLocks->value)->where('name', $closureTable)->value('version');

    return $version === null ? null : (int) $version;
}

/**
 * Inserts a node row without its closure rows, as a bulk write does, returning its key.
 */
function maintainerBulkNode(?int $parentId): int
{
    return (int) DB::table(MAINTAINER_NODES)->insertGetId(['parent_id' => $parentId]);
}

/**
 * @param  list<array{0: int, 1: int, 2: int}>  $triples
 * @return list<string>
 */
function maintainerExpectedRows(array $triples): array
{
    $rows = array_map(static fn (array $triple): string => sprintf('%d>%d:%d', ...$triple), $triples);
    sort($rows);

    return $rows;
}

it('creates the closure table with its keys', function (): void {
    expect(Schema::getColumnListing(MAINTAINER_CLOSURE))->toEqualCanonicalizing(['ancestor_id', 'descendant_id', 'depth']);

    $indexes = collect(Schema::getIndexes(MAINTAINER_CLOSURE));
    $primary = $indexes->firstWhere('primary', true);

    expect($primary)->not->toBeNull()
        ->and($primary['columns'])->toBe(['ancestor_id', 'descendant_id'])
        ->and($indexes->contains(static fn (array $index): bool => $index['columns'] === ['descendant_id', 'depth'] && ! $index['primary']))->toBeTrue();

    $foreign_keys = collect(Schema::getForeignKeys(MAINTAINER_CLOSURE))
        ->mapWithKeys(static fn (array $key): array => [$key['columns'][0] => $key]);

    expect($foreign_keys->keys()->sort()->values()->all())->toBe(['ancestor_id', 'descendant_id']);

    foreach ($foreign_keys as $foreign_key) {
        expect($foreign_key['foreign_table'])->toBe(MAINTAINER_NODES)
            ->and($foreign_key['foreign_columns'])->toBe(['id'])
            ->and(mb_strtolower((string) $foreign_key['on_delete']))->toBe('cascade');
    }
});

it('inserts a node under its parent ancestors', function (): void {
    $root = ($this->addNode)(null);
    $child = ($this->addNode)($root);
    $grandchild = ($this->addNode)($child);

    expect(maintainerClosureRows())->toBe(maintainerExpectedRows([
        [$root, $root, 0],
        [$child, $child, 0],
        [$root, $child, 1],
        [$grandchild, $grandchild, 0],
        [$child, $grandchild, 1],
        [$root, $grandchild, 2],
    ]));
});

it('moves a subtree with all its descendants', function (): void {
    $old_root = ($this->addNode)(null);
    $moved = ($this->addNode)($old_root);
    $child = ($this->addNode)($moved);
    $grandchild = ($this->addNode)($child);
    $new_root = ($this->addNode)(null);
    $new_parent = ($this->addNode)($new_root);

    ($this->moveNode)($moved, $new_parent);

    $subtree_rows = [
        [$moved, $moved, 0],
        [$child, $child, 0],
        [$grandchild, $grandchild, 0],
        [$moved, $child, 1],
        [$moved, $grandchild, 2],
        [$child, $grandchild, 1],
    ];

    expect(maintainerClosureRows())->toBe(maintainerExpectedRows([
        [$old_root, $old_root, 0],
        [$new_root, $new_root, 0],
        [$new_parent, $new_parent, 0],
        [$new_root, $new_parent, 1],
        ...$subtree_rows,
        [$new_parent, $moved, 1],
        [$new_parent, $child, 2],
        [$new_parent, $grandchild, 3],
        [$new_root, $moved, 2],
        [$new_root, $child, 3],
        [$new_root, $grandchild, 4],
    ]));

    ($this->moveNode)($moved, null);

    expect(maintainerClosureRows())->toBe(maintainerExpectedRows([
        [$old_root, $old_root, 0],
        [$new_root, $new_root, 0],
        [$new_parent, $new_parent, 0],
        [$new_root, $new_parent, 1],
        ...$subtree_rows,
    ]));
});

it('refuses to move a node under its own descendant', function (): void {
    $root = ($this->addNode)(null);
    $moved = ($this->addNode)($root);
    $child = ($this->addNode)($moved);
    $grandchild = ($this->addNode)($child);
    $before = maintainerClosureRows();

    expect(fn () => $this->maintainer->moveSubtree($moved, $grandchild))->toThrow(HierarchyCycleException::class)
        ->and(fn () => $this->maintainer->moveSubtree($moved, $moved))->toThrow(HierarchyCycleException::class)
        ->and(maintainerClosureRows())->toBe($before);
});

it('detaches the subtrees of nested doomed nodes without touching surviving links', function (): void {
    $root = ($this->addNode)(null);
    $first_doomed = ($this->addNode)($root);
    $survivor = ($this->addNode)($first_doomed);
    $leaf = ($this->addNode)($survivor);
    $second_doomed = ($this->addNode)($survivor);
    $doomed = [$first_doomed, $second_doomed];

    $this->maintainer->detachSubtreesOf(static fn (Builder $query): Builder => $query->whereIn('id', $doomed));

    $rows = maintainerClosureRows();

    expect($rows)->toContain(sprintf('%d>%d:1', $survivor, $leaf))
        ->and($rows)->toContain(sprintf('%d>%d:0', $survivor, $survivor))
        ->and($rows)->toContain(sprintf('%d>%d:0', $leaf, $leaf))
        ->and($rows)->not->toContain(sprintf('%d>%d:3', $root, $leaf))
        ->and($rows)->not->toContain(sprintf('%d>%d:2', $root, $survivor))
        ->and($rows)->not->toContain(sprintf('%d>%d:1', $first_doomed, $survivor));

    DB::table(MAINTAINER_NODES)->whereIn('id', $doomed)->delete();
    DB::table(MAINTAINER_NODES)->where('id', $survivor)->update(['parent_id' => null]);

    expect(maintainerClosureRows())->toBe(maintainerExpectedRows([
        [$root, $root, 0],
        [$survivor, $survivor, 0],
        [$leaf, $leaf, 0],
        [$survivor, $leaf, 1],
    ]));
});

it('links a new parent missing from the closure from the parent column before moving under it', function (): void {
    $root = ($this->addNode)(null);
    $moved = ($this->addNode)($root);
    ($this->addNode)($moved);
    $unlinked_root = maintainerBulkNode(null);
    $unlinked_parent = maintainerBulkNode($unlinked_root);

    ($this->moveNode)($moved, $unlinked_parent);
    $incremental = maintainerClosureRows();
    $this->maintainer->rebuild();

    expect($incremental)->toBe(maintainerClosureRows())
        ->and($incremental)->toContain(sprintf('%d>%d:2', $unlinked_root, $moved));
});

it('leaves the closure on the parent the column kept when two moves of one node reach it in the other order', function (): void {
    $first_parent = ($this->addNode)(null);
    $second_parent = ($this->addNode)(null);
    $moved = ($this->addNode)(null);
    ($this->addNode)($moved);

    // Two plain saves commit the parent column in turn; their closure steps then run last first.
    DB::table(MAINTAINER_NODES)->where('id', $moved)->update(['parent_id' => $first_parent]);
    DB::table(MAINTAINER_NODES)->where('id', $moved)->update(['parent_id' => $second_parent]);
    $this->maintainer->moveSubtreeToParentColumn($moved);
    $this->maintainer->moveSubtreeToParentColumn($moved);
    $incremental = maintainerClosureRows();
    $this->maintainer->rebuild();

    expect($incremental)->toBe(maintainerClosureRows())
        ->and($incremental)->toContain(sprintf('%d>%d:1', $second_parent, $moved))
        ->and($incremental)->not->toContain(sprintf('%d>%d:1', $first_parent, $moved));
});

it('moves nothing when the node whose parent column it follows is gone', function (): void {
    $root = ($this->addNode)(null);
    $before = maintainerClosureRows();
    $version = maintainerLockVersion();

    $this->maintainer->moveSubtreeToParentColumn($root + 100);

    expect(maintainerClosureRows())->toBe($before)
        ->and(maintainerLockVersion())->toBe($version + 1);
});

it('inserts a node as a root when the parent it was given was deleted before its closure step', function (): void {
    $root = ($this->addNode)(null);
    // The parent foreign key cleared the column when the parent row went.
    $orphan = maintainerBulkNode(null);

    $this->maintainer->insertNode($orphan, $root + 100);
    $incremental = maintainerClosureRows();
    $this->maintainer->rebuild();

    expect($incremental)->toBe(maintainerClosureRows())
        ->and($incremental)->toContain(sprintf('%d>%d:0', $orphan, $orphan));
});

it('links a node under the parent its column names when the given parent is missing from the closure', function (): void {
    $root = ($this->addNode)(null);
    $kept_parent = ($this->addNode)($root);
    $node = maintainerBulkNode($kept_parent);

    $this->maintainer->insertNode($node, $root + 100);
    $incremental = maintainerClosureRows();
    $this->maintainer->rebuild();

    expect($incremental)->toBe(maintainerClosureRows())
        ->and($incremental)->toContain(sprintf('%d>%d:2', $root, $node));
});

it('refuses to move a node under a parent missing from the closure that sits in its own subtree', function (): void {
    $root = ($this->addNode)(null);
    $moved = ($this->addNode)($root);
    $child = ($this->addNode)($moved);
    $unlinked_below = maintainerBulkNode($child);
    $before = maintainerClosureRows();

    expect(fn () => $this->maintainer->moveSubtree($moved, $unlinked_below))
        ->toThrow(HierarchyCycleException::class)
        ->and(maintainerClosureRows())->toBe($before);
});

it('links a parent missing from the closure, with its unlinked ancestors, before inserting under it', function (): void {
    $root = ($this->addNode)(null);
    $unlinked_middle = maintainerBulkNode($root);
    $unlinked_parent = maintainerBulkNode($unlinked_middle);
    DB::table(MAINTAINER_CLOSURE)->insert(['ancestor_id' => $root, 'descendant_id' => $unlinked_parent, 'depth' => 2]);

    $leaf = ($this->addNode)($unlinked_parent);
    $incremental = maintainerClosureRows();
    $this->maintainer->rebuild();

    expect($incremental)->toBe(maintainerClosureRows())
        ->and($incremental)->toHaveCount(10)
        ->and($incremental)->toContain(sprintf('%d>%d:3', $root, $leaf));
});

it('links a whole chain the closure does not know, up to its root, before inserting under it', function (): void {
    $unlinked_root = maintainerBulkNode(null);
    $unlinked_parent = maintainerBulkNode($unlinked_root);
    $unrelated = maintainerBulkNode(null);

    $leaf = ($this->addNode)($unlinked_parent);

    expect(maintainerClosureRows())->toBe(maintainerExpectedRows([
        [$unlinked_root, $unlinked_root, 0],
        [$unlinked_parent, $unlinked_parent, 0],
        [$unlinked_root, $unlinked_parent, 1],
        [$leaf, $leaf, 0],
        [$unlinked_parent, $leaf, 1],
        [$unlinked_root, $leaf, 2],
    ]))->and(maintainerClosureRows())->not->toContain(sprintf('%d>%d:0', $unrelated, $unrelated));
});

it('refuses to link a parent column that loops and writes nothing', function (): void {
    $first = maintainerBulkNode(null);
    $second = maintainerBulkNode($first);
    DB::table(MAINTAINER_NODES)->where('id', $first)->update(['parent_id' => $second]);
    $leaf = maintainerBulkNode($second);

    expect(fn () => $this->maintainer->insertNode($leaf, $second))
        ->toThrow(LogicException::class, 'loops')
        ->and(maintainerClosureRows())->toBe([]);
});

it('detaches only the nodes selected by a query the closure returns instead', function (): void {
    $root = ($this->addNode)(null);
    $doomed = ($this->addNode)($root);
    $doomed_child = ($this->addNode)($doomed);
    $survivor = ($this->addNode)($root);
    $survivor_child = ($this->addNode)($survivor);
    $other_doomed = ($this->addNode)($survivor_child);
    $other_doomed_child = ($this->addNode)($other_doomed);

    $this->maintainer->detachSubtreesOf(static fn (Builder $ignored): Builder => DB::table(MAINTAINER_NODES)->where('id', $doomed));
    $this->maintainer->detachSubtreesOf(
        static fn (Builder $ignored): EloquentBuilder => (new ClosureTreeStubModel)->setTable(MAINTAINER_NODES)->newQuery()->whereKey($other_doomed),
    );

    $rows = maintainerClosureRows();

    expect($rows)->toContain(sprintf('%d>%d:1', $survivor, $survivor_child))
        ->and($rows)->toContain(sprintf('%d>%d:2', $root, $survivor_child))
        ->and($rows)->toContain(sprintf('%d>%d:3', $root, $other_doomed))
        ->and($rows)->not->toContain(sprintf('%d>%d:2', $root, $doomed_child))
        ->and($rows)->not->toContain(sprintf('%d>%d:1', $doomed, $doomed_child))
        ->and($rows)->not->toContain(sprintf('%d>%d:4', $root, $other_doomed_child))
        ->and($rows)->not->toContain(sprintf('%d>%d:3', $survivor, $other_doomed_child));

    expect(fn () => $this->maintainer->detachSubtreesOf(static fn (Builder $query): bool => true))
        ->toThrow(LogicException::class, 'must return a query builder or nothing');
});

it('moves and detaches a subtree larger than one delete chunk', function (): void {
    $root = ($this->addNode)(null);
    $old_parent = ($this->addNode)($root);
    $moved = ($this->addNode)($old_parent);
    $branch = ($this->addNode)($moved);

    for ($index = 0; $index < 300; $index++) {
        ($this->addNode)($moved);
        ($this->addNode)($branch);
    }

    $new_root = ($this->addNode)(null);
    $new_parent = ($this->addNode)($new_root);
    $subtree_size = 602;

    ($this->moveNode)($moved, $new_parent);
    $incremental = maintainerClosureRows();

    expect(DB::table(MAINTAINER_CLOSURE)->where('ancestor_id', $new_root)->count())->toBe($subtree_size + 2)
        ->and(DB::table(MAINTAINER_CLOSURE)->where('ancestor_id', $root)->count())->toBe(2)
        ->and(DB::table(MAINTAINER_CLOSURE)->where('ancestor_id', $old_parent)->count())->toBe(1);

    $this->maintainer->rebuild();

    expect(maintainerClosureRows())->toBe($incremental);

    $this->maintainer->detachSubtreesOf(static fn (Builder $query): Builder => $query->whereIn('id', [$moved, $branch]));
    DB::table(MAINTAINER_NODES)->whereIn('id', [$moved, $branch])->delete();
    DB::table(MAINTAINER_NODES)->whereIn('parent_id', [$moved, $branch])->update(['parent_id' => null]);
    $incremental = maintainerClosureRows();

    expect(DB::table(MAINTAINER_CLOSURE)->where('ancestor_id', $new_root)->count())->toBe(2)
        ->and(count($incremental))->toBe(6 + $subtree_size - 2);

    $this->maintainer->rebuild();

    expect(maintainerClosureRows())->toBe($incremental);
});

it('rebuilds to the same rows as incremental maintenance', function (): void {
    mt_srand(20261118);
    $nodes = [];
    $moves = 0;

    for ($index = 0; $index < 300; $index++) {
        $parent_id = $nodes === [] || mt_rand(0, 9) === 0 ? null : $nodes[mt_rand(0, count($nodes) - 1)];
        $nodes[] = ($this->addNode)($parent_id);

        if ($index % 5 !== 4) {
            continue;
        }

        $moved = $nodes[mt_rand(0, count($nodes) - 1)];
        $new_parent = mt_rand(0, 9) === 0 ? null : $nodes[mt_rand(0, count($nodes) - 1)];
        $would_cycle = $new_parent !== null && DB::table(MAINTAINER_CLOSURE)
            ->where('ancestor_id', $moved)
            ->where('descendant_id', $new_parent)
            ->exists();

        if (! $would_cycle) {
            ($this->moveNode)($moved, $new_parent);
            $moves++;
        }
    }

    $incremental = maintainerClosureRows();

    $this->maintainer->rebuild();

    expect(maintainerClosureRows())->toBe($incremental)
        ->and(count($incremental))->toBeGreaterThan(300)
        ->and($moves)->toBeGreaterThan(30);
});

it('builds a maintainer from a model with its connection and closure table', function (): void {
    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('affinity');

    Schema::connection('affinity')->create('closure_tree_nodes', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });
    MigrateUtils::closureTable('closure_tree_nodes', DB::connection('affinity'));

    $root = (int) DB::connection('affinity')->table('closure_tree_nodes')->insertGetId(['parent_id' => null]);
    $child = (int) DB::connection('affinity')->table('closure_tree_nodes')->insertGetId(['parent_id' => $root]);

    $maintainer = ClosureTableMaintainer::forModel((new ClosureTreeStubModel)->setConnection('affinity'));
    $maintainer->insertNode($root, null);
    $maintainer->insertNode($child, $root);

    expect(maintainerClosureRows('closure_tree_nodes_closure', 'affinity'))->toBe(maintainerExpectedRows([
        [$root, $root, 0],
        [$child, $child, 0],
        [$root, $child, 1],
    ]));

    DB::disconnect('affinity');
    DB::purge('affinity');
});

it('detaches the children of a node already deleted from the ancestors read before the delete', function (): void {
    $root = ($this->addNode)(null);
    $doomed = ($this->addNode)($root);
    $first_child = ($this->addNode)($doomed);
    $grandchild = ($this->addNode)($first_child);
    $second_child = ($this->addNode)($doomed);

    DB::table(MAINTAINER_NODES)->where('id', $doomed)->delete();
    DB::table(MAINTAINER_NODES)->where('parent_id', $doomed)->update(['parent_id' => null]);

    $this->maintainer->detachChildren([], [$root]);
    $this->maintainer->detachChildren([$first_child], []);

    expect(maintainerClosureRows())->toContain(sprintf('%d>%d:2', $root, $first_child));

    $this->maintainer->detachChildren([$first_child, $second_child], [$root]);

    expect(maintainerClosureRows())->toBe(maintainerExpectedRows([
        [$root, $root, 0],
        [$first_child, $first_child, 0],
        [$grandchild, $grandchild, 0],
        [$first_child, $grandchild, 1],
        [$second_child, $second_child, 0],
    ]));
});

it('reads the children a delete of the doomed nodes leaves without their parent', function (): void {
    $root = ($this->addNode)(null);
    $first_doomed = ($this->addNode)($root);
    $survivor = ($this->addNode)($first_doomed);
    ($this->addNode)($survivor);
    $second_doomed = ($this->addNode)($survivor);
    $second_survivor = ($this->addNode)($second_doomed);
    $nested_doomed = ($this->addNode)($first_doomed);
    $nested_survivor = ($this->addNode)($nested_doomed);
    $doomed = [$first_doomed, $second_doomed, $nested_doomed];

    expect($this->maintainer->childrenLeftBy(static fn (Builder $query): Builder => $query->whereIn('id', $doomed)))
        ->toBe([$survivor, $second_survivor, $nested_survivor])
        ->and($this->maintainer->childrenLeftBy(
            static fn (Builder $ignored): EloquentBuilder => (new ClosureTreeStubModel)->setTable(MAINTAINER_NODES)->newQuery()->whereKey($second_doomed),
        ))->toBe([$second_survivor])
        ->and($this->maintainer->childrenLeftBy(static fn (Builder $query): Builder => $query->where('id', $second_survivor)))->toBe([]);
});

it('roots the listed nodes the delete left without a parent and leaves the others', function (): void {
    $root = ($this->addNode)(null);
    $first_doomed = ($this->addNode)($root);
    $survivor = ($this->addNode)($first_doomed);
    $leaf = ($this->addNode)($survivor);
    $second_doomed = ($this->addNode)($survivor);
    $second_survivor = ($this->addNode)($second_doomed);
    $second_leaf = ($this->addNode)($second_survivor);
    $doomed = [$first_doomed, $second_doomed];
    $left = $this->maintainer->childrenLeftBy(static fn (Builder $query): Builder => $query->whereIn('id', $doomed));

    DB::table(MAINTAINER_NODES)->whereIn('id', $doomed)->delete();
    DB::table(MAINTAINER_NODES)->whereIn('id', $left)->update(['parent_id' => null]);

    $this->maintainer->rootParentless([]);
    $this->maintainer->rootParentless([$leaf]);

    expect(maintainerClosureRows())->toContain(sprintf('%d>%d:2', $root, $survivor));

    $this->maintainer->rootParentless([...$left, $leaf, $second_leaf]);
    $maintained = maintainerClosureRows();

    expect($maintained)->toBe(maintainerExpectedRows([
        [$root, $root, 0],
        [$survivor, $survivor, 0],
        [$leaf, $leaf, 0],
        [$survivor, $leaf, 1],
        [$second_survivor, $second_survivor, 0],
        [$second_leaf, $second_leaf, 0],
        [$second_survivor, $second_leaf, 1],
    ]));

    $this->maintainer->rebuild();

    expect(maintainerClosureRows())->toBe($maintained);
});

it('reads and roots more children than one statement binds', function (): void {
    $root = ($this->addNode)(null);
    $doomed = ($this->addNode)($root);
    $children = [];

    for ($index = 0; $index < 450; $index++) {
        $children[] = ($this->addNode)($doomed);
    }

    $left = $this->maintainer->childrenLeftBy(static fn (Builder $query): Builder => $query->where('id', $doomed));

    expect($left)->toBe($children);

    DB::table(MAINTAINER_NODES)->where('id', $doomed)->delete();
    DB::table(MAINTAINER_NODES)->whereIn('parent_id', [$doomed])->update(['parent_id' => null]);
    $this->maintainer->rootParentless($left);
    $maintained = maintainerClosureRows();

    expect(DB::table(MAINTAINER_CLOSURE)->where('ancestor_id', $root)->count())->toBe(1)
        ->and(count($maintained))->toBe(451);

    $this->maintainer->rebuild();

    expect(maintainerClosureRows())->toBe($maintained);
});

it('takes the hierarchy lock before any closure statement of every write', function (): void {
    $root = ($this->addNode)(null);
    $middle = ($this->addNode)($root);
    $leaf = ($this->addNode)($middle);
    $other_root = ($this->addNode)(null);

    $writes = [
        'insertNode' => fn () => $this->maintainer->insertNode(maintainerBulkNode($root), $root),
        'moveSubtree' => fn () => $this->maintainer->moveSubtree($middle, $other_root),
        'detachChildren' => fn () => $this->maintainer->detachChildren([$leaf], [$other_root]),
        'detachSubtreesOf' => fn () => $this->maintainer->detachSubtreesOf(fn (Builder $query): Builder => $query->where('id', $root)),
        'rootParentless' => fn () => $this->maintainer->rootParentless([$leaf]),
        'rebuild' => fn () => $this->maintainer->rebuild(),
    ];

    foreach ($writes as $name => $write) {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $version = maintainerLockVersion();

        $write();

        $touching = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, MAINTAINER_CLOSURE) || str_contains($sql, CoreTables::HierarchyLocks->value),
        ));

        expect(str_contains($touching[0] ?? '', CoreTables::HierarchyLocks->value))->toBeTrue($name . ' wrote before the lock')
            ->and(maintainerLockVersion())->toBe($version + 1, $name . ' did not bump the lock once');

        DB::getEventDispatcher()->forget(Illuminate\Database\Events\QueryExecuted::class);
    }
});

it('creates the lock row on the first write of a hierarchy and bumps it on every write', function (): void {
    expect(maintainerLockVersion())->toBeNull();

    $root = ($this->addNode)(null);
    ($this->addNode)($root);

    expect(maintainerLockVersion())->toBe(2);
});

it('takes the lock with one bump when the write opens the outermost transaction', function (): void {
    config()->set('database.connections.lockless', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('lockless');
    Schema::connection('lockless')->create(MAINTAINER_NODES, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
    });
    MigrateUtils::closureTable(MAINTAINER_NODES, DB::connection('lockless'));
    $maintainer = new ClosureTableMaintainer(DB::connection('lockless'), MAINTAINER_NODES, MAINTAINER_CLOSURE);
    $root = (int) DB::connection('lockless')->table(MAINTAINER_NODES)->insertGetId(['parent_id' => null]);
    $child = (int) DB::connection('lockless')->table(MAINTAINER_NODES)->insertGetId(['parent_id' => $root]);

    $maintainer->insertNode($root, null);
    $maintainer->insertNode($child, $root);

    expect(maintainerLockVersion(MAINTAINER_CLOSURE, 'lockless'))->toBe(2)
        ->and(maintainerClosureRows(MAINTAINER_CLOSURE, 'lockless'))->toHaveCount(3)
        ->and(fn () => $maintainer->lock())->toThrow(LogicException::class, 'inside a transaction');

    DB::disconnect('lockless');
    DB::purge('lockless');
});

it('writes nothing and asks for a retry when the transaction snapshot predates the last write', function (): void {
    $root = ($this->addNode)(null);
    $leaf = maintainerBulkNode($root);
    $connection = new StaleSnapshotConnection(DB::connection());
    $connection->readLockVersionsAs([0], repeatLast: true);
    $before = maintainerClosureRows();

    try {
        (new ClosureTableMaintainer($connection, MAINTAINER_NODES, MAINTAINER_CLOSURE))->insertNode($leaf, $root);
        $thrown = null;
    } catch (DeadlockException $exception) {
        $thrown = $exception;
    }

    // Inside the test's own transaction Laravel rethrows a concurrency error as a plain DeadlockException.
    expect($thrown?->getPrevious())->toBeInstanceOf(HierarchyWriteConflictException::class)
        ->and((new ConcurrencyErrorDetector)->causedByConcurrencyError($thrown->getPrevious()))->toBeTrue()
        ->and(maintainerClosureRows())->toBe($before)
        ->and(maintainerLockVersion())->toBe(1);
});

it('asks for a retry when another writer created the lock row after the transaction snapshot', function (): void {
    $root = ($this->addNode)(null);
    $connection = new StaleSnapshotConnection(DB::connection());
    $connection->readLockVersionsAs([null], repeatLast: true);

    expect(fn () => (new ClosureTableMaintainer($connection, MAINTAINER_NODES, MAINTAINER_CLOSURE))->insertNode(maintainerBulkNode($root), $root))
        ->toThrow(DeadlockException::class, 'after this transaction read it')
        ->and(maintainerLockVersion())->toBe(1);
});

it('waits on the lock row with a locking read before reading it from the snapshot', function (): void {
    $root = ($this->addNode)(null);
    $leaf = maintainerBulkNode($root);
    $connection = new StaleSnapshotConnection(DB::connection());

    (new ClosureTableMaintainer($connection, MAINTAINER_NODES, MAINTAINER_CLOSURE))->insertNode($leaf, $root);

    expect($connection->lockReads())->toBe(['locking', 'snapshot'])
        ->and(maintainerLockVersion())->toBe(2)
        ->and(maintainerClosureRows())->toContain(sprintf('%d>%d:1', $root, $leaf));
});

it('creates the lock row when a locking read finds none', function (): void {
    $connection = new StaleSnapshotConnection(DB::connection());
    $root = maintainerBulkNode(null);

    (new ClosureTableMaintainer($connection, MAINTAINER_NODES, MAINTAINER_CLOSURE))->insertNode($root, null);

    expect($connection->lockReads())->toBe(['locking', 'locking', 'snapshot'])
        ->and(maintainerLockVersion())->toBe(1);
});

it('refuses to write when the lock row cannot be read back after it was created', function (): void {
    $root = ($this->addNode)(null);
    $connection = new StaleSnapshotConnection(DB::connection());
    $connection->readLockVersionsAs([null], repeatLast: true, lockingReads: true);

    expect(fn () => (new ClosureTableMaintainer($connection, MAINTAINER_NODES, MAINTAINER_CLOSURE))->insertNode(maintainerBulkNode($root), $root))
        ->toThrow(LogicException::class, 'could not be created')
        ->and(maintainerLockVersion())->toBe(1);
});
