<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Modules\Core\Contracts\ISettingChangeConfirmation;
use Modules\Core\Data\SettingChangeWarning;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Override;

/**
 * The confirmation shown when `versioning.strategy.{table}` is switched off on the settings page: the edit history of
 * the table is deleted (decision D22 of `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md`),
 * and the warning says how much and what is kept. The purge itself is dispatched by the `SettingObserver` for every
 * save that switches it off, so an approved modification and the API purge too; confirming here does nothing more.
 */
final readonly class VersioningSettingConfirmation implements ISettingChangeConfirmation
{
    public function __construct(private VersionHistoryPurger $purger) {}

    /**
     * The table a versioning strategy setting is about, or null when the name is no such setting.
     */
    public static function tableOf(string $settingName): ?string
    {
        $prefix = CoreDatabaseSeeder::VERSIONING_NAME_PREFIX . '.';

        if (! str_starts_with($settingName, $prefix) || mb_strlen($settingName) === mb_strlen($prefix)) {
            return null;
        }

        return mb_substr($settingName, mb_strlen($prefix));
    }

    /**
     * Whether a value of the setting switches versioning off, as `HasVersions::getVersionStrategy()` reads it.
     */
    public static function isOff(mixed $value): bool
    {
        return $value === false;
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

        ['sets' => $sets, 'rows' => $rows] = $this->purger->countFor($table);

        return new SettingChangeWarning('Switch versioning off?', [
            "The edit history of {$table} is deleted: {$sets} revisions, {$rows} version rows. It cannot be restored.",
            'The snapshots of records deleted for good are kept: those records can still be recovered.',
            'The history is deleted by a queued job: switching versioning back on before it runs keeps it.',
            'Switched back on later, versioning starts a new history: each record gets a baseline at its next save.',
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
