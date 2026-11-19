<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Modules\Core\Jobs\PurgeVersionHistoryJob;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingsCacheCoordinator;
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
}
