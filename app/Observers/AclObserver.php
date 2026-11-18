<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Services\AclResolverService;

/**
 * Keeps the cache consistent with ACL writes, after the transaction of the write commits.
 *
 * Every create, update, delete and restore flushes the `acl` label, which every cached resolution of
 * {@see AclResolverService} carries, and the labels of the responses the ACL narrows: `role:{id}` for an ACL scoped
 * to a role, the table of its permission's entity for an unscoped one, which applies to every holder of the
 * permission. An update that moves the ACL to another role or permission flushes what it narrowed before as well.
 */
final readonly class AclObserver
{
    public function __construct(private CacheLabelInvalidator $invalidator) {}

    public function saved(ACL $acl): void
    {
        $this->flush($acl);
    }

    public function deleted(ACL $acl): void
    {
        $this->flush($acl);
    }

    public function restored(ACL $acl): void
    {
        $this->flush($acl);
    }

    /**
     * The table a permission is about: its generated `table_name`, or the middle segment of its
     * `{connection}.{table}.{action}` name when the column was not read.
     */
    private static function entityTableOf(Permission $permission): ?string
    {
        $table = $permission->table_name;

        if (! is_string($table) || $table === '') {
            $segments = explode('.', (string) $permission->name);
            $table = count($segments) === 3 ? $segments[1] : null;
        }

        return is_string($table) && $table !== '' ? $table : null;
    }

    private function flush(ACL $acl): void
    {
        $labels = [CacheLabel::acl(), ...$this->labelsOf($acl, $acl->role_id, $acl->permission_id)];

        if ($acl->isDirty(['role_id', 'permission_id'])) {
            $labels = [...$labels, ...$this->labelsOf($acl, $acl->getOriginal('role_id'), $acl->getOriginal('permission_id'))];
        }

        $this->invalidator->flushLabels($acl, ...array_values(array_unique($labels)));
    }

    /**
     * @return list<string>
     */
    private function labelsOf(ACL $acl, mixed $role_id, mixed $permission_id): array
    {
        if (is_int($role_id) || (is_string($role_id) && $role_id !== '')) {
            return [CacheLabel::role($role_id)];
        }

        if (! is_int($permission_id) && ! is_string($permission_id)) {
            return [];
        }

        $permission = $acl->permission()->getRelated()->newQuery()->find($permission_id);
        $table = $permission instanceof Permission ? self::entityTableOf($permission) : null;

        return $table === null ? [] : [CacheLabel::table($table)];
    }
}
