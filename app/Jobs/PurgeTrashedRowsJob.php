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
 * are still off, so switching them back on while the job waits keeps the rows. A table whose model pins soft deletes
 * on in code keeps its bin: the setting has no effect on it.
 */
final class PurgeTrashedRowsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Retried when a worker kills it at its timeout or a write fails: rows are deleted chunk by chunk, each for good, so
     * an attempt resumes where the last one stopped, and the setting is read again first. The worker's own timeout is
     * kept on purpose: a longer one than the queue's `retry_after` would let a second worker run the same purge at once
     * and fire the events of a row twice.
     */
    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(
        public readonly string $table,
        public readonly string $settingName,
    ) {}

    public function handle(TrashedRowsPurger $purger): void
    {
        $setting = Setting::query()->where('name', $this->settingName)->first();

        if (! $setting instanceof Setting || ! SoftDeletesSettingConfirmation::isOff($setting->value) || $purger->pinnedOn($this->table)) {
            return;
        }

        $purged = $purger->purgeTable($this->table);

        Log::info('Soft deletes switched off: records in the bin deleted for good', ['table' => $this->table, 'records' => $purged]);
    }
}
