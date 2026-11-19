<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Casts\FieldType;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Exceptions\HierarchyCycleException;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Helpers\TreeCollection;
use Modules\Core\Models\Field;
use Modules\Core\Observers\FieldObserver;
use Modules\Core\Support\RelationGuard;
use Modules\Core\Tests\Stubs\ClosureTreeStubModel;
use Modules\Core\Tests\Stubs\DeleteCancellingClosureTreeStubModel;
use Modules\Core\Tests\Stubs\DeleteRefusingClosureTreeStubModel;
use Modules\Core\Tests\Stubs\HardDeletingClosureTreeStubModel;
use Modules\Core\Tests\Stubs\MutatorCountingClosureTreeStubModel;
use Modules\Core\Tests\Stubs\OrderedClosureTreeStubModel;
use Modules\Core\Tests\Stubs\ReparentingDeleteClosureTreeStubModel;
use Modules\Core\Tests\Stubs\SoftDeletingClosureTreeStubModel;

const CLOSURE_TREE_RELATIONS = [
    'parent',
    'children',
    'ancestors',
    'ancestorsAndSelf',
    'descendants',
    'descendantsAndSelf',
    'bloodline',
    'siblings',
    'siblingsAndSelf',
    'rootAncestor',
    'parentAndSelf',
    'childrenAndSelf',
];

beforeEach(function (): void {
    Schema::dropIfExists('closure_soft_tree_nodes_closure');
    Schema::dropIfExists('closure_soft_tree_nodes');
    Schema::dropIfExists('closure_tree_nodes_closure');
    Schema::dropIfExists('closure_tree_nodes');

    Schema::create('closure_tree_nodes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->nullable()->constrained('closure_tree_nodes')->nullOnDelete();
        $table->unsignedInteger('position')->nullable();
        $table->timestamps();
    });

    MigrateUtils::closureTable('closure_tree_nodes');

    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('affinity');

    Schema::connection('affinity')->create('closure_tree_nodes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->nullable()->constrained('closure_tree_nodes')->nullOnDelete();
        $table->unsignedInteger('position')->nullable();
        $table->timestamps();
    });

    MigrateUtils::closureTable('closure_tree_nodes', DB::connection('affinity'));
});

afterEach(function (): void {
    DB::disconnect('affinity');
    DB::purge('affinity');
});

/**
 * Every closure row of a table as "ancestor>descendant:depth", sorted.
 *
 * @return list<string>
 */
function closureTreeRows(string $table = 'closure_tree_nodes_closure', ?string $connection = null): array
{
    $rows = DB::connection($connection)->table($table)->get()
        ->map(static fn (object $row): string => sprintf('%d>%d:%d', $row->ancestor_id, $row->descendant_id, $row->depth))
        ->all();
    sort($rows);

    return $rows;
}

/**
 * The keys of what a relation loaded: a list for a collection, a key or null for a single model.
 *
 * @return list<int>|int|null
 */
function closureTreeKeys(mixed $loaded): array|int|null
{
    if ($loaded instanceof EloquentCollection) {
        return array_values(array_map(static fn (Model $node): int => (int) $node->getKey(), $loaded->all()));
    }

    return $loaded instanceof Model ? (int) $loaded->getKey() : null;
}

/**
 * Two trees: r1 → a → a1, r1 → b, r2 → c → c1.
 *
 * @return array<string, ClosureTreeStubModel>
 */
function closureTreeForest(): array
{
    $r1 = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $a = ClosureTreeStubModel::query()->create(['parent_id' => $r1->id]);
    $a1 = ClosureTreeStubModel::query()->create(['parent_id' => $a->id]);
    $b = ClosureTreeStubModel::query()->create(['parent_id' => $r1->id]);
    $r2 = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $c = ClosureTreeStubModel::query()->create(['parent_id' => $r2->id]);
    $c1 = ClosureTreeStubModel::query()->create(['parent_id' => $c->id]);

    return ['r1' => $r1, 'a' => $a, 'a1' => $a1, 'b' => $b, 'r2' => $r2, 'c' => $c, 'c1' => $c1];
}

it('writes closure rows on the model connection', function (): void {
    $model = (new ClosureTreeStubModel)->setConnection('affinity');
    $root = $model->newQuery()->create(['parent_id' => null]);
    $model->newQuery()->create(['parent_id' => $root->id]);

    expect(DB::connection('affinity')->table('closure_tree_nodes_closure')->count())->toBe(3)
        ->and(DB::table('closure_tree_nodes_closure')->count())->toBe(0);
});

