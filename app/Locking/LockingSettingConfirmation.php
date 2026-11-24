<?php

declare(strict_types=1);

namespace Modules\Core\Locking;

use Modules\Core\Contracts\ISettingChangeConfirmation;
use Modules\Core\Data\SettingChangeWarning;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Override;

/**
 * The confirmation shown when `locking.enabled.{table}` is switched off: every lock of the table is released (nothing is
 * deleted), and the warning says how many records are locked. The release itself is dispatched by the
 * `SettingObserver` for every save that switches it off, so an approved modification and the API release too;
 * confirming here does nothing more. Optimistic locking (`locking.optimistic.{table}`) holds no lock and asks nothing.
 */
final readonly class LockingSettingConfirmation implements ISettingChangeConfirmation
{
    public function __construct(private LockedRowsReleaser $releaser) {}

    /**
     * The table a record locking setting is about, or null when the name is no such setting.
     */
    public static function tableOf(string $settingName): ?string
    {
        $prefix = CoreDatabaseSeeder::LOCK_NAME_PREFIX . '.';

        if (! str_starts_with($settingName, $prefix) || mb_strlen($settingName) === mb_strlen($prefix)) {
            return null;
        }

        return mb_substr($settingName, mb_strlen($prefix));
    }

    /**
     * Whether a value of the setting switches locking off, as `PerModelSettingResolver::boolean()` reads it: a missing
     * value reads as on.
     */
    public static function isOff(mixed $value): bool
    {
        return $value !== null && ! (bool) $value;
    }

    #[Override]
    public function supports(string $settingName): bool
    {
        return self::tableOf($settingName) !== null;
    }

    #[Override]
    public function warn(Setting $setting, mixed $newValue): ?SettingChangeWarning
    {
        $table = self::tableOf($setting->name);

        if ($table === null || ! self::isOff($newValue) || self::isOff($setting->value)) {
            return null;
        }

        $locked = $this->releaser->countFor($table);

        return new SettingChangeWarning('Switch record locking off?', [
            "The {$locked} locked records of {$table} are unlocked. No record is deleted.",
            'The records are unlocked by a queued job: switching locking back on before it runs keeps the locks.',
        ]);
    }

    #[Override]
    public function confirmed(Setting $setting, mixed $newValue): void {}

    #[Override]
    public function lockedReason(Setting $setting): ?string
    {
        return null;
    }
}
