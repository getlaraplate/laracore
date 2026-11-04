<?php

declare(strict_types=1);

namespace Modules\Core\Locking;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Locking\Traits\HasLocks;
use Throwable;

/**
 * Derived from sfolador/laravel-locked (MIT), see NOTICES/laravel-locked.md.
 */
final class Locked
{
    /**
     * Whether the modification guard is currently suspended for this process.
     *
     * @see withoutGuard()
     */
    private static bool $guard_suspended = false;

    /**
     * Runs a callback with the lock guard suspended, and puts it back afterwards.
     *
     * The guard has no notion of an acting user outside a request, so on a queue or in the console
     * nobody holds the lock and every leased record is closed to writing. That is the right default:
     * a lease exists to protect somebody's work in progress, and a background job overwriting it is
     * exactly the damage the mechanism is for. But some system work genuinely must go through, and
     * it should say so out loud rather than be waved past by an implicit exception for "no user":
     *
     * <code>
     * Locked::withoutGuard(fn () => $invoice->recalculateTotals());
     * </code>
     *
     * Nested calls restore the previous state rather than switching the guard back on, so a bypass
     * inside a bypass cannot silently re-enable it halfway through.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutGuard(callable $callback): mixed
    {
        $previous = self::$guard_suspended;
        self::$guard_suspended = true;

        try {
            return $callback();
        } finally {
            self::$guard_suspended = $previous;
        }
    }

    /**
     * Whether a {@see withoutGuard()} block is currently running.
     */
    public static function guardIsSuspended(): bool
    {
        return self::$guard_suspended;
    }

    public function lockedAtColumn(): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return 'locked_at';
            }

            return config('core.locking.lock_at_column', 'locked_at');
        } catch (Throwable) {
            return 'locked_at';
        }
    }

    public function lockedByColumn(): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return 'locked_user_id';
            }

            return config('core.locking.lock_by_column', 'locked_user_id');
        } catch (Throwable) {
            return 'locked_user_id';
        }
    }

    /**
     * Deadline column: after this moment the lock is void, whether or not the sweep has run.
     */
    public function lockedUntilColumn(): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return 'locked_until';
            }

            return config('core.locking.lock_until_column', 'locked_until');
        } catch (Throwable) {
            return 'locked_until';
        }
    }

    /**
     * Default lifetime, in seconds, of a lease taken by the edit-form lifecycle.
     */
    public function leaseTtl(): int
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return 900;
            }

            return (int) config('core.locking.lease_ttl', 900);
        } catch (Throwable) {
            return 900;
        }
    }

    public function canBeUnlocked(object $model): bool
    {
        $modelClass = $model::class;
        $canBeUnlocked = $this->classesThatCanBeUnlocked();
        $unlockAllowed = $this->unlockAllowed();

        return $unlockAllowed || in_array($modelClass, $canBeUnlocked, true);
    }

    public function cannotBeUnlocked(object $model): bool
    {
        return ! $this->canBeUnlocked($model);
    }

    public function unlockAllowed(): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return true;
            }

            return config('core.locking.unlock_allowed', true);
        } catch (Throwable) {
            return true;
        }
    }

    public function classesThatCanBeUnlocked(): array
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return [];
            }

            return config('core.locking.can_be_unlocked', []);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Whether locking applies to this model at all.
     *
     * The trait is the first half of the answer and the `lock_{table}` setting is the
     * second: a table whose switch is off behaves, everywhere this method is consulted,
     * exactly like a table that never had locks. Reading it here rather than at each
     * call site is what makes the setting mean something without every guard having to
     * remember it.
     *
     * The trait probe is recursive, because a model can inherit it: the runtime
     * `_extended` subclasses carry no traits of their own, and a flat probe reported
     * them as unlockable, silently switching the guard off for exactly the records an
     * installation customised.
     */
    public function usesHasLocks(Model $model): bool
    {
        if (! class_uses_trait($model, HasLocks::class)) {
            return false;
        }

        return $model->locksEnabledBySettings();
    }

    public function doesNotUseHasLocks(Model $model): bool
    {
        return ! $this->usesHasLocks($model);
    }

    /**
     * Whether saves, deletes, and replicates on locked models should be blocked.
     *
     * Config: core.locking.prevent_modifications (runtime setting, DB overlay).
     * Used by {@see LockedModelSubscriber} on eloquent.saving, eloquent.deleting, eloquent.replicating.
     *
     * **On by default.** A lock that enforces nothing is decoration: with this off, a record one
     * user has taken charge of can still be saved over by anybody, and the only real protection in
     * the system is the handful of database triggers on the ERP documents. The guard exempts the
     * holder of the lock, so the person who took it is never the one it stops.
     */
    public function preventsModificationsOnLockedObjects(): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return true;
            }

            return config('core.locking.prevent_modifications', true);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Inverse of {@see preventsModificationsOnLockedObjects()}; early-exit in {@see LockedModelSubscriber} when true.
     */
    public function allowsModificationsOnLockedObjects(): bool
    {
        return ! $this->preventsModificationsOnLockedObjects();
    }

    /**
     * Reads core.locking.prevent_notifications (name is misleading: returns the "prevent" flag, not "allow").
     *
     * Used by {@see LockedModelSubscriber::notificationSending()}: when this returns true, the listener returns false
     * and cancels the notification before checking whether the notifiable is locked.
     * When false, only locked {@see HasLocks} notifiables are blocked (via exception).
     */
    public function allowsNotificationsToLockedObjects(): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return false;
            }

            return config('core.locking.prevent_notifications', false);
        } catch (Throwable) {
            return false;
        }
    }
}