it('moves affinity tree nodes inside the affinity transaction', function (): void {
    $model = (new ClosureTreeStubModel)->setConnection('affinity');
    $root = $model->newQuery()->create(['parent_id' => null]);
    $first_child = $model->newQuery()->create(['parent_id' => $root->id]);
    $second_child = $model->newQuery()->create(['parent_id' => $root->id]);

    $affinity_transaction_started = false;
    $default_transaction_started = false;
    DB::connection('affinity')->beforeStartingTransaction(static function () use (&$affinity_transaction_started): void {
        $affinity_transaction_started = true;
    });
    DB::connection()->beforeStartingTransaction(static function () use (&$default_transaction_started): void {
        $default_transaction_started = true;
    });

    expect($first_child->moveTo($second_child))->toBeTrue();

    expect($affinity_transaction_started)->toBeTrue()
        ->and($default_transaction_started)->toBeFalse()
        ->and(closureTreeRows(connection: 'affinity'))->toBe([
            '1>1:0', '1>2:2', '1>3:1', '2>2:0', '3>2:1', '3>3:0',
        ]);
});

it('rejects moving tree nodes across database connections before mutation', function (): void {
    $affinity_model = (new ClosureTreeStubModel)->setConnection('affinity');
    $node = $affinity_model->newQuery()->create(['parent_id' => null]);
    $default_parent = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $affinity_closure_count = DB::connection('affinity')->table('closure_tree_nodes_closure')->count();
    $default_closure_count = DB::table('closure_tree_nodes_closure')->count();

    expect(fn (): bool => $node->moveTo($default_parent))
        ->toThrow(LogicException::class, 'Cannot move tree nodes across multiple database connections.');

    expect($node->fresh()->parent_id)->toBeNull()
        ->and(DB::connection('affinity')->table('closure_tree_nodes_closure')->count())->toBe($affinity_closure_count)
        ->and(DB::table('closure_tree_nodes_closure')->count())->toBe($default_closure_count);
});

it('checks linked fields on the field model connection', function (): void {
    Schema::connection('affinity')->create(CoreTables::Fieldables->value, function (Blueprint $table): void {
        $table->unsignedBigInteger('field_id');
    });
    DB::connection('affinity')->table(CoreTables::Fieldables->value)->insert(['field_id' => 999_999]);

    $field = (new Field)->setConnection('affinity');
    $field->forceFill([
        'id' => 999_999,
        'name' => 'affinity_field',
        'type' => FieldType::Text,
    ]);
    $field->exists = true;
    $field->syncOriginal();
    $field->type = FieldType::Number;

    expect(fn (): mixed => (new FieldObserver)->updating($field))
        ->toThrow(ValidationException::class);
});

it('rebuilds closure table from root nodes', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $child = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $written = closureTreeRows();

    DB::table('closure_tree_nodes_closure')->delete();
    ClosureTreeStubModel::rebuildClosure();

    expect(closureTreeRows())->toBe($written)
        ->and($written)->toBe(['1>1:0', '1>2:1', '2>2:0'])
        ->and($child->fresh()->getDepth())->toBe(1);
});

it('computes the depth from the root, zero for a root', function (): void {
    $forest = closureTreeForest();

    expect($forest['r1']->fresh()->getDepth())->toBe(0)
        ->and($forest['a']->fresh()->getDepth())->toBe(1)
        ->and($forest['a1']->fresh()->getDepth())->toBe(2);
});

it('reads the depth from the closure on every call, without process-level state', function (): void {
    $forest = closureTreeForest();
    $node = $forest['a1']->fresh();

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($node->getDepth())->toBe(2)
        ->and($node->getDepth())->toBe(2)
        ->and(DB::getQueryLog())->toHaveCount(2);

    expect((new ReflectionClass(ClosureTreeStubModel::class))->getStaticProperties())
        ->not->toHaveKey('depth_cache');
});

it('gives the descendants of a moved node their new depth on the instances already held', function (): void {
    $forest = closureTreeForest();
    $grandchild = $forest['a1']->fresh();

    expect($grandchild->getDepth())->toBe(2);

    // The old cache was invalidated only for the moved node: its descendants kept serving the old depth.
    expect($forest['a']->fresh()->moveTo($forest['c1']->fresh()))->toBeTrue()
        ->and($grandchild->getDepth())->toBe(4);
});

