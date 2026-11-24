<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Groups flat closure-table rows into a tree.
 *
 * A node is any object carrying the closure-table columns: an Eloquent model of a
 * {@see \Modules\Core\Models\Concerns\HasClosureTable} hierarchy, wrapped here
 * explicitly, or a stdClass when it comes from a raw query. `children` and `total_articles_count`
 * are written here onto whichever object arrived, so no declared type
 * describes a node and the members stay dynamic.
 */
final class TreeCollection extends EloquentCollection
{
    private const string ROOT_KEY = '__root__';

    public function tree(): self
    {
        /** @var Collection<int|string, Collection<int, object>> $grouped */
        $grouped = $this->groupBy(fn (object $item): int|string => $item->parent_id ?? self::ROOT_KEY);

        $built = self::buildTree($grouped, self::ROOT_KEY);

        return new self($built->all());
    }

    /**
     * Build a tree structure from grouped items.
     *
     * @param  Collection<int|string, Collection<int, object>>  $grouped
     * @return Collection<int, object>
     */
    private static function buildTree(Collection $grouped, int|string $parentId): Collection
    {
        return ($grouped[$parentId] ?? collect())->map(function (object $item) use ($grouped): object {
            $item->children = self::buildTree($grouped, $item->id)->values();

            if (isset($item->articles_count)) {
                $item->total_articles_count = $item->articles_count + $item->children->sum('total_articles_count');
            }

            return $item;
        });
    }
}
