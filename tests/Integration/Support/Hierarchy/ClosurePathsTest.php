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

it('reads the chains of every starting node in one query', function (): void {
    $starting_nodes = ClosureTreeStubModel::query()->with('bloodline')->get();

    DB::enableQueryLog();
    ClosurePaths::forModel(new ClosureTreeStubModel)->assign($starting_nodes, 'bloodline');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

it('falls back to the two ends when the closure lacks a link', function (): void {
    DB::table('closure_tree_nodes_closure')->where('ancestor_id', 2)->where('descendant_id', 4)->delete();

    expect(closure_paths_of('descendantsAndSelf')[1])->toContain([4, 3, '1.4']);
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