it('reads the depth of identical table and ids on their own connection', function (): void {
    $default_root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $default_node = ClosureTreeStubModel::query()->create(['parent_id' => $default_root->id]);

    $affinity = (new ClosureTreeStubModel)->setConnection('affinity');
    $affinity_root = $affinity->newQuery()->create(['parent_id' => null]);
    $affinity_child = $affinity->newQuery()->create(['parent_id' => $affinity_root->id]);
    $affinity_node = $affinity->newQuery()->create(['parent_id' => $affinity_child->id]);

    expect($default_node->getKey())->toBe($affinity_child->getKey())
        ->and($default_node->getDepth())->toBe(1)
        ->and($affinity_child->getDepth())->toBe(1)
        ->and($affinity_node->getDepth())->toBe(2);

    expect($default_node->moveTo(ClosureTreeStubModel::query()->create(['parent_id' => null])))->toBeTrue()
        ->and($default_node->getDepth())->toBe(1)
        ->and($affinity_node->getDepth())->toBe(2);
});

it('resolves parent belongs-to relationship', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $child = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);

    $child = $child->fresh();

    expect($child->parent())->toBeInstanceOf(BelongsTo::class)
        ->and($child->parent)->not->toBeNull()
        ->and($child->parent->is($root))->toBeTrue();
});

it('detects root leaf and sibling relationships', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $a = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $b = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);

    expect($root->isRoot())->toBeTrue()
        ->and($root->isLeaf())->toBeFalse()
        ->and($a->isRoot())->toBeFalse()
        ->and($a->isLeaf())->toBeTrue()
        ->and($a->isSiblingOf($b))->toBeTrue()
        ->and($a->isSiblingOf($a))->toBeFalse();
});

it('detects ancestor and descendant via closure', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $child = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);

    $root = $root->fresh();
    $child = $child->fresh();

    expect($child->isDescendantOf($root))->toBeTrue()
        ->and($root->isAncestorOf($child))->toBeTrue()
        ->and($root->isDescendantOf($child))->toBeFalse()
        ->and($root->isAncestorOf($root))->toBeFalse();
});

it('moves node to new parent when allowed', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $a = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $b = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);

    $a = $a->fresh();
    $b = $b->fresh();

    expect($a->moveTo($b))->toBeTrue()
        ->and($a->fresh()->parent_id)->toBe($b->id)
        ->and($a->fresh()->ancestors->modelKeys())->toBe([$b->id, $root->id]);
});

it('refuses move when target is descendant or the node itself', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $child = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $rows = closureTreeRows();

    $root = $root->fresh();
    $child = $child->fresh();

    expect($root->moveTo($child))->toBeFalse()
        ->and($root->moveTo($root))->toBeFalse()
        ->and($root->fresh()->parent_id)->toBeNull()
        ->and(closureTreeRows())->toBe($rows);
});

it('leaves the collection class to the model, tree collection stays available explicitly', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);

    $nodes = ClosureTreeStubModel::query()->get();

    expect($nodes)->not->toBeInstanceOf(TreeCollection::class)
        ->and($nodes::class)->toBe(EloquentCollection::class)
        ->and((new TreeCollection($nodes->all()))->tree())->toHaveCount(1);
});

it('declares every hierarchy relation with a relation return type the relation guard accepts', function (): void {
    $node = new ClosureTreeStubModel;

    foreach (CLOSURE_TREE_RELATIONS as $name) {
        expect(RelationGuard::relationOf($node, $name))->toBeInstanceOf(Relation::class, $name);
    }

    expect($node->ancestors())->toBeInstanceOf(BelongsToMany::class)
        ->and($node->ancestorsAndSelf())->toBeInstanceOf(BelongsToMany::class)
        ->and($node->descendants())->toBeInstanceOf(BelongsToMany::class)
        ->and($node->descendantsAndSelf())->toBeInstanceOf(BelongsToMany::class)
        ->and($node->children())->toBeInstanceOf(HasMany::class)
        ->and(method_exists($node, 'closure'))->toBeFalse()
        ->and(method_exists($node, 'bloodlineAndSelf'))->toBeFalse()
        ->and(method_exists($node, 'withClosure'))->toBeFalse()
        ->and(method_exists($node, 'tree'))->toBeFalse()
        ->and(method_exists($node, 'withSiblings'))->toBeFalse()
        ->and(method_exists($node, 'withBloodline'))->toBeFalse();
});

it('gives siblings the nodes under the same parent, and the other roots to a root', function (): void {
    $forest = closureTreeForest();

    expect($forest['a']->fresh()->siblings->modelKeys())->toBe([$forest['b']->id])
        ->and($forest['a']->fresh()->siblingsAndSelf->modelKeys())->toEqualCanonicalizing([$forest['a']->id, $forest['b']->id])
        ->and($forest['r1']->fresh()->siblings->modelKeys())->toBe([$forest['r2']->id])
        ->and($forest['c']->fresh()->siblings->modelKeys())->toBe([]);
});

