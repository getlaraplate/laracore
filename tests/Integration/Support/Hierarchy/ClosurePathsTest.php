<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Support\Hierarchy\ClosurePaths;
use Modules\Core\Tests\Stubs\ClosureTreeStubModel;
use Modules\Core\Tests\Stubs\MutatorCountingClosureTreeStubModel;

beforeEach(function (): void {
    Schema::dropIfExists('closure_tree_nodes_closure');
    Schema::dropIfExists('closure_tree_nodes');

    Schema::create('closure_tree_nodes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->nullable()->constrained('closure_tree_nodes')->nullOnDelete();
        $table->unsignedInteger('position')->nullable();
        $table->timestamps();
    });

    MigrateUtils::closureTable('closure_tree_nodes');

    // 1 → 2 → 3 → 4, and 5 under 1.
    $this->nodes = [];

    foreach ([null, 1, 2, 3, 1] as $parent) {
        $this->nodes[] = ClosureTreeStubModel::query()->create(['parent_id' => $parent === null ? null : $this->nodes[$parent - 1]->id]);
    }
});

/**
 * The `[key, depth, path]` of each node the relation loaded on each starting node.
 *
 * @param  list<int>  $hidden  Keys the eager load leaves out, as the caller's ACL would
 * @return array<int, list<array{0: int, 1: int, 2: string}>>
 */
function closure_paths_of(string $relation, array $hidden = []): array
{
    $starting_nodes = ClosureTreeStubModel::query()
        ->with([$relation => static function (Relation $nodes) use ($hidden): void {
            $nodes->getQuery()->whereNotIn($nodes->getRelated()->getQualifiedKeyName(), $hidden);
        }])
        ->orderBy('id')
        ->get();

    ClosurePaths::forModel(new ClosureTreeStubModel)->assign($starting_nodes, $relation);

    return $starting_nodes
        ->mapWithKeys(static fn (ClosureTreeStubModel $node): array => [
            $node->id => $node->getRelation($relation)
                ->map(static fn (ClosureTreeStubModel $related): array => [$related->id, $related->depth, $related->path])
                ->all(),
        ])
        ->all();
}

it('joins the keys from the starting node down to each descendant', function (): void {
    expect(closure_paths_of('descendantsAndSelf')[1])->toBe([
        [1, 0, '1'], [2, 1, '1.2'], [5, 1, '1.5'], [3, 2, '1.2.3'], [4, 3, '1.2.3.4'],
    ]);
});

it('joins the keys from the starting node up to each ancestor', function (): void {
    expect(closure_paths_of('ancestorsAndSelf')[4])->toBe([
        [4, 0, '4'], [3, -1, '4.3'], [2, -2, '4.3.2'], [1, -3, '4.3.2.1'],
    ]);
});

it('keeps a hidden middle node in the path of the nodes beyond it', function (): void {
    $paths = closure_paths_of('bloodline', hidden: [2]);

    expect($paths[1])->toBe([[1, 0, '1'], [5, 1, '1.5'], [3, 2, '1.2.3'], [4, 3, '1.2.3.4']])
        ->and($paths[4])->toBe([[4, 0, '4'], [3, -1, '4.3'], [1, -3, '4.3.2.1']]);
});

it('reads no closure rows when the loaded rows give every path above and below the starting nodes', function (): void {
    $starting_nodes = ClosureTreeStubModel::query()->with('bloodline')->orderBy('id')->get();

    DB::enableQueryLog();
    ClosurePaths::forModel(new ClosureTreeStubModel)->assign($starting_nodes, 'bloodline');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($starting_nodes[2]->bloodline->map(static fn (ClosureTreeStubModel $node): array => [$node->id, $node->depth, $node->path])->all())
        ->toBe([[3, 0, '3'], [4, 1, '3.4'], [2, -1, '3.2'], [1, -2, '3.2.1']]);
});

it('reads no closure rows for a tree with children, nothing hidden', function (): void {
    $starting_nodes = ClosureTreeStubModel::query()->with('descendantsAndSelf')->orderBy('id')->get();

    DB::enableQueryLog();
    ClosurePaths::forModel(new ClosureTreeStubModel)->assign($starting_nodes, 'descendantsAndSelf');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($starting_nodes->mapWithKeys(static fn (ClosureTreeStubModel $node): array => [
            $node->id => $node->descendantsAndSelf->map(static fn (ClosureTreeStubModel $related): array => [$related->id, $related->depth, $related->path])->all(),
        ])->all())
        ->toBe([
            1 => [[1, 0, '1'], [2, 1, '1.2'], [5, 1, '1.5'], [3, 2, '1.2.3'], [4, 3, '1.2.3.4']],
            2 => [[2, 0, '2'], [3, 1, '2.3'], [4, 2, '2.3.4']],
            3 => [[3, 0, '3'], [4, 1, '3.4']],
            4 => [[4, 0, '4']],
            5 => [[5, 0, '5']],
        ]);
});

it('reads the closure rows of the nodes below a hidden one and keeps the hidden one in their paths', function (): void {
    DB::enableQueryLog();
    $paths = closure_paths_of('descendantsAndSelf', hidden: [2]);
    $closure_reads = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'closure_tree_nodes_closure') && ! str_contains($query['query'], 'inner join'))->count();
    DB::disableQueryLog();

    expect($paths[1])->toBe([[1, 0, '1'], [5, 1, '1.5'], [3, 2, '1.2.3'], [4, 3, '1.2.3.4']])
        ->and($closure_reads)->toBe(1);
});

