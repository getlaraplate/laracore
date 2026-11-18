<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Exceptions\HierarchyCycleException;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Support\Hierarchy\ClosureTableMaintainer;
use Modules\Core\Tests\Stubs\ClosureTreeStubModel;

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

it('refuses to move a node under a parent missing from the closure', function (): void {
    $root = ($this->addNode)(null);
    $moved = ($this->addNode)($root);
    $unlinked_parent = (int) DB::table(MAINTAINER_NODES)->insertGetId(['parent_id' => null]);
    $before = maintainerClosureRows();

    expect(fn () => $this->maintainer->moveSubtree($moved, $unlinked_parent))
        ->toThrow(LogicException::class, 'has no self row')
        ->and(maintainerClosureRows())->toBe($before);
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
