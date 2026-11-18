<?php

declare(strict_types=1);

namespace Modules\Core\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Models\User;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Flushes the scope labels a permission or role change invalidates, after the transaction of the changed model
 * commits.
 *
 * The pivot flush of the relation knows the tables only, not for whom a response was computed:
 * - a role that gains or loses a permission flushes its `role:{id}` label, and `acl` too, because an ACL resolution
 *   is cached per user and does not carry the labels of the user's roles;
 * - a permission given to or taken from roles from its own side flushes the labels of those roles, and `acl`;
 * - a user whose roles or direct permissions change flushes its `user:{id}` label, which its ACL resolutions carry.
 */
final readonly class FlushCacheLabelsOnPermissionChange
{
    public function __construct(private CacheLabelInvalidator $invalidator) {}

    public function handle(RoleAttachedEvent|RoleDetachedEvent|PermissionAttachedEvent|PermissionDetachedEvent $event): void
    {
        $model = $event->model;

        if ($model instanceof Role) {
            $this->flushRoles($model, self::keysOf($model));

            return;
        }

        if ($model instanceof Permission && ($event instanceof RoleAttachedEvent || $event instanceof RoleDetachedEvent)) {
            $this->flushRoles($model, self::keysOf($event->rolesOrIds));

            return;
        }

        if ($model instanceof User) {
            $this->invalidator->flushLabels($model, ...array_map(CacheLabel::user(...), self::keysOf($model)));
        }
    }

    /**
     * Normalizes what Spatie passes (a model, a key, a list or a collection of either) to the keys it names.
     *
     * @return list<int|string>
     */
    private static function keysOf(mixed $items): array
    {
        if ($items instanceof Collection) {
            $items = $items->all();
        } elseif (! is_array($items)) {
            $items = [$items];
        }

        $keys = [];

        foreach ($items as $item) {
            $key = $item instanceof Model ? $item->getKey() : $item;

            if (is_int($key) || (is_string($key) && $key !== '')) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  list<int|string>  $role_keys
     */
    private function flushRoles(Model $owner, array $role_keys): void
    {
        if ($role_keys === []) {
            return;
        }

        $this->invalidator->flushLabels($owner, CacheLabel::acl(), ...array_map(CacheLabel::role(...), $role_keys));
    }
}