it('deletes closure rows when a node is deleted', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $child = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);

    $child->delete();

    expect(closureTreeRows())->toBe(['1>1:0']);
});

it('loads the bloodline as a relation: the node, its descendants, then its ancestors closest first', function (): void {
    $forest = closureTreeForest();

    $bloodline = $forest['a']->fresh()->bloodline;

    expect($forest['a']->bloodline())->toBeInstanceOf(Relation::class)
        ->and($bloodline->map(static fn (Model $node): array => [$node->getKey(), $node->getAttribute('depth')])->all())
        ->toBe([[$forest['a']->id, 0], [$forest['a1']->id, 1], [$forest['r1']->id, -1]]);
});

it('rebuilds a larger closure tree without eager loading recursive children', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $parent_id = $root->id;

    for ($i = 0; $i < 120; $i++) {
        $node = ClosureTreeStubModel::query()->create(['parent_id' => $parent_id]);
        $parent_id = $node->id;
    }

    DB::enableQueryLog();

    ClosureTreeStubModel::rebuildClosure();

    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect(DB::table('closure_tree_nodes_closure')->count())->toBe(121 * 122 / 2)
        ->and($queries)->not->toContain('select * from "closure_tree_nodes" where "closure_tree_nodes"."parent_id" in');
});

it('orders ancestors closest first and descendants nearest first under a global scope ordering by another column', function (): void {
    $root = OrderedClosureTreeStubModel::query()->create(['parent_id' => null, 'position' => 1]);
    $child = OrderedClosureTreeStubModel::query()->create(['parent_id' => $root->id, 'position' => 3]);
    $grandchild = OrderedClosureTreeStubModel::query()->create(['parent_id' => $child->id, 'position' => 2]);

    expect($grandchild->fresh()->ancestors->modelKeys())->toBe([$child->id, $root->id])
        ->and($grandchild->fresh()->ancestorsAndSelf->modelKeys())->toBe([$grandchild->id, $child->id, $root->id])
        ->and($root->fresh()->descendants->modelKeys())->toBe([$child->id, $grandchild->id])
        ->and($root->fresh()->descendantsAndSelf->modelKeys())->toBe([$root->id, $child->id, $grandchild->id])
        ->and($child->fresh()->bloodline->modelKeys())->toBe([$child->id, $grandchild->id, $root->id])
        ->and(OrderedClosureTreeStubModel::query()->whereKey($grandchild->id)->with('ancestors')->sole()->ancestors->modelKeys())
        ->toBe([$child->id, $root->id]);
});

it('exposes a signed depth on every node a hierarchy relation loads, and no pivot', function (): void {
    $forest = closureTreeForest();

    $depths = static fn (EloquentCollection $nodes): array => $nodes
        ->map(static fn (Model $node): array => [$node->getKey(), $node->getAttribute('depth')])
        ->all();

    $grandchild = $forest['a1']->fresh();
    $root = $forest['r1']->fresh();

    expect($depths($grandchild->ancestorsAndSelf))->toBe([[$forest['a1']->id, 0], [$forest['a']->id, -1], [$forest['r1']->id, -2]])
        ->and($depths($grandchild->ancestors))->toBe([[$forest['a']->id, -1], [$forest['r1']->id, -2]])
        ->and($depths($grandchild->parentAndSelf))->toBe([[$forest['a1']->id, 0], [$forest['a']->id, -1]])
        ->and($grandchild->rootAncestor?->getAttribute('depth'))->toBe(-2)
        ->and($depths($root->descendantsAndSelf))->toEqualCanonicalizing([
            [$forest['r1']->id, 0], [$forest['a']->id, 1], [$forest['b']->id, 1], [$forest['a1']->id, 2],
        ])
        ->and($depths($root->descendants)[2])->toBe([$forest['a1']->id, 2])
        ->and($depths($root->childrenAndSelf))->toEqualCanonicalizing([[$forest['r1']->id, 0], [$forest['a']->id, 1], [$forest['b']->id, 1]]);

    foreach (['ancestors', 'ancestorsAndSelf', 'descendants', 'descendantsAndSelf', 'bloodline', 'parentAndSelf', 'childrenAndSelf'] as $name) {
        $loaded = ClosureTreeStubModel::query()->whereKey($forest['a']->id)->with($name)->sole()->getRelation($name);

        expect($loaded)->not->toBeEmpty();

        foreach ($loaded as $node) {
            expect($node->toArray())->toHaveKey('depth')->not->toHaveKey('pivot');
        }
    }

    expect($grandchild->rootAncestor->toArray())->toHaveKey('depth')->not->toHaveKey('pivot');
});

