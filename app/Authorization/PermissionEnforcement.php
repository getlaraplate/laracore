<?php

declare(strict_types=1);

namespace Modules\Core\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Support\TableModels;

/**
 * What a granted permission actually does at this moment.
 *
 * A capability permission keeps its row whatever the per-table settings say. The row
 * is the vocabulary, and dropping it would take every grant and every ACL written on
 * that name down with it by cascade. What the settings decide is whether the
 * operation runs, and that is read at the point of use
 * ({@see \Modules\Core\Locking\Locked::usesHasLocks()} for locks,
 * {@see \Modules\Core\SoftDeletes\SoftDeletes::softDeletesEnabledBySettings()} for
 * soft deletes). The price of keeping the two apart is that somebody configuring a
 * role can grant a permission that does nothing, or something other than what it
 * says, and have no way of telling. This is what tells them.
 *
 * The map is written by hand, verb by verb, because it does not follow from the
 * capability. On this CRUD surface `delete` and `restore` are the soft-delete pair:
 * the destructive endpoint authorizes against `forceDelete`, `delete` gates the
 * inactivate operation and `restore` the activate one. So switching soft deletes off
 * silences `restore`, which has nothing left to act on, while `delete` keeps working
 * and changes meaning: {@see \Modules\Core\SoftDeletes\SoftDeletes::performDeleteOnModel()}
 * destroys the row instead of hiding it. Those are two different warnings, not one.
 */
final readonly class PermissionEnforcement
{
    public function __construct(private PerModelSettingResolver $settings) {}

    public function hasNote(string $permission_name, ?string $table_name): bool
    {
        return $this->noteFor($permission_name, $table_name) instanceof PermissionNote;
    }

    /**
     * A warning for whoever is configuring the role, or null when the permission does
     * exactly what its name says.
     */
    public function noteFor(string $permission_name, ?string $table_name): ?PermissionNote
    {
        if ($table_name === null || $table_name === '') {
            return null;
        }

        $separator = mb_strrpos($permission_name, '.');
        $operation = $separator === false ? $permission_name : mb_substr($permission_name, $separator + 1);

        return match ($operation) {
            ActionEnum::Lock->value, ActionEnum::Unlock->value => $this->isOff(CoreDatabaseSeeder::LOCK_NAME_PREFIX, $table_name)
                ? $this->inert('locking', $table_name)
                : null,
            ActionEnum::Restore->value => $this->isOff(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $table_name)
                ? $this->inert('soft_deletes', $table_name)
                : null,
            ActionEnum::Delete->value => $this->isOff(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $table_name)
                ? $this->changed('hard_delete', $table_name)
                : null,
            default => null,
        };
    }

    private function inert(string $key, string $table_name): PermissionNote
    {
        return new PermissionNote(
            (string) __('app.permissions.note.inert'),
            (string) __('app.permissions.note.' . $key, ['table' => $table_name]),
        );
    }

    private function changed(string $key, string $table_name): PermissionNote
    {
        return new PermissionNote(
            (string) __('app.permissions.note.changed'),
            (string) __('app.permissions.note.' . $key, ['table' => $table_name]),
        );
    }

    /**
     * Whether the capability is off for the table. The feature follows the model: a missing setting reads as on (the
     * trait decides, the row is only an off switch), so a table whose models no longer declare the trait is off
     * whatever the setting says, as after `model:soft-deletes-remove` or `model:locked-remove`. A table no model of
     * the application reads is decided by the setting alone.
     */
    private function isOff(string $prefix, string $table_name): bool
    {
        $models = TableModels::for($table_name);

        if ($models === []) {
            return ! $this->settings->boolean(
                PerModelSettingResolver::nameFor($prefix, $table_name),
                default: true,
            );
        }

        foreach ($models as $model) {
            if ($this->modelUses($prefix, $model)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the model declares the capability and its setting (or the model itself) leaves it on.
     */
    private function modelUses(string $prefix, Model $model): bool
    {
        if ($prefix === CoreDatabaseSeeder::LOCK_NAME_PREFIX) {
            return class_uses_trait($model, HasLocks::class) && $model->locksEnabledBySettings();
        }

        return class_uses_trait($model, SoftDeletes::class)
            && (! method_exists($model, 'softDeletesEnabledBySettings') || $model->softDeletesEnabledBySettings());
    }
}
