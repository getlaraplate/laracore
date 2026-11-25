<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Override;

/**
 * A closure tree node that records the relations set on it, so that a test can tell which ones hydration builds.
 */
final class RelationCountingClosureTreeStubModel extends ClosureTreeStubModel
{
    /**
     * @var list<string>
     */
    public static array $relationsSet = [];

    #[Override]
    public function setRelation($relation, $value)
    {
        self::$relationsSet[] = (string) $relation;

        return parent::setRelation($relation, $value);
    }
}
