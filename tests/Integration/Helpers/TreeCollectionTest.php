<?php

declare(strict_types=1);

use Modules\Core\Helpers\TreeCollection;

it('builds a tree with children and aggregated article counts', function (): void {
    $items = [
        (object) ['id' => 1, 'parent_id' => null, 'name' => 'Root', 'articles_count' => 1],
        (object) ['id' => 2, 'parent_id' => 1, 'name' => 'Child A', 'articles_count' => 2],
        (object) ['id' => 3, 'parent_id' => 1, 'name' => 'Child B', 'articles_count' => 3],
        (object) ['id' => 4, 'parent_id' => 2, 'name' => 'Grandchild', 'articles_count' => 4],
    ];

    $tree_collection = new TreeCollection($items);

    $tree = $tree_collection->tree();

    expect($tree)->toHaveCount(1);

    $root = $tree->first();

    expect($root->children)->toHaveCount(2)
        ->and($root->total_articles_count)->toBe(1 + 2 + 3 + 4);

    $child_a = $root->children->firstWhere('name', 'Child A');

    expect($child_a->children)->toHaveCount(1)
        ->and($child_a->total_articles_count)->toBe(2 + 4);
});
