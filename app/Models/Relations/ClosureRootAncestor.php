<?php

declare(strict_types=1);

namespace Modules\Core\Models\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;
use Override;

/**
 * The root of a node's tree, through its closure table: the one ancestor that has no ancestor of its own. Null for
 * a root, and null when a global scope hides the root.
 *
 * A single-result view of the node's ancestors: the walk, its eager loading and its signed `depth` are those of
 * the {@see ClosureLineage} it wraps, which shares this relation's query. The root is told apart on the closure
 * table alone, so the condition holds in an existence query too, where the model table is aliased.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, TRelatedModel|null>
 */
final class ClosureRootAncestor extends Relation
{
    /**
     * @param  ClosureLineage<TRelatedModel, TDeclaringModel>  $ancestors  The node's ancestors, the node excluded
     */
    public function __construct(private readonly ClosureLineage $ancestors)
    {
        parent::__construct($ancestors->getQuery(), $ancestors->getParent());

        $closure_table = $ancestors->getTable();
        $alias = $closure_table . '_above';
        $ancestor = $closure_table . '.ancestor_id';

        $ancestors->getQuery()->whereNotExists(static function (QueryBuilder $above) use ($closure_table, $alias, $ancestor): void {
            $above->selectRaw('1')
                ->from($closure_table . ' as ' . $alias)
                ->whereColumn($alias . '.descendant_id', '=', $ancestor)
                ->where($alias . '.depth', '>', 0);
        });
    }

    /**
     * The constraints are those of the wrapped ancestors.
     */
    #[Override]
    public function addConstraints(): void {}

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    #[Override]
    public function addEagerConstraints(array $models): void
    {
        $this->ancestors->addEagerConstraints($models);
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @return array<int, TDeclaringModel>
     */
    #[Override]
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  EloquentCollection<int, TRelatedModel>  $results
     * @return array<int, TDeclaringModel>
     */
    #[Override]
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        $this->ancestors->match($models, $results, $relation);

        foreach ($models as $model) {
            $roots = $model->getRelation($relation);
            $model->setRelation($relation, $roots instanceof EloquentCollection ? $roots->first() : null);
        }

        return $models;
    }

    /**
     * @return TRelatedModel|null
     */
    #[Override]
    public function getResults(): ?Model
    {
        if ($this->parent->getAttribute($this->ancestors->getParentKeyName()) === null) {
            return null;
        }

        return $this->ancestors->first();
    }

    /**
     * The root ancestor is read whole: the walk selects the model's columns with the closure row it needs.
     *
     * @param  array<int, string>  $columns
     *
     * @throws LogicException When columns are chosen
     *
     * @return EloquentCollection<int, TRelatedModel>
     */
    #[Override]
    public function get($columns = ['*']): EloquentCollection
    {
        if ($columns !== ['*']) {
            throw new LogicException('The root ancestor relation selects its own columns; constrain the query instead.');
        }

        return $this->ancestors->get();
    }

    /**
     * The existence query of the wrapped ancestors, selecting what the caller asks: `*`, or the count expression
     * of `has()` with an operator and of `withCount()`.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<int, string>|string|Expression  $columns
     * @return Builder<TRelatedModel>
     */
    #[Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        return $this->ancestors->getRelationExistenceQuery($query, $parentQuery)->select($columns);
    }
}
