<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Modules\Core\Events\ModificationPreProcessingCompleted;
use Modules\Core\Events\ModificationRequiresModeration;
use Modules\Core\Listeners\FlushCacheLabelsOnModelWrite;
use Modules\Core\Listeners\FlushCacheLabelsOnPermissionChange;
use Modules\Core\Listeners\RemoveOriginOfDeletedRecord;
use Modules\Core\Locking\LockedModelSubscriber;
use Modules\Core\Models\CronJob;
use Modules\Core\Models\Entity;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Pivot\Fieldable;
use Modules\Core\Models\Preset;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Support\OwnerTimestamp;
use Override;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    #[Override]
    protected $listen = [
        \Modules\Core\Events\ModelRequiresIndexing::class => [
            \Modules\Core\Listeners\IndexModelFallbackListener::class, // Executes after AI listeners
        ],
        \Modules\Core\Events\ModelPreProcessingCompleted::class => [
            \Modules\Core\Listeners\FinalizeModelIndexingListener::class, // Handles finalization
        ],
        ModificationRequiresModeration::class => [
            \Modules\Core\Listeners\ModificationModerationFallbackListener::class,
        ],
        ModificationPreProcessingCompleted::class => [
            \Modules\Core\Listeners\FinalizeModificationModerationListener::class,
        ],
        // Listed by hand: event discovery only scans the application's own app/Listeners, never a
        // module's, so these never ran while they relied on it.
        Attempting::class => [
            \Modules\Core\Listeners\LogoutOtherDevicesListener::class . '@handleAttempting',
        ],
        JobProcessing::class => [
            \Modules\Core\Listeners\ApplySettingsOverlayBeforeJob::class,
        ],
        Login::class => [
            \Modules\Core\Listeners\AfterLoginListener::class,
            \Modules\Core\Listeners\LogoutOtherDevicesListener::class . '@handleLogin',
        ],
        // Spatie fires these only with `permission.events_enabled`, which CoreServiceProvider turns on.
        RoleAttachedEvent::class => [FlushCacheLabelsOnPermissionChange::class],
        RoleDetachedEvent::class => [FlushCacheLabelsOnPermissionChange::class],
        PermissionAttachedEvent::class => [FlushCacheLabelsOnPermissionChange::class],
        PermissionDetachedEvent::class => [FlushCacheLabelsOnPermissionChange::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    #[Override]
    protected static $shouldDiscoverEvents = true;

    #[Override]
    public function boot(): void
    {
        // The lock guard was written but never wired up, so `locking.prevent_modifications`
        // enforced nothing: a record locked by one user could be saved, deleted or replicated by
        // anybody. Subscribing it here is what makes the setting mean something. It stays off by
        // default, so nothing changes for a deployment that has not asked for it.
        Event::subscribe(LockedModelSubscriber::class);

        Event::listen('eloquent.deleted: *', [RemoveOriginOfDeletedRecord::class, 'handle']);

        Event::listen([
            'eloquent.created: *',
            'eloquent.updated: *',
            'eloquent.deleted: *',
            'eloquent.restored: *',
            'eloquent.forceDeleted: *',
        ], FlushCacheLabelsOnModelWrite::class . '@handle');

        // Only an instance that touched something has state to keep in step with the transactions.
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            if ($this->app->resolved(OwnerTimestamp::class)) {
                $this->app->make(OwnerTimestamp::class)->committed($event->connection);
            }
        });
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            if ($this->app->resolved(OwnerTimestamp::class)) {
                $this->app->make(OwnerTimestamp::class)->rolledBack($event->connection);
            }
        });

        Event::listen('eloquent.saved: ' . Modification::class, function (Modification $modification): void {
            if (! $modification->active || ! $modification->wasRecentlyCreated) {
                return;
            }

            event(new ModificationRequiresModeration($modification));
        });

        Event::listen([
            'eloquent.saved: ' . CronJob::class,
            'eloquent.deleted: ' . CronJob::class,
        ], function (Model $model): void {
            if ($model instanceof CronJob) {
                Cache::forget($model->getTable());
            } elseif ($model instanceof Fieldable || $model instanceof Preset) {
                $this->clearPresetCache();
            } elseif ($model instanceof Entity) {
                $this->clearEntityCache($model);
            }
        });
    }

    /**
     * Configure the proper event listeners for email verification.
     */
    #[Override]
    protected function configureEmailVerification(): void
    {
        //
    }

    private function clearEntityCache(Entity $model): void
    {
        Cache::forget($model->getCacheKey());
        DynamicContentsService::getInstance()->clearEntitiesCache();
        $this->clearPresetCache();
    }

    private function clearPresetCache(): void
    {
        Cache::forget('presets');
        Cache::memo()->forget('presets');
        DynamicContentsService::getInstance()->clearPresetsCache();
        DynamicContentsService::getInstance()->clearPresettablesCache();
    }
}