it('reads the closure rows when the loaded nodes lack the parent column', function (): void {
    $starting_nodes = ClosureTreeStubModel::query()
        ->with(['descendantsAndSelf' => static function (Relation $nodes): void {
            $nodes->getQuery()->select($nodes->getRelated()->getQualifiedKeyName());
        }])
        ->whereKey(1)
        ->get();

    DB::enableQueryLog();
    ClosurePaths::forModel(new ClosureTreeStubModel)->assign($starting_nodes, 'descendantsAndSelf');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($starting_nodes[0]->descendantsAndSelf->map(static fn (ClosureTreeStubModel $node): array => [$node->id, $node->depth, $node->path])->all())
        ->toBe([[1, 0, '1'], [2, 1, '1.2'], [5, 1, '1.5'], [3, 2, '1.2.3'], [4, 3, '1.2.3.4']])
        ->and($queries)->toBe(1);
});

it('reads no closure rows when the loaded ancestors already give every path above the starting nodes', function (): void {
    $starting_nodes = ClosureTreeStubModel::query()->with('ancestorsAndSelf')->orderBy('id')->get();

    DB::enableQueryLog();
    ClosurePaths::forModel(new ClosureTreeStubModel)->assign($starting_nodes, 'ancestorsAndSelf');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($starting_nodes[3]->ancestorsAndSelf->map(static fn (ClosureTreeStubModel $node): array => [$node->id, $node->depth, $node->path])->all())
        ->toBe([[4, 0, '4'], [3, -1, '4.3'], [2, -2, '4.3.2'], [1, -3, '4.3.2.1']]);
});

it('reads no closure rows for the ancestors without the node itself either', function (): void {
    DB::enableQueryLog();
    $paths = closure_paths_of('ancestors');
    $closure_reads = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'closure_tree_nodes_closure') && ! str_contains($query['query'], 'inner join'))->count();
    DB::disableQueryLog();

    expect($paths[4])->toBe([[3, -1, '4.3'], [2, -2, '4.3.2'], [1, -3, '4.3.2.1']])
        ->and($closure_reads)->toBe(0);
});

it('reads the closure rows of a starting node whose loaded ancestors miss a hidden one', function (): void {
    DB::enableQueryLog();
    $paths = closure_paths_of('ancestorsAndSelf', hidden: [2]);
    $closure_reads = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'closure_tree_nodes_closure') && ! str_contains($query['query'], 'inner join'))->count();
    DB::disableQueryLog();

    expect($paths[4])->toBe([[4, 0, '4'], [3, -1, '4.3'], [1, -3, '4.3.2.1']])
        ->and($closure_reads)->toBe(1);
});

it('follows the parent column below the starting node when the closure lacks a link', function (): void {
    DB::table('closure_tree_nodes_closure')->where('ancestor_id', 2)->where('descendant_id', 4)->delete();

    expect(closure_paths_of('descendantsAndSelf')[1])->toContain([4, 3, '1.2.3.4']);
});

it('reads the closure rows when the parent column disagrees with the closure depths', function (): void {
    DB::table('closure_tree_nodes')->where('id', 4)->update(['parent_id' => 5]);

    expect(closure_paths_of('descendantsAndSelf')[1])->toContain([4, 3, '1.2.3.4']);
});

it('falls back to the two ends when the closure lacks a link the walk needs', function (): void {
    DB::table('closure_tree_nodes_closure')->where('ancestor_id', 2)->where('descendant_id', 4)->delete();

    expect(closure_paths_of('descendantsAndSelf', hidden: [3])[1])->toContain([4, 3, '1.4']);
});

it('leaves alone the starting nodes that did not load the relation', function (): void {
    $starting_nodes = ClosureTreeStubModel::query()->orderBy('id')->get();

    ClosurePaths::forModel(new ClosureTreeStubModel)->assign($starting_nodes, 'descendants');

    expect($starting_nodes->every(static fn (ClosureTreeStubModel $node): bool => ! $node->relationLoaded('descendants') && ! array_key_exists('path', $node->getAttributes())))->toBeTrue();
});

it('writes the path without going through the model mutators and leaves the node clean', function (): void {
    $starting_nodes = MutatorCountingClosureTreeStubModel::query()->with('descendantsAndSelf')->whereKey($this->nodes[0]->id)->get();
    MutatorCountingClosureTreeStubModel::$mutatedKeys = [];

    ClosurePaths::forModel(new MutatorCountingClosureTreeStubModel)->assign($starting_nodes, 'descendantsAndSelf');

    $nodes = $starting_nodes->first()->descendantsAndSelf;

    expect(MutatorCountingClosureTreeStubModel::$mutatedKeys)->not->toContain('path')
        ->and($nodes->pluck('path')->all())->toBe(['1', '1.2', '1.5', '1.2.3', '1.2.3.4'])
        ->and($nodes->contains(static fn (MutatorCountingClosureTreeStubModel $node): bool => $node->isDirty()))->toBeFalse();
});
