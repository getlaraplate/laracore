<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Setting;
use Modules\Core\SoftDeletes\SoftDeletesSettingConfirmation;
use Modules\Core\SoftDeletes\TrashedRowsPurger;

/**
 * Deletes for good the rows in the bin of a table whose soft deletes were switched off, dispatched after the commit
 * of the save that switched them off. It reads the setting again when it runs and does nothing unless soft deletes
 * are still off, so switching them back on while the job waits keeps the rows.
 */
final class PurgeTrashedRowsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $table,
        public readonly string $settingName,
    ) {}

    public function handle(TrashedRowsPurger $purger): void
    {
        $setting = Setting::query()->where('name', $this->settingName)->first();

        if (! $setting instanceof Setting || ! SoftDeletesSettingConfirmation::isOff($setting->value)) {
            return;
        }

        $purged = $purger->purgeTable($this->table);

        Log::info('Soft deletes switched off: records in the bin deleted for good', ['table' => $this->table, 'records' => $purged]);
    }
}
