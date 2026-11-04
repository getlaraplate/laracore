<?php

declare(strict_types=1);

namespace Modules\Core\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Support\RelationGuard;

/**
 * Resolves the chain of a part of its parent ({@see IsPartOfParent}): every model up its parent relations, the
 * intermediate parts first and the first owner that is not itself a part last, as
 * {@see \Modules\Core\Services\Crud\RelationAuthorizer} walks it to decide the visibility of a part loaded from any
 * step of that chain.
 *
 * The answer depends on the part's class alone (its declared parent relation and the related class), never on
 * the record or on the acting user, so it is memoized per class for the life of the process: climbing the chain
 * builds a relation and a parent instance per step, which a list of thousands of lines would otherwise pay per row.
 */
final class PartOwnerResolver
{
    /**
     * How far a chain of parts is climbed to find its owner.
     */
    public const int MAX_DEPTH = 8;

    /**
     * @var array<class-string<Model>, non-empty-list<class-string<Model>>|null>
     */
    private static array $chains = [];

    /**
     * The classes up the part's chain of parent relations, nearest first: each intermediate part, then the first
     * owner that is not a part. Null when the model is not a part, or when its chain does not resolve to relations
     * to one parent model each (an undeclared or morph relation, a cycle, a chain longer than
     * {@see self::MAX_DEPTH}): the caller then keeps the model's own permission in charge.
     *
     * @return non-empty-list<class-string<Model>>|null
     */
    public static function chainClassesOf(Model $model): ?array
    {
        if (! $model instanceof IsPartOfParent) {
            return null;
        }

        if (array_key_exists($model::class, self::$chains)) {
            return self::$chains[$model::class];
        }

        return self::$chains[$model::class] = Relation::noConstraints(static fn (): ?array => self::climb($model));
    }

    /**
     * Builds each relation without its constraints, so that it reads nothing of the instance it starts from: a
     * record retrieved without its foreign key would otherwise throw where missing attributes are prevented, and
     * the null answered for that one record would be memoized for its whole class.
     *
     * @return non-empty-list<class-string<Model>>|null
     */
    private static function climb(Model $model): ?array
    {
        $subject = $model;
        $chain = [];

        for ($depth = 0; $subject instanceof IsPartOfParent && $depth < self::MAX_DEPTH; $depth++) {
            $relation = RelationGuard::relationOf($subject, $subject->parentRelation());

            if (! $relation instanceof Relation || $relation instanceof MorphTo) {
                return null;
            }

            $subject = $relation->getRelated();
            $chain[] = $subject::class;
        }

        return $subject instanceof IsPartOfParent || $chain === [] ? null : $chain;
    }
}
