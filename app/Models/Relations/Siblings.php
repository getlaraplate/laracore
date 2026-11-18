<?php

declare(strict_types=1);

namespace Modules\Core\Models\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * The nodes that share a node's parent, the node itself included or not. The siblings of a root are the other
 * roots, as with the adjacency list.
 *
 * Read from the parent column alone: the node's parent value is matched against the same column of the other nodes,
 * a null value against null.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
final class Siblings extends HasMany
{
    /**
     * The dictionary key of the roots, which have no parent value to be keyed by.
     */
    private const string ROOTS = "\0roots";

    /**
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @param  string  $parentKeyName  The parent column, on the node and on its siblings
     * @param  bool  $andSelf  Include the node itself
     */
    public function __construct(Builder $query, Model $parent, string $parentKeyName, private readonly bool $andSelf)
    {
        parent::__construct($query, $parent, $query->getModel()->qualifyColumn($parentKeyName), $parentKeyName);
    }

    #[Override]
    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $this->getRelationQuery()->where($this->foreignKey, '=', $this->getParentKey());

        if (! $this->andSelf && $this->parent->getKey() !== null) {
            $this->getRelationQuery()->where($this->related->getQualifiedKeyName(), '<>', $this->parent->getKey());
        }
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    #[Override]
    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->localKey);
        $parent_values = array_values(array_filter($keys, static fn (mixed $key): bool => $key !== null));
        $has_roots = count($parent_values) < count($keys);

        $this->getRelationQuery()->where(function (Builder $query) use ($parent_values, $has_roots): void {
            $query->whereIn($this->foreignKey, $parent_values);

            if ($has_roots) {
                $query->orWhereNull($this->foreignKey);
            }
        });
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  EloquentCollection<int, TRelatedModel>  $results
     * @return array<int, TDeclaringModel>
     */
    #[Override]
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$this->dictionaryKeyOf($result->getAttribute($this->localKey))][] = $result;
        }

        foreach ($models as $model) {
            $siblings = $dictionary[$this->dictionaryKeyOf($model->getAttribute($this->localKey))] ?? [];

            if (! $this->andSelf) {
                $siblings = array_filter($siblings, static fn (Model $sibling): bool => ! $sibling->is($model));
            }

            $model->setRelation($relation, $this->related->newCollection(array_values($siblings)));
        }

        return $models;
    }

    /**
     * The siblings, the other roots included for a root: the base relation returns nothing for a null parent value.
     *
     * @return EloquentCollection<int, TRelatedModel>
     */
    #[Override]
    public function getResults(): EloquentCollection
    {
        return $this->query->get();
    }

    /**
     * The siblings of each row of the parent query, for `has()`, `whereHas()` and `withCount()`: the parent values
     * match, two nulls included. The model table is aliased when the parent query reads it too.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<int, string>|string|Expression  $columns  `*`, or the count expression of `has()` with an operator and of `withCount()`
     * @return Builder<TRelatedModel>
     */
    #[Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from === $query->getQuery()->from) {
            $hash = $this->getRelationCountHash();
            $query->from($query->getModel()->getTable() . ' as ' . $hash);
            $query->getModel()->setTable($hash);
        }

        $sibling_parent = $query->getModel()->qualifyColumn($this->localKey);
        $node_parent = $this->parent->qualifyColumn($this->localKey);

        $query->select($columns)->where(static function (Builder $same_parent) use ($sibling_parent, $node_parent): void {
            $same_parent->whereColumn($sibling_parent, '=', $node_parent)
                ->orWhere(static function (Builder $both_roots) use ($sibling_parent, $node_parent): void {
                    $both_roots->whereNull($sibling_parent)->whereNull($node_parent);
                });
        });

        if (! $this->andSelf) {
            $query->whereColumn($query->getModel()->getQualifiedKeyName(), '<>', $this->parent->getQualifiedKeyName());
        }

        return $query;
    }

    private function dictionaryKeyOf(mixed $parentValue): int|string
    {
        $key = $this->getDictionaryKey($parentValue);

        return $key ?? self::ROOTS;
    }
}
