<?php

declare(strict_types=1);

namespace Modules\Core\Locking;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Locking\Exceptions\LockedModelException;

/**
 * Derived from sfolador/laravel-locked (MIT), see NOTICES/laravel-locked.md.
 */
final class LockedModelSubscriber
{
    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string,string>
     */
    public function subscribe(): array
    {
        return [
            'eloquent.saving: *' => 'saving',
            'eloquent.deleting: *' => 'deleting',
            'eloquent.replicating: *' => 'replicating',
            NotificationSending::class => 'notificationSending',
        ];
    }

    public function saving(string $event, object|array $entity): bool
    {
        if (Locked::guardIsSuspended() || new Locked()->allowsModificationsOnLockedObjects()) {
            return true;
        }

        $model = $this->getModelFromPassedParams($entity);

        if (new Locked()->doesNotUseHasLocks($model)) {
            return true;
        }

        $locked = new Locked();
        $lockedAtColumnName = $locked->lockedAtColumn();

        if ($model->wasUnlocked() && $model->isDirty($lockedAtColumnName)) {
            // we are locking a model
            return true;
        }

        throw_if(
            $model->wasLocked()
                && $model->isDirty()
                && ! $this->heldByCurrentUser($model)
                && ! $model->writesOnlyAttributesAllowedWhileLocked(),
            LockedModelException::class,
            'This model is locked',
        );

        return true;
    }

    public function deleting(string $event, object|array $entity): bool
    {
        if (Locked::guardIsSuspended() || new Locked()->allowsModificationsOnLockedObjects()) {
            return true;
        }

        $model = $this->getModelFromPassedParams($entity);

        $locked = new Locked();

        if ($locked->doesNotUseHasLocks($model)) {
            return true;
        }

        if (! $model->wasLocked() || $this->heldByCurrentUser($model)) {
            return true;
        }

        throw new LockedModelException('This model is locked');
    }

    public function replicating(string $event, object|array $entity): bool
    {
        $locked = new Locked();

        if (Locked::guardIsSuspended() || $locked->allowsModificationsOnLockedObjects()) {
            return true;
        }

        $model = $this->getModelFromPassedParams($entity);

        /** @var Model $model */
        if (! $model instanceof Model || $locked->doesNotUseHasLocks($model)) {
            return true;
        }

        if ($model->isUnlocked() || $this->heldByCurrentUser($model)) {
            return true;
        }

        throw new LockedModelException('This model is locked');
    }

    public function notificationSending(NotificationSending $event): bool
    {
        $locked = new Locked();

        if ($locked->allowsNotificationsToLockedObjects()) {
            return false;
        }

        $model = $event->notifiable;

        if ($locked->doesNotUseHasLocks($model)) {
            return true;
        }

        if ($model->isUnlocked()) {
            return true;
        }

        throw new LockedModelException('This model is locked');
    }

    /**
     * Whether the lock on the record belongs to the user making the request.
     *
     * A lease exists so that one person can work on a record undisturbed. Blocking that person is
     * the one outcome the whole mechanism must never produce, and it is what this guard used to do:
     * it asked whether the record was locked and never whose lock it was. An ownerless lock is a
     * freeze and matches nobody, which is exactly right.
     *
     * The original value is read rather than the current one, so staging a change to the owner in
     * the same save cannot be used to walk past the guard.
     */
    private function heldByCurrentUser(?Model $model): bool
    {
        if (! $model instanceof Model) {
            return false;
        }

        $owner = $model->getOriginal(new Locked()->lockedByColumn());
        $current = Auth::id();

        return $owner !== null && $current !== null && (int) $owner === (int) $current;
    }

    private function getModelFromPassedParams(object|array $params): ?Model
    {
        if (is_array($params) && $params !== [] && $params[0] instanceof Model) {
            return $params[0];
        }

        if ($params instanceof Model) {
            return $params;
        }

        return null;
    }
}