it('eager loads each of the twelve relations on three parents, each parent getting its own nodes', function (): void {
    $forest = closureTreeForest();
    $id = static fn (string ...$names): array => array_map(static fn (string $name): int => $forest[$name]->id, $names);

    $expected = [
        'parent' => ['a' => $forest['r1']->id, 'b' => $forest['r1']->id, 'c' => $forest['r2']->id],
        'children' => ['a' => $id('a1'), 'b' => [], 'c' => $id('c1')],
        'ancestors' => ['a' => $id('r1'), 'b' => $id('r1'), 'c' => $id('r2')],
        'ancestorsAndSelf' => ['a' => $id('a', 'r1'), 'b' => $id('b', 'r1'), 'c' => $id('c', 'r2')],
        'descendants' => ['a' => $id('a1'), 'b' => [], 'c' => $id('c1')],
        'descendantsAndSelf' => ['a' => $id('a', 'a1'), 'b' => $id('b'), 'c' => $id('c', 'c1')],
        'bloodline' => ['a' => $id('a', 'a1', 'r1'), 'b' => $id('b', 'r1'), 'c' => $id('c', 'c1', 'r2')],
        'siblings' => ['a' => $id('b'), 'b' => $id('a'), 'c' => []],
        'siblingsAndSelf' => ['a' => $id('a', 'b'), 'b' => $id('a', 'b'), 'c' => $id('c')],
        'rootAncestor' => ['a' => $forest['r1']->id, 'b' => $forest['r1']->id, 'c' => $forest['r2']->id],
        'parentAndSelf' => ['a' => $id('a', 'r1'), 'b' => $id('b', 'r1'), 'c' => $id('c', 'r2')],
        'childrenAndSelf' => ['a' => $id('a', 'a1'), 'b' => $id('b'), 'c' => $id('c', 'c1')],
    ];

    expect(array_keys($expected))->toBe(CLOSURE_TREE_RELATIONS);

    foreach ($expected as $name => $per_parent) {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $parents = ClosureTreeStubModel::query()
            ->whereKey([$forest['a']->id, $forest['b']->id, $forest['c']->id])
            ->with($name)
            ->get()
            ->keyBy('id');

        expect(DB::getQueryLog())->toHaveCount(2, $name);

        foreach ($per_parent as $parent_name => $keys) {
            $eager = closureTreeKeys($parents[$forest[$parent_name]->id]->getRelation($name));
            $lazy = closureTreeKeys($forest[$parent_name]->fresh()->getAttribute($name));

            expect($eager)->toBe($lazy, sprintf('%s of %s: eager differs from lazy', $name, $parent_name))
                ->and(is_array($eager) ? $eager : [$eager])->toEqualCanonicalizing(is_array($keys) ? $keys : [$keys], sprintf('%s of %s', $name, $parent_name));
        }
    }

    DB::disableQueryLog();
});

it('moves the whole subtree when a plain save changes the parent', function (): void {
    $forest = closureTreeForest();

    $a = $forest['a']->fresh();
    $a->parent_id = $forest['c']->id;
    $a->save();

    $rows = closureTreeRows();
    ClosureTreeStubModel::rebuildClosure();

    expect($rows)->toBe(closureTreeRows())
        ->and($forest['a1']->fresh()->ancestors->modelKeys())->toBe([$forest['a']->id, $forest['c']->id, $forest['r2']->id])
        ->and($forest['a1']->fresh()->getDepth())->toBe(3);
});

it('moves the subtree to the roots when a plain save clears the parent', function (): void {
    $forest = closureTreeForest();

    $a = $forest['a']->fresh();
    $a->parent_id = null;
    $a->save();

    $rows = closureTreeRows();
    ClosureTreeStubModel::rebuildClosure();

    expect($rows)->toBe(closureTreeRows())
        ->and($forest['a1']->fresh()->ancestors->modelKeys())->toBe([$forest['a']->id]);
});

it('refuses a parent inside the own subtree and saves nothing', function (): void {
    $forest = closureTreeForest();
    $rows = closureTreeRows();

    $root = $forest['r1']->fresh();
    $root->parent_id = $forest['a1']->id;

    expect(fn (): bool => $root->save())->toThrow(HierarchyCycleException::class);

    $self = $forest['a']->fresh();
    $self->parent_id = $forest['a']->id;

    expect(fn (): bool => $self->save())->toThrow(HierarchyCycleException::class)
        ->and($forest['r1']->fresh()->parent_id)->toBeNull()
        ->and($forest['a']->fresh()->parent_id)->toBe($forest['r1']->id)
        ->and(closureTreeRows())->toBe($rows);
});

