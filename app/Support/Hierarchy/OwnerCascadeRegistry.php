<?php

declare(strict_types=1);

namespace Modules\Core\Support\Hierarchy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Modules\Core\SoftDeletes\RowRemoval;
use WeakMap;

/**
 * Core-owned registry of the {@see OwnerCascade}s: the hierarchies an owner's hard delete reaches by cascading
 * foreign keys. Populated at boot by the modules that own the hierarchies; Core stays agnostic of them.
 *
 * The rows the cascade removes fire no event, so their closure rows go with the closure's own foreign keys while
 * the surviving children of those rows keep links to the ancestors above them. The owners' observer,
 * {@see \Modules\Core\Observers\HierarchyOwnerObserver}, calls {@see self::ownerDeleting()} and
 * {@see self::ownerDeleted()}: the first only reads, when the row really
 * goes, the children each cascade leaves without a parent; the second, once the row is gone, moves those children to
 * the roots. A delete that a later listener cancels, or that fails, therefore writes nothing to any closure.
 */
final class OwnerCascadeRegistry
{
    /**
     * @var list<OwnerCascade>
     */
    private array $cascades = [];

    /**
     * The children read before an owner's delete, per cascade, until the delete ends.
     *
     * @var WeakMap<Model, list<array{cascade: OwnerCascade, children: list<int|string>}>>
     */
    private WeakMap $leftChildren;

    public function __construct()
    {
        $this->leftChildren = new WeakMap();
    }

    public function register(OwnerCascade $cascade): void
    {
        $this->cascades[] = $cascade;
    }

    /**
     * The cascades of an owner class, those registered for a parent class included.
     *
     * @return list<OwnerCascade>
     */
    public function for(string $owner_class): array
    {
        return array_values(array_filter(
            $this->cascades,
            static fn (OwnerCascade $cascade): bool => is_a($owner_class, $cascade->owner, true),
        ));
    }

    /**
     * The owner tables registered for a hierarchy table, sorted.
     *
     * @return list<string>
     */
    public function ownerTablesOf(string $hierarchy_table): array
    {
        $tables = [];

        foreach ($this->cascades as $cascade) {
            if ($cascade->hierarchyTable === $hierarchy_table) {
                $tables[] = $cascade->ownerTable;
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * Before an owner's delete: when the delete removes the row, read for each of its cascades the hierarchy rows
     * the cascade leaves without their parent. Writes nothing.
     */
    public function ownerDeleting(Model $owner): void
    {
        unset($this->leftChildren[$owner]);

        if (! RowRemoval::inDelete($owner)) {
            return;
        }

        $left = [];

        foreach ($this->for($owner::class) as $cascade) {
            $children = $cascade->maintainerFor($owner)->childrenLeftBy(
                static fn (Builder $hierarchy): Builder => $cascade->doomedKeysOf($hierarchy, $owner),
            );

            if ($children !== []) {
                $left[] = ['cascade' => $cascade, 'children' => $children];
            }
        }

        if ($left !== []) {
            $this->leftChildren[$owner] = $left;
        }
    }

    /**
     * After an owner's delete: when the row is gone, move to the roots the children read before it whose parent the
     * parent foreign key cleared.
     */
    public function ownerDeleted(Model $owner): void
    {
        $left = $this->leftChildren[$owner] ?? [];
        unset($this->leftChildren[$owner]);

        if ($owner->exists) {
            return;
        }

        foreach ($left as ['cascade' => $cascade, 'children' => $children]) {
            $cascade->maintainerFor($owner)->rootParentless($children);
        }
    }
}
