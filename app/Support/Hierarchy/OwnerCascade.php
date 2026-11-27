<?php

declare(strict_types=1);

namespace Modules\Core\Support\Hierarchy;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * One way an owner's hard delete removes rows of a hierarchy: a chain of cascading foreign keys from the owner's
 * table to the hierarchy table, which deletes the rows without an Eloquent event on them.
 */
final readonly class OwnerCascade
{
    /**
     * @param  class-string<Model>  $owner  the owner class; its subclasses are covered too
     * @param  string  $ownerTable  the owner's table, named here because an abstract owner has no instance to ask
     * @param  string  $hierarchyTable  the hierarchy table the cascade reaches
     * @param  Closure(Model): ClosureTableMaintainer  $maintainer  the hierarchy's maintainer, for the owner being deleted
     * @param  Closure(Builder, Model): Builder  $doomedKeys  narrows a query on the hierarchy table to the rows the
     *                                                        owner's hard delete takes along
     */
    public function __construct(
        public string $owner,
        public string $ownerTable,
        public string $hierarchyTable,
        private Closure $maintainer,
        private Closure $doomedKeys,
    ) {}

    public function maintainerFor(Model $owner): ClosureTableMaintainer
    {
        return ($this->maintainer)($owner);
    }

    /**
     * The hierarchy rows the owner's hard delete takes along, as conditions on a query on the hierarchy table.
     */
    public function doomedKeysOf(Builder $hierarchy, Model $owner): Builder
    {
        return ($this->doomedKeys)($hierarchy, $owner);
    }
}
