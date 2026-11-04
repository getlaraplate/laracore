<?php

declare(strict_types=1);

namespace Modules\Core\Locking\Traits;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Locking\Exceptions\CannotUnlockException;
use Modules\Core\Locking\Locked;
use Modules\Core\Services\PerModelSettingResolver;

/**
 * Derived from sfolador/laravel-locked (MIT), see NOTICES/laravel-locked.md.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-require-implements ILockableModel
 */
trait HasLocks
{
    /**
     * The optimistic version the client is holding used to be read here, straight off the global
     * request, on every save of every lockable model. That is the wrong place three times over: it
     * reaches for a request that does not exist on a queue or in the console, it trusts client input
     * inside a model event where nothing has authorized it, and on a write matching several rows it
     * stamped one client's version onto all of them. The CRUD service now applies it explicitly,
     * and only where it means something. See {@see \Modules\Core\Services\Crud\CrudService}.
     */
    public static function bootHasLocks(): void
    {
        static::saving(function (Model $model): void {
            // `is_locked` is computed, never stored: it must not reach the write.
            unset($model->attributes[$model->getIsLockedColumn()]);
            unset($model->original[$model->getIsLockedColumn()]);
        });
    }

    /**
     * Initialize the locking trait for an instance.
     */
    public function initializeHasLocks(): void
    {
        // is_locked stays guarded (never mass-assigned) but the lock columns are
        // intentionally visible so CRUD payloads can surface lock state to the UI.
        if (! in_array($this->getIsLockedColumn(), $this->guarded, true)) {
            $this->guarded[] = $this->getIsLockedColumn();
        }

        // It is no longer a stored column, so it has to be appended to stay in the payload.
        if (! in_array($this->getIsLockedColumn(), $this->appends, true)) {
            $this->appends[] = $this->getIsLockedColumn();
        }

        // Declared here rather than on each of the ten lockable models: expiry is compared against
        // these columns on every read, and a model that left them uncast would hand back strings.
        $this->mergeCasts([
            $this->getLockedAtColumn() => 'datetime',
            $this->getLockedUntilColumn() => 'datetime',
        ]);
    }

    /**
     * Lock state, computed rather than stored.
     *
     * `is_locked` used to be a stored generated column over `locked_at`. It could not survive the
     * introduction of a deadline: every engine requires a generated column's expression to be
     * deterministic, and expiry needs the current time. Computing it here keeps a single source of
     * truth with {@see isLocked()} and with the `locked` query scope.
     */
    public function getIsLockedAttribute(): bool
    {
        return $this->isLocked();
    }

    /**
     * Get the name of the "is locked" column.
     */
    public function getIsLockedColumn(): string
    {
        return 'is_locked';
    }

    public function getLockedAtColumn(): string
    {
        return new Locked()->lockedAtColumn();
    }

    public function getLockedByColumn(): string
    {
        return new Locked()->lockedByColumn();
    }

    public function getLockedUntilColumn(): string
    {
        return new Locked()->lockedUntilColumn();
    }

    /**
     * Whether record locking is switched on for this model.
     *
     * Reads the optional per-model property {@see $locksEnabled}, and otherwise the
     * `lock_{table}` setting in the `locking` group, mirroring
     * {@see \Modules\Core\SoftDeletes\SoftDeletes::softDeletesEnabledBySettings()}.
     * A model that declares the property pins the answer in code, which is why
     * {@see CoreDatabaseSeeder} seeds no setting for it:
     * offering a switch that the code overrules would be a lie.
     *
     * With no row, locking stays on. The columns are only ever added to a table by
     * `module:locked-add`, so their presence already means somebody asked for locks.
     */
    public function locksEnabledBySettings(): bool
    {
        $in_code = $this->locksEnabledInCode();

        if ($in_code !== null) {
            return $in_code;
        }

        return app(PerModelSettingResolver::class)->boolean(
            PerModelSettingResolver::nameFor(CoreDatabaseSeeder::LOCK_NAME_PREFIX, $this->getTable()),
            default: true,
        );
    }

    /**
     * The model's own last word on locking, or null when it does not give one.
     *
     * Asked of the model rather than read from outside because the property may be
     * private: a model pins the answer for itself, and only the model can be asked.
     * {@see \Modules\Core\Console\PermissionsRefreshCommand} needs this half alone,
     * without the setting, to decide whether the `lock` and `unlock` permissions
     * belong in this model's vocabulary at all.
     */
    public function locksEnabledInCode(): ?bool
    {
        return property_exists($this, 'locksEnabled') ? (bool) $this->locksEnabled : null;
    }

