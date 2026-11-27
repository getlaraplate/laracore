<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Modules\Core\Jobs\PurgeTrashedRowsJob;
use Modules\Core\Jobs\PurgeVersionHistoryJob;
use Modules\Core\Jobs\ReleaseLocksJob;
use Modules\Core\Locking\LockingSettingConfirmation;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingsCacheCoordinator;
use Modules\Core\SoftDeletes\SoftDeletesSettingConfirmation;
use Modules\Core\Versioning\VersioningSettingConfirmation;

final class SettingObserver
{
    public function saving(Setting $setting): void
    {
        if ($setting->value === '') {
            $setting->value = null;
        }
    }

    public function saved(Setting $setting): void
    {
        app(SettingsCacheCoordinator::class)->flushSetting($setting, sync_runtime_config: true);

        $this->purgeHistoryWhenVersioningSwitchesOff($setting);
        $this->purgeTrashedRowsWhenSoftDeletesSwitchOff($setting);
        $this->releaseLocksWhenLockingSwitchesOff($setting);
    }

    public function deleted(Setting $setting): void
    {
        app(SettingsCacheCoordinator::class)->flushSetting($setting);
    }

    /**
     * A save that switches a table's versioning off removes the table's edit history (decision D22), whatever made it:
     * the settings page, an approved modification, the API. The purge is queued after the commit and checks the
     * setting again when it runs.
     */
    private function purgeHistoryWhenVersioningSwitchesOff(Setting $setting): void
    {
        $table = VersioningSettingConfirmation::tableOf($setting->name);

        if ($table === null
            || ! $setting->wasChanged('value')
            || ! VersioningSettingConfirmation::isOff($setting->value)
            || VersioningSettingConfirmation::isOff($setting->getOriginal('value'))) {
            return;
        }

        PurgeVersionHistoryJob::dispatch($table, $setting->name)->afterCommit();
    }

    /**
     * A save that switches a table's soft deletes off deletes the rows of the table in the bin for good, whatever made
     * it: the settings page, an approved modification, the API, a command. The purge is queued after the commit and
     * checks the setting again when it runs.
     */
    private function purgeTrashedRowsWhenSoftDeletesSwitchOff(Setting $setting): void
    {
        $table = SoftDeletesSettingConfirmation::tableOf($setting->name);

        if ($table === null
            || ! $setting->wasChanged('value')
            || ! SoftDeletesSettingConfirmation::isOff($setting->value)
            || SoftDeletesSettingConfirmation::isOff($setting->getOriginal('value'))) {
            return;
        }

        PurgeTrashedRowsJob::dispatch($table, $setting->name)->afterCommit();
    }

    /**
     * A save that switches a table's record locking off releases every lock of the table, whatever made it. The
     * release is queued after the commit and checks the setting again when it runs.
     */
    private function releaseLocksWhenLockingSwitchesOff(Setting $setting): void
    {
        $table = LockingSettingConfirmation::tableOf($setting->name);

        if ($table === null
            || ! $setting->wasChanged('value')
            || ! LockingSettingConfirmation::isOff($setting->value)
            || LockingSettingConfirmation::isOff($setting->getOriginal('value'))) {
            return;
        }

        ReleaseLocksJob::dispatch($table, $setting->name)->afterCommit();
    }
}
