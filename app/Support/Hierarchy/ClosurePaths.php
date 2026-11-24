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
 * The path is read from the closure rows: a node a scope or the caller's ACL hides still stands in the path of the
 * nodes beyond it. Above a starting node, the rows the relation itself loaded give the path when they hold a node at
 * every distance up to the one reached, so the ancestors of a node, nothing hidden, read nothing more. The other paths
 * take one query per {@see self::KEY_CHUNK_SIZE} nodes, whatever the number of starting nodes; it reads the ancestors
 * of each node, at most its depth.
 */
final readonly class ClosurePaths
{
    /**
     * Node keys bound per query, below the 999 variables of older SQLite builds.
     */
    private const int KEY_CHUNK_SIZE = 500;

    public function __construct(
        private ConnectionInterface $connection,
        private string $closureTable,
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

        return new self($model->getConnection(), $closure_table);
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
                }
            }
        }

        if ($walks === []) {
            return;
        }

        // Above the starting node the path climbs its own chain, which the relation has loaded when it holds a node
        // at every distance up to the one reached; below it, the path is the chain of the node reached, read here.
        $chains = [];

        foreach ($walks as $walk) {
            $distance = -$walk['depth'];

            if ($distance >= 0 && self::holdsEveryDistance($above[$walk['from']] ?? [], $distance)) {
                $chains[$walk['from']] ??= [0 => $walk['from']] + ($above[$walk['from']] ?? []);
            } else {
                $keys[$walk['depth'] < 0 ? $walk['from'] : $walk['to']] = true;
            }
        }

        // Keyed by node keys, numeric: merged key by key, never renumbered.
        foreach ($keys === [] ? [] : $this->chainsOf(array_map(strval(...), array_keys($keys))) as $key => $chain) {
            $chains[$key] = $chain;
        }

        foreach ($walks as $walk) {
            $node = $walk['node'];
            // Raw, not setAttribute(): a model's mutators may do work per node (Taxonomy's dynamic-field lookup).
            $node->setRawAttributes([...$node->getAttributes(), 'path' => implode('.', self::pathOf($walk['from'], $walk['to'], $walk['depth'], $chains))]);
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
     * The keys from the starting node to the node reached. A chain the closure table lacks, which only drift
     * leaves, falls back to the two ends.
     *
     * @param  array<string, array<int, string>>  $chains
     * @return list<string>
     */
    private static function pathOf(string $from, string $to, int $depth, array $chains): array
    {
        $chain = $chains[$depth < 0 ? $from : $to] ?? [];
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