    /**
     * Whether the lock recorded on this instance has already lapsed.
     *
     * A lock with no deadline never lapses. Expiry is evaluated here, on every read, so a lock is
     * free the moment it expires whether or not the housekeeping sweep has run.
     */
    public function lockHasExpired(): bool
    {
        $locked_until = $this->{$this->getLockedUntilColumn()} ?? null;

        if ($locked_until === null) {
            return false;
        }

        return $this->asDateTime($locked_until)->isPast();
    }

    /**
     * Takes the lock, writing the lock columns directly.
     *
     * Passing no user produces an **ownerless** lock, which is a freeze: nobody may edit the record.
     * Passing a user produces a lease that only that user may edit under. `$until` is the moment the
     * lock lapses; null means it never does.
     */
    public function lock(?User $user = null, DateTimeInterface|string|null $until = null): self
    {
        $locked = new Locked();

        $this->writeLockColumns([
            $locked->lockedAtColumn() => $this->freshTimestampString(),
            $locked->lockedByColumn() => $user?->id,
            $locked->lockedUntilColumn() => $until === null ? null : $this->fromDateTime($until),
        ]);

        return $this;
    }

    public function lockBy(User $user, DateTimeInterface|string|null $until = null): self
    {
        return $this->lock($user, $until);
    }

    public function isLocked(): bool
    {
        return $this->{new Locked()->lockedAtColumn()} !== null && ! $this->lockHasExpired();
    }

    public function isLockedBy(User $user): bool
    {
        return $this->isLocked() && $this->{new Locked()->lockedByColumn()} === $user->id;
    }

    /**
     * Whether the lock keeps the signed-in user from writing this record: it is held by somebody else, or it
     * is a freeze, which nobody holds. The holder of a lease writes. Like the write guard, it gives way while
     * the guard is suspended for a system operation or when the deployment allows writes on locked records.
     */
    public function blocksWritesByCurrentUser(): bool
    {
        $locked = new Locked();

        if (Locked::guardIsSuspended() || $locked->allowsModificationsOnLockedObjects() || ! $this->isLocked()) {
            return false;
        }

        $owner = $this->{$locked->lockedByColumn()};
        $current = Auth::id();

        // Loose for the same reason as unlock(): the key comes back as an int or a string.
        return $owner === null || $current === null || (int) $owner !== (int) $current;
    }

    public function isNotLocked(): bool
    {
        return ! $this->isLocked();
    }

    public function isNotLockedBy(User $user): bool
    {
        return $this->isNotLocked() && $this->{new Locked()->lockedByColumn()} !== $user->id;
    }

    /**
     * Attributes this model still accepts while it is locked.
     *
     * A lock answers one question, "who holds this record", and knows nothing about the meaning of
     * any column. Some records nonetheless carry attributes that are not edits at all: counters a
     * downstream process maintains, a status the system advances on its own. Refusing those means
     * stopping the system from doing its work on a record somebody merely has open, which is not
     * what a lock is for.
     *
     * Empty here, so a lock keeps meaning "no writes" on every model until one states otherwise.
     * Declaring attributes is a statement about the model, not about the caller: a service with a
     * genuine reason to overrule the lock itself uses {@see Locked::withoutGuard()}.
     *
     * @return list<string>
     */
    public function attributesWritableWhileLocked(): array
    {
        return [];
    }

    /**
     * Whether the pending change consists solely of attributes a locked record still accepts.
     *
     * Every dirty attribute has to be exempt. One edited field makes the whole save an edit, so a
     * write that advances a counter and rewrites a price is refused on account of the price.
     */
    public function writesOnlyAttributesAllowedWhileLocked(): bool
    {
        $allowed = $this->attributesWritableWhileLocked();

        if ($allowed === []) {
            return false;
        }

        $dirty = array_keys($this->getDirty());

        return $dirty !== [] && array_diff($dirty, $allowed) === [];
    }

    /**
     * Moves the deadline of the lock already on the record, touching nothing else.
     *
     * `locked_at` records when the current lock was taken and is never refreshed, so extending a
     * lease must not rewrite it: the client reads it to tell the user since when the record has
     * been held.
     */
    public function setLockDeadline(DateTimeInterface|string|null $until): self
    {
        $this->writeLockColumns([
            $this->getLockedUntilColumn() => $until === null ? null : $this->fromDateTime($until),
        ]);

        return $this;
    }

    /**
     * Releases the lock whoever holds it.
     *
     * {@see unlock()} refuses to release a lock owned by somebody else, which is the right default
     * for direct model use. A caller that has already established the right to remove another
     * user's lock, through the `unlock` permission and its ACL, uses this instead. The per-class
     * configuration guard still applies: a class the deployment declares unlockable is unlockable
     * by nobody.
     */
    public function forceUnlock(): self
    {
        $locked = new Locked();

        throw_if($locked->cannotBeUnlocked($this), CannotUnlockException::class, 'This model cannot be unlocked');

        $this->writeLockColumns([
            $locked->lockedAtColumn() => null,
            $locked->lockedByColumn() => null,
            $locked->lockedUntilColumn() => null,
        ]);

        return $this;
    }

