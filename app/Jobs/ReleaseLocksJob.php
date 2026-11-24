<?php

declare(strict_types=1);

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * Retried when the write fails: releasing the locks is one statement that clears columns, so running it again
     * changes nothing more, and the setting is read again first.
     */
    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $table,
        public readonly string $settingName,
    ) {}

    /**
     * One run per table at a time: switching off, on and off again queues two jobs, and two workers running them at
     * once would fire the events of a row twice. A second job finds the lock taken and is released back to the queue
     * for a minute (an attempt of its {@see $tries}). The lock expires after three minutes, twice the queue's default
     * `retry_after`: a run lasts less than the worker timeout, and an attempt the worker killed, which never frees the
     * lock, holds it no longer than that, so its retries still find it free within their tries.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->table)->releaseAfter(60)->expireAfter(180)];
    }

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
