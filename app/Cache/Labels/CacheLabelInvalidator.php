<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;

/**
 * Turns a write into label flushes. When an owning model is given the flush waits for the commit of
 * the transaction that model's connection has open, and runs at once when there is none. The labels of every write of
 * one transaction are collected and flushed together, once, after the commit ({@see PendingLabelFlushes}).
 */
final readonly class CacheLabelInvalidator
{
    public function __construct(private ICacheLabelIndex $index, private PendingLabelFlushes $pending) {}

    /**
     * Flushes everything that depends on the table: which of its rows exist (`table:{table}`) and the content of any of
     * its rows (`rows:{table}`). Use it after a write that fires no model event, whose rows are not named one by one.
     */
    public function flushTable(string $table, ?Model $owner = null): void
    {
        $this->flushTables([$table], $owner);
    }

    /**
     * {@see flushTable()} for several tables, in one flush call.
     *
     * @param  list<string>  $tables
     */
    public function flushTables(array $tables, ?Model $owner = null): void
    {
        $labels = [];

        foreach (array_unique($tables) as $table) {
            $labels[] = CacheLabel::table($table);
            $labels[] = CacheLabel::rows($table);
        }

        $this->flushLabels($owner, ...$labels);
    }

    public function flushRecord(Model $model, bool $with_table = true): void
    {
        $labels = [];

        if ($with_table) {
            $labels[] = CacheLabel::table($model->getTable());
        }

        // A model loaded without its key, such as a partial pivot row, has no row to name.
        $key = array_key_exists($model->getKeyName(), $model->getAttributes()) ? $model->getKey() : null;

        if (is_int($key) || (is_string($key) && $key !== '')) {
            $labels[] = CacheLabel::row($model->getTable(), $key);
        }

        $this->flushLabels($model, ...$labels);
    }

    /**
     * Flushes the tables of the pivot and of both sides, and, for a grant of roles or permissions, the scope labels
     * of whoever it changes ({@see scopeLabelsOfPivot()}).
     *
     * @param  BelongsToMany<covariant Model, covariant Model, *, *>  $relation
     * @param  list<int|string>|null  $related_keys  The related keys the mutation touched, null when unknown (a detach of all).
     */
    public function flushPivot(BelongsToMany $relation, ?array $related_keys = null): void
    {
        $parent = $relation->getParent();

        $this->flushLabels(
            $parent,
            CacheLabel::table($relation->getTable()),
            CacheLabel::table($parent->getTable()),
            CacheLabel::table($relation->getRelated()->getTable()),
            ...$this->scopeLabelsOfPivot($parent, $relation->getRelated(), $related_keys),
        );
    }

    /**
     * Flushes the given labels and, when roles are given, `acl` with the labels of those roles and of every role
     * below them, which inherit their permissions and ACLs.
     *
     * @param  list<int|string>  $role_keys
     */
    public function flushRoles(?Model $owner, array $role_keys, string ...$labels): void
    {
        $this->flushLabels($owner, ...array_values(array_unique([...$labels, ...$this->roleLabels($role_keys)])));
    }

    public function flushUser(int|string $id, ?Model $owner = null): void
    {
        $this->flushLabels($owner, CacheLabel::user($id));
    }

    public function flushRole(int|string $id, ?Model $owner = null): void
    {
        $this->flushLabels($owner, CacheLabel::role($id));
    }

    public function flushLabels(?Model $owner, string ...$labels): void
    {
        if ($labels === []) {
            return;
        }

        $connection = $owner?->getConnection();

        if ($connection === null || $connection->transactionLevel() === 0) {
            $this->index->flush(...array_values(array_unique($labels)));

            return;
        }

        $this->pending->add($connection, $this->index, array_values($labels));
    }

    /**
     * `acl` and the labels of the roles and of their descendants, roles in the bin included; nothing for no role.
     * Read before a role is deleted when its subtree must be flushed after: the deleted row names no descendant.
     *
     * @param  list<int|string>  $role_keys
     * @return list<string>
     */
    public function roleLabels(array $role_keys): array
    {
        if ($role_keys === []) {
            return [];
        }

        $keys = [];

        // A role in the bin still names the roles below it, which inherit through it again once it is restored.
        $roles = Role::query()
            ->withTrashed()
            ->whereKey($role_keys)
            ->with(['descendants' => static fn (Relation $descendants): Relation => $descendants->withTrashed()])
            ->get();

        foreach ($roles as $role) {
            foreach ($role->descendants as $descendant) {
                $key = self::scalarKey($descendant->getKey());

                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }

        $keys = array_values(array_unique([...$role_keys, ...$keys]));

        return [CacheLabel::acl(), ...array_map(CacheLabel::role(...), $keys)];
    }

    private static function scalarKey(mixed $key): int|string|null
    {
        return is_int($key) || (is_string($key) && $key !== '') ? $key : null;
    }

    /**
     * The scope labels a grant changes. The ACL resolutions carry `acl` and `user:{id}`, a CRUD response the labels
     * of its caller and of the caller's roles:
     * - roles or permissions of a user: that user;
     * - permissions of a role: the role and the roles below it, and `acl`;
     * - roles holding a permission: those roles and the roles below them, and `acl`;
     * - users holding a role or a permission: those users.
     * When the touched keys are unknown, `acl` stands in for them; a CRUD response keys on its caller's roles, so a
     * change of membership already moves it to another key.
     *
     * @param  list<int|string>|null  $related_keys
     * @return list<string>
     */
    private function scopeLabelsOfPivot(Model $parent, Model $related, ?array $related_keys): array
    {
        $parent_key = self::scalarKey($parent->getKey());
        $is_grant = $related instanceof RoleContract || $related instanceof PermissionContract;

        if ($parent instanceof User && $is_grant) {
            return $parent_key === null ? [] : [CacheLabel::user($parent_key)];
        }

        if ($parent instanceof RoleContract && $related instanceof PermissionContract) {
            return $parent_key === null ? [] : $this->roleLabels([$parent_key]);
        }

        if ($parent instanceof PermissionContract && $related instanceof RoleContract) {
            return $related_keys === null ? [CacheLabel::acl()] : $this->roleLabels($related_keys);
        }

        if (($parent instanceof RoleContract || $parent instanceof PermissionContract) && $related instanceof User) {
            return $related_keys === null ? [CacheLabel::acl()] : array_map(CacheLabel::user(...), $related_keys);
        }

        return [];
    }
}