    public function unlock(): self
    {
        $locked = new Locked();
        $lock_by_column = $locked->lockedByColumn();

        throw_if($locked->cannotBeUnlocked($this), CannotUnlockException::class, 'This model cannot be unlocked');
        $locking_user = $this->{$lock_by_column};

        // Loose on purpose: the column is a bigint, and depending on the driver and on whether
        // prepares are emulated it comes back as an int or as a string. A strict comparison would
        // let a user fail to release their own lock. A null Auth::id() never matches an owner.
        throw_if(
            $locking_user !== null && (int) $locking_user !== (int) Auth::id(),
            CannotUnlockException::class,
            'This model cannot be unlocked because locked by another user',
        );

        $this->writeLockColumns([
            $locked->lockedAtColumn() => null,
            $lock_by_column => null,
            $locked->lockedUntilColumn() => null,
        ]);

        return $this;
    }

    public function isUnlocked(): bool
    {
        return ! $this->isLocked();
    }

    public function isUnlockedBy(User $user): bool
    {
        return ! $this->isLockedBy($user);
    }

    public function isNotUnlocked(): bool
    {
        return ! $this->isUnlocked();
    }

    public function isNotUnlockedBy(User $user): bool
    {
        return ! $this->isUnlockedBy($user);
    }

    public function toggleLock(?User $user = null): self
    {
        if ($this->isLocked()) {
            $this->unlock();
        } else {
            $this->lock($user);
        }

        return $this;
    }

    public function toggleLockBy(?User $user = null): self
    {
        if (! $user instanceof User) {
            $user = Auth::user();
        }

        if ($this->isLocked()) {
            $this->unlock();
        } else {
            $this->lock($user);
        }

        return $this;
    }

    public function wasUnlocked(): bool
    {
        return $this->getOriginal(new Locked()->lockedAtColumn()) === null;
    }

    public function wasUnlockedBy(User $user): bool
    {
        return $this->wasUnlocked() && $user->id === $this->getOriginal(new Locked()->lockedByColumn());
    }

    public function wasLocked(): bool
    {
        if ($this->getOriginal(new Locked()->lockedAtColumn()) === null) {
            return false;
        }

        $locked_until = $this->getOriginal(new Locked()->lockedUntilColumn());

        return $locked_until === null || $this->asDateTime($locked_until)->isFuture();
    }

    public function wasLockedBy(User $user): bool
    {
        return $this->wasLocked() && $user->id === $this->getOriginal(new Locked()->lockedByColumn());
    }

    /**
     * Persists the lock columns without going through the model's save pipeline.
     *
     * A lock is coordination metadata, not record data. Writing it through `save()` would touch
     * `updated_at` and, on a model that also uses {@see HasOptimisticLocking}, reach `performUpdate`
     * and increment `lock_version`. That version is what a client holds for the form it has just
     * opened, and comparing it is how the client learns whether the record changed underneath it:
     * bumping it on acquisition would destroy the very signal the lock is measured by.
     *
     * A model that does not exist yet has nothing to update, so the values are only staged on the
     * instance and the caller's own insert persists them.
     *
     * @param  array<string,mixed>  $values
     */
    protected function writeLockColumns(array $values): void
    {
        if ($this->exists) {
            $this->newQueryWithoutScopes()
                ->whereKey($this->getKey())
                ->toBase()
                ->update($values);
        }

        foreach ($values as $column => $value) {
            $this->setAttribute($column, $value);

            if ($this->exists) {
                $this->syncOriginalAttribute($column);
            }
        }
    }

    /**
     * Mirrors {@see isLocked()}: a row is locked when it carries a `locked_at` and its deadline,
     * if any, has not passed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function locked(Builder $query): Builder
    {
        $locked = new Locked();
        $locked_until = $query->qualifyColumn($locked->lockedUntilColumn());

        return $query
            ->whereNotNull($query->qualifyColumn($locked->lockedAtColumn()))
            ->where(fn (Builder $sub): Builder => $sub
                ->whereNull($locked_until)
                ->orWhere($locked_until, '>', now()));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function lockedBy(Builder $query, User $user): Builder
    {
        return $query->locked()->where(new Locked()->lockedByColumn(), $user->id);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function unlocked(Builder $query): Builder
    {
        $locked = new Locked();
        $locked_until = $query->qualifyColumn($locked->lockedUntilColumn());

        return $query->where(fn (Builder $sub): Builder => $sub
            ->whereNull($query->qualifyColumn($locked->lockedAtColumn()))
            ->orWhere($locked_until, '<=', now()));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function unlockedBy(Builder $query, User $user): Builder
    {
        return $query->unlocked()->where(new Locked()->lockedByColumn(), '!=', $user->id);
    }
}
