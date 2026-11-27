<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes;

use Modules\Core\Contracts\ISettingChangeConfirmation;
use Modules\Core\Data\SettingChangeWarning;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Override;

/**
 * The confirmation shown when `soft_deletes.enabled.{table}` is switched off: the rows of the table in the bin are
 * deleted for good, and the warning says how many. The purge itself is dispatched by the `SettingObserver` for every
 * save that switches it off, so an approved modification and the API purge too; confirming here does nothing more.
 * `model:soft-deletes-refresh` asks the same before it aligns the setting to a model without the trait.
 */
final readonly class SoftDeletesSettingConfirmation implements ISettingChangeConfirmation
{
    public function __construct(private TrashedRowsPurger $purger) {}

    /**
     * The table a soft deletes setting is about, or null when the name is no such setting.
     */
    public static function tableOf(string $settingName): ?string
    {
        $prefix = CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX . '.';

        if (! str_starts_with($settingName, $prefix) || mb_strlen($settingName) === mb_strlen($prefix)) {
            return null;
        }

        return mb_substr($settingName, mb_strlen($prefix));
    }

    /**
     * Whether a value of the setting switches soft deletes off, as `PerModelSettingResolver::boolean()` reads it: a
     * missing value reads as on.
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

        if ($table === null || ! self::isOff($newValue) || self::isOff($setting->value) || $this->purger->pinnedOn($table)) {
            return null;
        }

        $trashed = $this->purger->countFor($table);

        return new SettingChangeWarning('Switch soft deletes off?', [
            "The {$trashed} records of {$table} in the bin are deleted for good. They cannot be restored.",
            'From now on, deleting a record of the table deletes it for good.',
            'The records are deleted by a queued job: switching soft deletes back on before it runs keeps them.',
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