it('leaves the children of a hard-deleted middle node as roots with their subtrees intact', function (): void {
    $forest = closureTreeForest();
    $a2 = ClosureTreeStubModel::query()->create(['parent_id' => $forest['a1']->id]);

    $forest['a']->fresh()->delete();

    $rows = closureTreeRows();
    ClosureTreeStubModel::rebuildClosure();

    expect($rows)->toBe(closureTreeRows())
        ->and($forest['a1']->fresh()->parent_id)->toBeNull()
        ->and($forest['a1']->fresh()->descendants->modelKeys())->toBe([$a2->id])
        ->and($a2->fresh()->ancestors->modelKeys())->toBe([$forest['a1']->id])
        ->and($forest['r1']->fresh()->descendants->modelKeys())->toBe([$forest['b']->id]);
});

it('ends a sequence of creates, moves, a refused cycle and a delete equal to a rebuild', function (): void {
    $forest = closureTreeForest();

    $forest['b']->fresh()->moveTo($forest['c1']->fresh());

    $c = $forest['c']->fresh();
    $c->parent_id = $forest['a1']->id;
    $c->save();

    $r1 = $forest['r1']->fresh();
    $r1->parent_id = $forest['b']->id;

    try {
        $r1->save();
    } catch (HierarchyCycleException) {
    }

    ClosureTreeStubModel::query()->create(['parent_id' => $forest['b']->id]);
    $forest['a1']->fresh()->delete();
    $forest['r2']->fresh()->delete();

    $rows = closureTreeRows();
    ClosureTreeStubModel::rebuildClosure();

    expect($rows)->toBe(closureTreeRows())
        ->and(count($rows))->toBeGreaterThan(5);
});

it('hides only the trashed middle node and keeps the closure through soft delete and restore', function (): void {
    Schema::create('closure_soft_tree_nodes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->nullable()->constrained('closure_soft_tree_nodes')->nullOnDelete();
        $table->softDeletes();
        $table->boolean('is_deleted')->storedAs('deleted_at IS NOT NULL');
        $table->timestamps();
    });
    MigrateUtils::closureTable('closure_soft_tree_nodes');

    $root = SoftDeletingClosureTreeStubModel::query()->create(['parent_id' => null]);
    $middle = SoftDeletingClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $leaf = SoftDeletingClosureTreeStubModel::query()->create(['parent_id' => $middle->id]);
    $rows = closureTreeRows('closure_soft_tree_nodes_closure');

    $middle->delete();

    expect(closureTreeRows('closure_soft_tree_nodes_closure'))->toBe($rows)
        ->and($leaf->fresh()->ancestors->modelKeys())->toBe([$root->id])
        ->and($root->fresh()->descendants->modelKeys())->toBe([$leaf->id])
        ->and($leaf->fresh()->getDepth())->toBe(2);

    SoftDeletingClosureTreeStubModel::withTrashed()->findOrFail($middle->id)->restore();

    expect(closureTreeRows('closure_soft_tree_nodes_closure'))->toBe($rows)
        ->and($leaf->fresh()->ancestors->modelKeys())->toBe([$middle->id, $root->id]);

    $middle->fresh()->forceDelete();

    expect($leaf->fresh()->parent_id)->toBeNull()
        ->and(closureTreeRows('closure_soft_tree_nodes_closure'))->toBe(['1>1:0', '3>3:0']);
});

it('detaches the subtree when soft deletes are switched off and delete removes the row', function (): void {
    Schema::create('closure_soft_tree_nodes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->nullable()->constrained('closure_soft_tree_nodes')->nullOnDelete();
        $table->softDeletes();
        $table->boolean('is_deleted')->storedAs('deleted_at IS NOT NULL');
        $table->timestamps();
    });
    MigrateUtils::closureTable('closure_soft_tree_nodes');

    $root = HardDeletingClosureTreeStubModel::query()->create(['parent_id' => null]);
    $middle = HardDeletingClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $leaf = HardDeletingClosureTreeStubModel::query()->create(['parent_id' => $middle->id]);

    $middle->delete();

    expect(DB::table('closure_soft_tree_nodes')->where('id', $middle->id)->exists())->toBeFalse()
        ->and($leaf->fresh()->parent_id)->toBeNull()
        ->and(closureTreeRows('closure_soft_tree_nodes_closure'))->toBe([
            sprintf('%d>%d:0', $root->id, $root->id),
            sprintf('%d>%d:0', $leaf->id, $leaf->id),
        ]);
});

