<?php

declare(strict_types=1);

namespace Modules\Core\Support\Hierarchy;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The `path` of the nodes a closure relation loaded: the keys from the node the walk started at to each node,
 * joined by dots. Below node 1, its grandchild 4 reached through 2 reads `1.2.4`; above node 4, the same
 * grandparent reads `4.2.1`; the node itself reads its own key. The tree payload of
 * {@see \Modules\Core\Services\Crud\CrudService::tree()} carries it next to the signed `depth` of each node.
 *
 * The rows the relation itself loaded give the path when they hold every node on it, so a tree with nothing hidden
 * reads nothing more. Above a starting node they do when they hold a node at every distance up to the one reached.
 * Below it, each node links to its parent through the raw parent column, and the chain counts only when every step
 * lands on a node the same starting node loaded, one level closer, and the last on the starting node itself: under
 * closure drift that the depths do not expose, the path follows the parent column, which the closure is built from.
 * Any gap, a node a scope or the caller's ACL hides or a selection without the parent column, reads the path from the
 * closure rows, so a hidden node still stands in the path of the nodes beyond it: one query per
 * {@see self::KEY_CHUNK_SIZE} such nodes, whatever the number of starting nodes; it reads the ancestors of each node,
 * at most its depth.
 */
final readonly class ClosurePaths
{
    /**
     * Node keys bound per query, below the 999 variables of older SQLite builds.
     */
    public const int KEY_CHUNK_SIZE = 500;

    /**
     * @param  string|null  $parentKeyName  The parent column linking the loaded nodes below a starting node; without it
     *                                      every path below reads the closure rows
     */
    public function __construct(
        private ConnectionInterface $connection,
        private string $closureTable,
        private ?string $parentKeyName = null,
    ) {}

    /**
     * Paths on the model's connection and closure table.
     *
     * @throws LogicException When the model does not use {@see \Modules\Core\Models\Concerns\HasClosureTable}
     */
    public static function forModel(Model $model): self
    {
        if (! method_exists($model, 'getClosureTable')) {
            throw new LogicException(sprintf('Model [%s] has no closure table: it must use HasClosureTable.', $model::class));
        }

        $closure_table = $model->getClosureTable();

        if (! is_string($closure_table)) {
            throw new LogicException(sprintf('Model [%s] names no closure table.', $model::class));
        }

        $parent_key_name = method_exists($model, 'getParentKeyName') ? $model->getParentKeyName() : null;

        return new self($model->getConnection(), $closure_table, is_string($parent_key_name) ? $parent_key_name : null);
    }

    /**
     * Set `path` on every node of the relation each starting node has loaded. Nodes without the relation loaded,
     * and loaded nodes without a numeric `depth`, are left as they are.
     *
     * @param  iterable<array-key, Model>  $startingNodes
     */
    public function assign(iterable $startingNodes, string $relation): void
    {
        /** @var list<array{node: Model, from: string, to: string, depth: int}> $walks */
        $walks = [];
        $keys = [];

        /** @var array<string, array<int, string>> $above */
        $above = [];

        /** @var array<string, array<string, array{depth: int, parent: string|null}>> $below */
        $below = [];

        foreach ($startingNodes as $starting_node) {
            $from = self::keyOf($starting_node->getKey());

            if ($from === null || ! $starting_node->relationLoaded($relation)) {
                continue;
            }

            $nodes = $starting_node->getRelation($relation);

            if (! is_iterable($nodes)) {
                continue;
            }

            foreach ($nodes as $node) {
                if (! $node instanceof Model) {
                    continue;
                }

                $to = self::keyOf($node->getKey());
                $depth = $node->getAttribute('depth');

                if ($to === null || ! is_numeric($depth)) {
                    continue;
                }

                $depth = (int) $depth;
                $walks[] = ['node' => $node, 'from' => $from, 'to' => $to, 'depth' => $depth];

                if ($depth <= 0) {
                    $above[$from][-$depth] = $to;
                } else {
                    // Raw, not getAttribute(): no accessor runs per node.
                    $below[$from][$to] = [
                        'depth' => $depth,
                        'parent' => $this->parentKeyName === null ? null : self::keyOf($node->getAttributes()[$this->parentKeyName] ?? null),
                    ];
                }
            }
        }

        if ($walks === []) {
            return;
        }

        // Each walk's chain from the rows its starting node loaded: above, the starting node's own chain; below, the
        // chain of the node reached. A walk whose loaded rows leave a gap reads that chain from the closure rows.
        /** @var array<int, array<int, string>> $loaded */
        $loaded = [];

        /** @var array<string, array<int, string>> $chains_above */
        $chains_above = [];

        foreach ($walks as $index => $walk) {
            $from = $walk['from'];

            if ($walk['depth'] <= 0) {
                if (self::holdsEveryDistance($above[$from] ?? [], -$walk['depth'])) {
                    $loaded[$index] = $chains_above[$from] ??= [0 => $from] + ($above[$from] ?? []);
                } else {
                    $keys[$from] = true;
                }

                continue;
            }

            $chain = self::chainBelow($from, $walk['to'], $walk['depth'], $below[$from] ?? []);

            if ($chain === null) {
                $keys[$walk['to']] = true;
            } else {
                $loaded[$index] = $chain;
            }
        }

        // Keyed by node keys, numeric: never renumbered.
        $chains = $keys === [] ? [] : $this->chainsOf(array_map(strval(...), array_keys($keys)));

        foreach ($walks as $index => $walk) {
            $node = $walk['node'];
            $chain = $loaded[$index] ?? $chains[$walk['depth'] < 0 ? $walk['from'] : $walk['to']] ?? [];
            // Raw, not setAttribute(): a model's mutators may do work per node (Taxonomy's dynamic-field lookup).
            $node->setRawAttributes([...$node->getAttributes(), 'path' => implode('.', self::pathOf($walk['from'], $walk['to'], $walk['depth'], $chain))]);
            $node->syncOriginalAttribute('path');
        }
    }

    /**
     * Each node's ancestors and itself, by distance: index 0 is the node, 1 its parent, and so on. Read from the
     * closure rows, so an ancestor a scope hides still has its place. A node without closure rows is left out.
     *
     * @param  list<string>  $keys
     * @return array<string, array<int, string>>
     */
    public function chainsOf(array $keys): array
    {
        $chains = [];

        foreach (array_chunk($keys, self::KEY_CHUNK_SIZE) as $chunk) {
            $rows = $this->connection->table($this->closureTable)
                ->whereIn('descendant_id', $chunk)
                ->get(['ancestor_id', 'descendant_id', 'depth']);

            foreach ($rows as $row) {
                $ancestor = self::keyOf($row->ancestor_id ?? null);
                $descendant = self::keyOf($row->descendant_id ?? null);
                $distance = $row->depth ?? null;

                if ($ancestor === null || $descendant === null || ! is_numeric($distance)) {
                    continue;
                }

                $chains[$descendant][(int) $distance] = $ancestor;
            }
        }

        return $chains;
    }

    /**
     * The keys from the starting node to the node reached, from the chain by distance of the lower of the two. A
     * chain that lacks a link, which only drift leaves, falls back to the two ends.
     *
     * @param  array<int, string>  $chain
     * @return list<string>
     */
    private static function pathOf(string $from, string $to, int $depth, array $chain): array
    {
        $distance = abs($depth);
        $path = [];

        for ($step = 0; $step <= $distance; $step++) {
            if (! isset($chain[$step])) {
                return $from === $to ? [$from] : [$from, $to];
            }

            $path[] = $chain[$step];
        }

        return $depth < 0 ? $path : array_reverse($path);
    }

    /**
     * The chain by distance of a node a starting node reached below itself, from the nodes that starting node loaded
     * below itself linked by their parent column: index 0 is the node, the last the starting node. Null when a step
     * leaves the loaded nodes, lands on one whose depth is not one less, or the last step misses the starting node.
     *
     * @param  array<string, array{depth: int, parent: string|null}>  $loaded  The loaded nodes below the starting node, by key
     * @return array<int, string>|null
     */
    private static function chainBelow(string $from, string $to, int $depth, array $loaded): ?array
    {
        // The closure row at depth 1 is the link itself.
        if ($depth === 1) {
            return [0 => $to, 1 => $from];
        }

        $chain = [0 => $to];
        $current = $to;

        for ($step = 1; $step <= $depth; $step++) {
            $parent = $loaded[$current]['parent'] ?? null;

            if ($parent === null) {
                return null;
            }

            if ($step === $depth ? $parent !== $from : ($loaded[$parent]['depth'] ?? null) !== $depth - $step) {
                return null;
            }

            $chain[$step] = $parent;
            $current = $parent;
        }

        return $chain;
    }

    /**
     * Whether the nodes loaded above a starting node, by distance, hold one at every distance from 1 to the one given.
     *
     * @param  array<int, string>  $chain
     */
    private static function holdsEveryDistance(array $chain, int $distance): bool
    {
        for ($step = 1; $step <= $distance; $step++) {
            if (! isset($chain[$step])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A key as a string, or null when the value is not one.
     */
    private static function keyOf(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? (string) $value : null;
    }
}
