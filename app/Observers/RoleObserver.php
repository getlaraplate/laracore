<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Models\Role;
use Modules\Core\Services\AclResolverService;
use WeakMap;

/**
 * Keeps the cache consistent with changes of the role hierarchy, after the transaction of the write commits.
 *
 * A role inherits the permissions and the ACLs of the roles above it, so moving a role under another parent, moving it
 * to the bin, restoring it or deleting it for good changes what the role and every role below it may do. Each of these
 * writes flushes `acl`, which every cached resolution of {@see AclResolverService} carries, and the `role:{id}` labels
 * of the role and of its descendants, which the CRUD responses computed for their holders carry.
 *
 * A deleted row names no descendant any more (deleting it for good empties the `parent_id` of its children), so the
 * labels are read when the deletion starts and flushed when it is done.
 */
final class RoleObserver
{
    /**
     * The labels read by {@see deleting()} for the deletion still running on each role.
     *
     * @var WeakMap<Role, list<string>>|null
     */
    private static ?WeakMap $pending_deletions = null;

    public function __construct(private readonly CacheLabelInvalidator $invalidator) {}

    public function updated(Role $role): void
    {
        if ($role->wasChanged('parent_id')) {
            $this->invalidator->flushLabels($role, ...$this->labelsOf($role));
        }
    }

    public function deleting(Role $role): void
    {
        self::pendingDeletions()[$role] = $this->labelsOf($role);
    }

    public function deleted(Role $role): void
    {
        $pending = self::pendingDeletions();
        $labels = $pending[$role] ?? $this->labelsOf($role);
        unset($pending[$role]);

        $this->invalidator->flushLabels($role, ...$labels);
    }

    public function restored(Role $role): void
    {
        $this->invalidator->flushLabels($role, ...$this->labelsOf($role));
    }

    /**
     * @return WeakMap<Role, list<string>>
     */
    private static function pendingDeletions(): WeakMap
    {
        return self::$pending_deletions ??= new WeakMap();
    }

    /**
     * @return list<string>
     */
    private function labelsOf(Role $role): array
    {
        $key = $role->getKey();

        return is_int($key) || (is_string($key) && $key !== '') ? $this->invalidator->roleLabels([$key]) : [];
    }
}