it('answers existence and count queries through the closure relations', function (): void {
    $forest = closureTreeForest();

    $counts = ClosureTreeStubModel::query()
        ->withCount(['ancestors', 'descendants', 'bloodline', 'siblings', 'siblingsAndSelf'])
        ->orderBy('id')
        ->get()
        ->map(static fn (Model $node): array => [
            $node->getKey(),
            $node->getAttribute('ancestors_count'),
            $node->getAttribute('descendants_count'),
            $node->getAttribute('bloodline_count'),
            $node->getAttribute('siblings_count'),
            $node->getAttribute('siblings_and_self_count'),
        ])
        ->all();

    expect(ClosureTreeStubModel::query()->has('rootAncestor')->orderBy('id')->pluck('id')->all())
        ->toBe([$forest['a']->id, $forest['a1']->id, $forest['b']->id, $forest['c']->id, $forest['c1']->id])
        ->and(ClosureTreeStubModel::query()->doesntHave('ancestors')->orderBy('id')->pluck('id')->all())
        ->toBe([$forest['r1']->id, $forest['r2']->id])
        ->and($counts)->toBe([
            [$forest['r1']->id, 0, 3, 4, 1, 2],
            [$forest['a']->id, 1, 1, 3, 1, 2],
            [$forest['a1']->id, 2, 0, 3, 0, 1],
            [$forest['b']->id, 1, 0, 2, 1, 2],
            [$forest['r2']->id, 0, 2, 3, 1, 2],
            [$forest['c']->id, 1, 1, 3, 0, 1],
            [$forest['c1']->id, 2, 0, 3, 0, 1],
        ]);
});

it('leaves the closure as it was when a later deleting listener cancels the delete', function (): void {
    $root = DeleteCancellingClosureTreeStubModel::query()->create(['parent_id' => null]);
    $middle = DeleteCancellingClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    DeleteCancellingClosureTreeStubModel::query()->create(['parent_id' => $middle->id]);
    $rows = closureTreeRows();

    expect($middle->delete())->toBeFalse()
        ->and(DB::table('closure_tree_nodes')->where('id', $middle->id)->exists())->toBeTrue()
        ->and(closureTreeRows())->toBe($rows);
});

it('leaves the closure as it was when a later deleting listener throws', function (): void {
    $root = DeleteRefusingClosureTreeStubModel::query()->create(['parent_id' => null]);
    $middle = DeleteRefusingClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    DeleteRefusingClosureTreeStubModel::query()->create(['parent_id' => $middle->id]);
    $rows = closureTreeRows();

    expect(fn (): ?bool => $middle->delete())->toThrow(RuntimeException::class, 'Delete refused.')
        ->and(DB::table('closure_tree_nodes')->where('id', $middle->id)->exists())->toBeTrue()
        ->and(closureTreeRows())->toBe($rows);
});

it('orders nodes tied on depth and on the scope order by key, after the scope order, and still counts and paginates', function (): void {
    $root = OrderedClosureTreeStubModel::query()->forceCreate(['id' => 1, 'parent_id' => null, 'position' => 1]);
    $high_key = OrderedClosureTreeStubModel::query()->forceCreate(['id' => 20, 'parent_id' => $root->id, 'position' => 5]);
    $low_key = OrderedClosureTreeStubModel::query()->forceCreate(['id' => 10, 'parent_id' => $root->id, 'position' => 5]);
    $first_by_scope = OrderedClosureTreeStubModel::query()->forceCreate(['id' => 30, 'parent_id' => $root->id, 'position' => 2]);
    $grandchild = OrderedClosureTreeStubModel::query()->forceCreate(['id' => 5, 'parent_id' => $high_key->id, 'position' => 0]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $descendants = $root->fresh()->descendants;
    $sql = collect(DB::getQueryLog())->pluck('query')->last();

    expect($descendants->modelKeys())->toBe([$first_by_scope->id, $low_key->id, $high_key->id, $grandchild->id])
        ->and($sql)->toEndWith('order by "closure_tree_nodes_closure"."depth" asc, "closure_tree_nodes"."position" asc, "closure_tree_nodes"."id" asc')
        ->and($root->fresh()->bloodline->modelKeys())->toBe([$root->id, $first_by_scope->id, $low_key->id, $high_key->id, $grandchild->id])
        ->and($root->fresh()->descendants()->count())->toBe(4)
        ->and($root->fresh()->descendants()->paginate(2)->total())->toBe(4)
        ->and($root->fresh()->descendants()->paginate(2)->getCollection()->modelKeys())->toBe([$first_by_scope->id, $low_key->id]);

    DB::disableQueryLog();
});

it('returns false from moveTo, with nothing changed, when a listener cancels the save', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $a = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $b = ClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $rows = closureTreeRows();

    Event::listen('eloquent.updating: ' . ClosureTreeStubModel::class, static fn (): bool => false);

    expect($a->fresh()->moveTo($b->fresh()))->toBeFalse()
        ->and($a->fresh()->parent_id)->toBe($root->id)
        ->and(closureTreeRows())->toBe($rows);
});

