<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Core\Locking\LockedRowsReleaser;
use Modules\Core\Locking\LockingSettingConfirmation;
use Modules\Core\Models\Setting;

/**
 * Releases every lock of a table whose record locking was switched off, dispatched after the commit of the save that
 * switched it off. It reads the setting again when it runs and does nothing unless locking is still off, so switching
 * it back on while the job waits keeps the locks.
 */
final class ReleaseLocksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $table,
        public readonly string $settingName,
    ) {}

    public function handle(LockedRowsReleaser $releaser): void
    {
        $setting = Setting::query()->where('name', $this->settingName)->first();

        if (! $setting instanceof Setting || ! LockingSettingConfirmation::isOff($setting->value)) {
            return;
        }

        $released = $releaser->release($this->table);

        Log::info('Record locking switched off: locks released', ['table' => $this->table, 'records' => $released]);
    }
}
