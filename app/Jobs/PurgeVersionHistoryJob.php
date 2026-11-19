<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Setting;
use Modules\Core\Versioning\VersioningSettingConfirmation;
use Modules\Core\Versioning\VersionHistoryPurger;

/**
 * Removes the edit history of a table whose versioning was switched off (decision D22), dispatched after the commit of
 * the save that switched it off. It reads the setting again when it runs and does nothing unless versioning is still
 * off, so switching it back on while the job waits keeps the history written meanwhile.
 */
final class PurgeVersionHistoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $table,
        public readonly string $settingName,
    ) {}

    public function handle(VersionHistoryPurger $purger): void
    {
        $setting = Setting::query()->where('name', $this->settingName)->first();

        if (! $setting instanceof Setting || ! VersioningSettingConfirmation::isOff($setting->value)) {
            return;
        }

        $removed = $purger->purge($this->table);

        Log::info('Versioning switched off: edit history purged', ['table' => $this->table, 'sets' => $removed]);
    }
}
