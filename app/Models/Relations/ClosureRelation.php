<?php

declare(strict_types=1);

namespace Modules\Core\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use LogicException;
use Override;
use WeakMap;

/**
 * A walk of a closure table (`ancestor_id`, `descendant_id`, `depth`, one row per ancestor and descendant pair, the
 * node itself at depth 0) from a node to other nodes of the same model.
 *
 * Every node it loads carries `depth` signed from the node the walk starts at: negative above it, `0` for the node
 * itself, positive below. The closure row is read and then dropped: no `pivot` is left on the node, so none reaches
 * a payload. The node each row belongs to is worked out from the row itself, which lets one query serve many
 * starting nodes when the relation is eager loaded.
 *
 * Global scopes of the model apply to the nodes returned, not to the walk: a node hidden by a scope hides only
 * itself, the nodes beyond it stay. The key of the nodes is the last order, after any order a global scope adds,
 * so nodes that tie on every other order come back in the same order on every database.
 *
 * The closure rows are written only by {@see \Modules\Core\Support\Hierarchy\ClosureTableMaintainer}, through the
 * model events of {@see \Modules\Core\Models\Concerns\HasClosureTable}: every pivot write of the relation throws.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
abstract class ClosureRelation extends BelongsToMany
{
    /**
     * The key of the node each loaded node was reached from, read when eager loaded nodes are matched.
     *
     * @var WeakMap<Model, int|string>
     */
    private WeakMap $startingNodes;

    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $closureTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $relationName,
    ) {
        $this->startingNodes = new WeakMap;

        parent::__construct(
            $query,
            $parent,
            $closureTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parent->getKeyName(),
            $parent->getKeyName(),
            $relationName,
        );

        $this->withPivot('depth');

        $related_key = $this->getQualifiedRelatedKeyName();

        // Run when the query is compiled, after the global scopes have added their orders. An aggregate (a count,
        // the total of a paginator) needs no order.
        $this->query->getQuery()->beforeQuery(static function (QueryBuilder $query) use ($related_key): void {
            if ($query->aggregate === null) {
                $query->orderBy($related_key);
            }
        });
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function attach($ids, array $attributes = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function attachOrFail($ids, array $attributes = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function detach($ids = null, $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function detachOrFail($ids = null, $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function sync($ids, $detaching = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function syncOrFail($ids, $detaching = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function syncWithoutDetaching($ids): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function syncWithoutDetachingOrFail($ids): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     * @param  array<string, mixed>  $values
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function syncWithPivotValues($ids, array $values, bool $detaching = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  Collection<array-key, mixed>|Model|array<array-key, mixed>|int|string  $ids
     * @param  array<string, mixed>  $values
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function syncWithPivotValuesOrFail($ids, array $values, bool $detaching = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function toggle($ids, $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function toggleOrFail($ids, $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function updateExistingPivot($id, array $attributes, $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws LogicException Always: the closure rows are written by the maintainer alone
     */
    #[Override]
    public function updateExistingPivotOrFail($id, array $attributes, $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function save(Model $model, array $pivotAttributes = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function saveQuietly(Model $model, array $pivotAttributes = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<array-key, mixed>  $pivotAttributes
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function saveMany($models, array $pivotAttributes = []): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<array-key, mixed>  $pivotAttributes
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function saveManyQuietly($models, array $pivotAttributes = []): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $joining
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function create(array $attributes = [], array $joining = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $joining
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function createQuietly(array $attributes = [], array $joining = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  iterable<array-key, array<string, mixed>>  $records
     * @param  array<array-key, mixed>  $joinings
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function createMany(iterable $records, array $joinings = []): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  iterable<array-key, array<string, mixed>>  $records
     * @param  array<array-key, mixed>  $joinings
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function createManyQuietly(iterable $records, array $joinings = []): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $joining
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function forceCreate(array $attributes = [], array $joining = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $joining
     *
     * @throws LogicException Always: a node is placed by its parent column, not by a pivot row
     */
    #[Override]
    public function forceCreateQuietly(array $attributes = [], array $joining = [], $touch = true): never
    {
        $this->refusePivotWrite(__FUNCTION__);
    }

    /**
     * Turn each closure row into the signed `depth` of its node, remember which node the row starts from, and drop
     * the pivot.
     *
     * @param  array<int, TRelatedModel>  $models
     */
    #[Override]
    protected function hydratePivotRelation(array $models): void
    {
        parent::hydratePivotRelation($models);

        foreach ($models as $model) {
            $pivot = $model->getRelation($this->accessor);
            $model->unsetRelation($this->accessor);

            if (! $pivot instanceof Model) {
                continue;
            }

            $key = self::keyOf($model->getAttribute($this->relatedKey));
            $ancestor = self::keyOf($pivot->getAttribute('ancestor_id'));
            $descendant = self::keyOf($pivot->getAttribute('descendant_id'));
            $distance = $pivot->getAttribute('depth');
            $distance = is_numeric($distance) ? (int) $distance : 0;

            if ($key === null || $ancestor === null || $descendant === null) {
                continue;
            }

            [$starting_node, $depth] = match (true) {
                (string) $key === (string) $ancestor && (string) $key === (string) $descendant => [$key, 0],
                (string) $key === (string) $descendant => [$ancestor, $distance],
                default => [$descendant, -$distance],
            };

            $this->startingNodes[$model] = $starting_node;
            $model->setAttribute('depth', $depth);
            $model->syncOriginalAttribute('depth');
        }
    }

    /**
     * Group the loaded nodes by the node they were reached from.
     *
     * @param  EloquentCollection<int, TRelatedModel>  $results
     * @return array<array<array-key, TRelatedModel>>
     */
    #[Override]
    protected function buildDictionary(EloquentCollection $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            if (! isset($this->startingNodes[$result])) {
                continue;
            }

            $dictionary[$this->startingNodes[$result]][] = $result;
        }

        return $dictionary;
    }

    /**
     * A key read from a row, or null when the value is not one.
     */
    private static function keyOf(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @throws LogicException Always
     */
    private function refusePivotWrite(string $method): never
    {
        throw new LogicException(sprintf(
            'The %s relation of %s is read from its closure table; %s() is refused: the closure rows are written only from the parent column, through the model events.',
            $this->relationName,
            $this->parent::class,
            $method,
        ));
    }
}
