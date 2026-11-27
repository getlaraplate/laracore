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
 * {@see AclResolverService} carries, and the labels of the responses the ACL narrows: for an ACL scoped to a role,
 * `role:{id}` of that role and of every role below it, which inherits the ACL unless it has its own; for an unscoped
 * one, `crud` with the table of its permission's entity, as it applies to every holder of the permission and narrows
 * the entity wherever a response embeds it through a relation, under row labels its table label does not reach.
 * ACL writes are rare admin operations, so flushing every CRUD response then is acceptable. An update that moves the
 * ACL to another role or permission flushes what it narrowed before as well.
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
        $role_keys = [];
        $tables = [];
        $unscoped = $this->collect($acl, $acl->role_id, $acl->permission_id, $role_keys, $tables);

        if ($acl->isDirty(['role_id', 'permission_id'])) {
            $unscoped = $this->collect($acl, $acl->getOriginal('role_id'), $acl->getOriginal('permission_id'), $role_keys, $tables) || $unscoped;
        }

        $this->invalidator->flushRoles(
            $acl,
            array_values(array_unique($role_keys)),
            CacheLabel::acl(),
            ...($unscoped ? [CacheLabel::all()] : []),
            ...array_map(CacheLabel::table(...), array_values(array_unique($tables))),
        );
    }

    /**
     * Adds what one state of the ACL narrows: its role, or the table of its permission's entity when unscoped.
     * Returns true for an unscoped state.
     *
     * @param  list<int|string>  $role_keys
     * @param  list<string>  $tables
     */
    private function collect(ACL $acl, mixed $role_id, mixed $permission_id, array &$role_keys, array &$tables): bool
    {
        if (is_int($role_id) || (is_string($role_id) && $role_id !== '')) {
            $role_keys[] = $role_id;

            return false;
        }

        if (! is_int($permission_id) && ! is_string($permission_id)) {
            return true;
        }

        $permission = $acl->permission()->getRelated()->newQuery()->find($permission_id);
        $table = $permission instanceof Permission ? self::entityTableOf($permission) : null;

        if ($table !== null) {
            $tables[] = $table;
        }

        return true;
    }
}