it('refuses pivot writes on the closure relations', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $other = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $rows = closureTreeRows();

    expect(fn (): mixed => $other->ancestors()->attach($root->id))->toThrow(LogicException::class, 'attach() is refused')
        ->and(fn (): mixed => $root->descendants()->create([]))->toThrow(LogicException::class, 'create() is refused')
        ->and(fn (): mixed => $root->bloodline()->sync([$other->id]))->toThrow(LogicException::class, 'sync() is refused')
        ->and(ClosureTreeStubModel::query()->count())->toBe(2)
        ->and(closureTreeRows())->toBe($rows);
});

it('eager loads on parents where one is an ancestor of another', function (): void {
    $forest = closureTreeForest();
    $pairs = static fn (EloquentCollection $nodes): array => $nodes
        ->map(static fn (Model $node): array => [$node->getKey(), $node->getAttribute('depth')])
        ->all();

    $loaded = ClosureTreeStubModel::query()
        ->whereKey([$forest['a']->id, $forest['a1']->id])
        ->with(['ancestors', 'descendants', 'bloodline'])
        ->get()
        ->keyBy('id');

    $a = $loaded[$forest['a']->id];
    $a1 = $loaded[$forest['a1']->id];

    expect($pairs($a->ancestors))->toBe([[$forest['r1']->id, -1]])
        ->and($pairs($a1->ancestors))->toBe([[$forest['a']->id, -1], [$forest['r1']->id, -2]])
        ->and($pairs($a->descendants))->toBe([[$forest['a1']->id, 1]])
        ->and($pairs($a1->descendants))->toBe([])
        ->and($pairs($a->bloodline))->toBe([[$forest['a']->id, 0], [$forest['a1']->id, 1], [$forest['r1']->id, -1]])
        ->and($pairs($a1->bloodline))->toBe([[$forest['a1']->id, 0], [$forest['a']->id, -1], [$forest['r1']->id, -2]]);
});

it('keeps the links of children a deleting listener moved up to the grandparent', function (): void {
    $root = ReparentingDeleteClosureTreeStubModel::query()->create(['parent_id' => null]);
    $middle = ReparentingDeleteClosureTreeStubModel::query()->create(['parent_id' => $root->id]);
    $moved = ReparentingDeleteClosureTreeStubModel::query()->create(['parent_id' => $middle->id]);
    $grandchild = ReparentingDeleteClosureTreeStubModel::query()->create(['parent_id' => $moved->id]);

    $middle->delete();

    $rows = closureTreeRows();
    ClosureTreeStubModel::rebuildClosure();

    expect($rows)->toBe(closureTreeRows())
        ->and($moved->fresh()->parent_id)->toBe($root->id)
        ->and($grandchild->fresh()->ancestors->modelKeys())->toBe([$moved->id, $root->id]);
});

it('hydrates the nodes of a closure relation without going through the model mutators', function (): void {
    $root = ClosureTreeStubModel::query()->create(['parent_id' => null]);
    $middle = ClosureTreeStubModel::query()->create(['parent_id' => $root->getKey()]);
    $leaf_a = ClosureTreeStubModel::query()->create(['parent_id' => $middle->getKey()]);
    $leaf_b = ClosureTreeStubModel::query()->create(['parent_id' => $middle->getKey()]);

    MutatorCountingClosureTreeStubModel::$mutatedKeys = [];
    $nodes = MutatorCountingClosureTreeStubModel::query()->whereKey([$leaf_a->getKey(), $leaf_b->getKey()])->orderBy('id')->get();
    MutatorCountingClosureTreeStubModel::$mutatedKeys = [];
    $nodes->load('ancestors');

    expect(MutatorCountingClosureTreeStubModel::$mutatedKeys)->not->toContain('depth')
        ->and($nodes[0]->ancestors->pluck('depth')->all())->toBe([-1, -2])
        ->and($nodes[1]->ancestors->pluck('depth')->all())->toBe([-1, -2])
        ->and($nodes[0]->ancestors->first()->isDirty('depth'))->toBeFalse();
});
