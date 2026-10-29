<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\VersionSet;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Contracts\VersionWriterInterface;
use Modules\Core\Versioning\Data\VersionChange;

/**
 * Owns the hard delete of a versioned root, inside the revision scope its `delete()` or `forceDelete()` opened (D29,
 * D44): the delete runs with the root's set flagged, so what it writes itself stays out of the set (D54); only when
 * the row is really gone are the snapshot rows written and the root's other sets removed. The snapshot set is then
 * the root's only history, read by the recovery and the purge of expired snapshots (D35, D55).
 */
final readonly class HardDeleteCoordinator
{
    /**
     * The reason of a snapshot set written by a delete a user asked for (D35).
     */
    public const string MANUAL_REASON = 'manual';

    public function __construct(
        private VersionSetManagerInterface $manager,
        private VersionWriterInterface $writer,
    ) {}

    /**
     * @param  list<VersionChange>  $snapshot  the aggregate as it was before the delete, read by the root (D55)
     * @param  Closure(): (bool|int|null)  $delete
     */
    public function delete(Model $root, array $snapshot, Closure $delete): bool|int|null
    {
        $set = $this->manager->setFor($root);
        $set->markBeingHardDeleted(true);

        try {
            $deleted = $delete();
        } finally {
            $set->markBeingHardDeleted(false);
        }

        // A listener that cancels the delete leaves the row, and the history with it.
        if ($root->newQueryWithoutScopes()->whereKey($root->getKey())->exists()) {
            return $deleted;
        }

        foreach ($snapshot as $change) {
            $this->writer->write($change);
        }

        $this->removeHistory($set);

        return $deleted;
    }

    /**
     * Removes every set of the root but the snapshot set; their rows go by cascade, and a revert set's link to a
     * removed target is set to null by the database (D58).
     */
    private function removeHistory(ActiveVersionSet $set): void
    {
        $root = $set->root();
        $version_set = new VersionSet;
        $version_set->setConnection($set->connectionName());

        $version_set->newQuery()
            ->where('root_type', $root->type())
            ->where('root_id', $root->id())
            ->where('root_table_ref', $root->tableName())
            ->whereKeyNot($set->versionSet()->getKey())
            ->delete();
    }
}
