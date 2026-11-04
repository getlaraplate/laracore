<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes as EloquentSoftDeletes;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Core\Approvals\Operation;
use Modules\Core\Approvals\PendingDeletionLock;
use Modules\Core\Approvals\PendingDeletionStrategy;
use Modules\Core\Casts\CrudExecutor;
use Modules\Core\Models\Approval;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\RelationAuthorizer;
use Modules\Core\Services\ModificationVoteService;
use Modules\Core\SoftDeletes\SoftDeletes;
use Modules\Core\Support\PermissionName;
use TypeError;

/**
 * Captures a write that its author may not apply alone, as a Modification waiting for approval.
 *
 * Derived from cloudcake/laravel-approval (MIT), see NOTICES/laravel-approval.md: the capture
 * body, the modifications relation and applyModificationChanges() were the package's.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-type HasApprovalsType HasApprovals
 */
trait HasApprovals
{
    /**
     * How many approvals mark the captured write as accepted.
     */
    protected int $approversRequired = 1;

    /**
     * How many disapprovals mark it as rejected.
     */
    protected int $disapproversRequired = 1;

    /**
     * Whether an approved diff is written to the model.
     */
    protected bool $updateWhenApproved = true;

    /**
     * Set while an approved modification is being written, so the listener lets it through.
     */
    private bool $forcedApprovalUpdate = false;

    /**
     * The request the last intercepted operation was turned into, so the caller can tell a
     * captured write from one that happened.
     */
    private ?Modification $pendingModification = null;

    /**
     * Intercept every save, delete and restore, and capture it when the writer may not apply
     * it alone. Laravel's forceDelete() fires `deleting` too (with isForceDeleting() true), so
     * listening to `deleting` covers both kinds of deletion; a restore refused by `restoring`
     * never reaches the save it would have made. A model without soft deletes has no restore.
     * While a deletion waits, a Block model refuses to save and a Hide model is filtered out
     * for the users who cannot decide on it.
     *
     * Derived from cloudcake/laravel-approval (MIT), see NOTICES/laravel-approval.md.
     */
    public static function bootHasApprovals(): void
    {
        static::saving(static function (Model $item): ?bool {
            if ($item->exists && ! $item->isForcedApprovalUpdate() && $item->pendingDeletionStrategy() === PendingDeletionStrategy::Block) {
                $blocked = array_diff(array_keys($item->getDirty()), $item->attributesWritableWhilePendingDeletion(), [$item->getUpdatedAtColumn()]);

                if ($blocked !== [] && $item->modifications()->activeOnly()->whereIn('operation', [Operation::Delete->value, Operation::ForceDelete->value])->exists()) {
                    throw PendingDeletionLock::for($item::class, $item->getKey());
                }
            }

            if ($item->shouldCapture($item->exists ? Operation::Update : Operation::Create) && $item->requiresApprovalWhen($item->getDirtyForApproval()) === true) {
                // Model validation runs on creating/updating, which a captured write never reaches:
                // validate here so a pending request only ever carries data the model accepts.
                if (method_exists($item, 'validateWithRules') && ! $item->shouldSkipValidation()) {
                    $item->validateWithRules($item->exists ? CrudExecutor::UPDATE : CrudExecutor::INSERT);
                }

                return static::captureSave($item);
            }

            $item->setForcedApprovalUpdate(false);

            return null;
        });

        // A record deleted for good can no longer receive the changes still waiting for approval, so they are
        // closed. The rows stay, with their votes: they are the record of who asked and who approved, the
        // deletion itself included when it was approved. A move to the bin changes nothing.
        static::deleted(static function (Model $item): void {
            if (! $item->exists) {
                $item->modifications()->activeOnly()->toBase()->update(['active' => false]);
            }
        });

        static::deleting(static function (Model $item): ?bool {
            $operation = $item->deletionOperation();

            return $item->shouldCapture($operation) && $item->requiresApprovalForOperation($operation)
                ? static::captureOperation($item, $operation)
                : null;
        });

        static::addGlobalScope(PendingDeletionStrategy::HIDE_SCOPE, static function (Builder $query): void {
            $model = $query->getModel();

            if ($model->pendingDeletionStrategy() !== PendingDeletionStrategy::Hide) {
                return;
            }

            $user = Auth::user();

            // Nobody authenticated: console, queues, jobs, search indexing, exports. They see
            // everything, exactly as capture is skipped in console. Hide answers "who may not
            // see a record they cannot decide on", which is a question about a person; a
            // pending deletion must not quietly change what background work reads.
            if (! $user instanceof User) {
                return;
            }

            if (resolve(AuthorizationService::class)->userHolds($user, PermissionName::forModel($model, 'approve'))) {
                return;
            }

            $query->withoutPendingDeletion();
        });

        if (! method_exists(static::class, 'restoring')) {
            return;
        }

        static::restoring(static function (Model $item): ?bool {
            if (! $item->trashed()) {
                return null;
            }

            return $item->shouldCapture(Operation::Restore) && $item->requiresApprovalForOperation(Operation::Restore)
                ? static::captureOperation($item, Operation::Restore)
                : null;
        });
    }

    /**
     * Turn a delete, force delete or restore into a request waiting for approval. The request
     * carries no diff, only the operation. A record has at most one pending request per
     * operation: repeating it, by anybody, joins the pending one, whose author and quorum stay
     * as they are (two pending deletions would leave the second one acting on a record the
     * first already removed).
     *
     * @param  Model&self  $item
     */
    public static function captureOperation(Model $item, Operation $operation): bool
    {
        $existing = $item->pendingOperationRequest($operation);

        $modification = $existing ?? new Modification();
        $modification->active = true;
        $modification->operation = $operation;
        $modification->modifications = [];
        $modification->md5 = md5($operation->value . '|' . $item::class . '|' . $item->getKey());

        if ($existing === null) {
            $modification->approvers_required = $item->approversRequired;
            $modification->disapprovers_required = $item->disapproversRequired;

            $modifier = $item->modifier();

            if ($modifier !== null) {
                $modification->modifier()->associate($modifier);
            }

            $item->modifications()->save($modification);
        } else {
            $modification->save();
        }

        $item->applyAuthorApproveCredit($modification);
        $item->pendingModification = $modification->active ? $modification : null;

        return false;
    }

    /**
     * Capture a pending modification, then apply the writer's approve-permission credit when N > 1.
     *
     * Saving the same diff again reuses the author's own pending request and leaves its quorum
     * as it is: the quorum may have been set after capture (AI moderation does), and resetting
     * it would leave a decision the votes already reach unapplied. Another author saving the
     * same diff gets a request of their own, so nobody takes over a request others voted on.
     *
     * @param  Model&self  $item
     */
    public static function captureSave($item): bool
    {
        $diff = collect($item->getDirtyForApproval())
            ->transform(static function ($change, $key) use ($item): array {
                return [
                    'original' => $item->getOriginal($key),
                    'modified' => $item->{$key},
                ];
            })->all();
        $md5 = md5(json_encode($diff, JSON_THROW_ON_ERROR));
        $modifier = $item->modifier();

        $pending_request = $item->modifications()->activeOnly()->where('md5', $md5);

        if ($modifier !== null) {
            $pending_request->where('modifier_type', $modifier::class)->where('modifier_id', $modifier->getKey());
        } else {
            $pending_request->whereNull('modifier_id');
        }

        $has_modification_pending = $pending_request->first();

        $modification = $has_modification_pending ?? new Modification();
        $modification->active = true;
        $modification->modifications = $diff;
        $modification->md5 = $md5;
        $modification->operation = $item->{$item->getKeyName()} === null ? Operation::Create : Operation::Update;

        if ($has_modification_pending === null) {
            $modification->approvers_required = $item->approversRequired;
            $modification->disapprovers_required = $item->disapproversRequired;

            if ($modifier !== null) {
                $modification->modifier_id = $modifier->getKey();
                $modification->modifier_type = $modifier::class;
            }

            $item->modifications()->save($modification);
        } else {
            $modification->save();
        }

        $item->applyAuthorApproveCredit($modification);
        $item->pendingModification = $modification->active ? $modification : null;

        return false;
    }

    /**
     * @return list<Operation>
     */
    public function approvalOperations(): array
    {
        return Operation::cases();
    }

    /**
     * How the record behaves while its deletion waits for approval. Block by default: the
     * record stays visible and refuses changes.
     */
    public function pendingDeletionStrategy(): PendingDeletionStrategy
    {
        return PendingDeletionStrategy::Block;
    }

    /**
     * Attributes that system writes may still change on a Block record whose deletion waits,
     * such as counters or sync timestamps. `updated_at` is always writable.
     *
     * @return list<string>
     */
    public function attributesWritableWhilePendingDeletion(): array
    {
        return [];
    }

    /**
     * The request the last intercepted operation on this instance became, or null when the
     * operation ran, including when the author's own credit completed the quorum at once.
     */
    public function pendingModification(): ?Modification
    {
        return $this->pendingModification;
    }

    /**
     * Whether the operation would be sent for approval, without running it.
     */
    public function wouldRequireApproval(Operation $operation): bool
    {
        return $this->shouldCapture($operation) && $this->requiresApprovalForOperation($operation);
    }

    public function pendingOperationRequest(Operation $operation): ?Modification
    {
        return $this->modifications()->activeOnly()->where('operation', $operation->value)->first();
    }

    public function isForcedApprovalUpdate(): bool
    {
        return $this->forcedApprovalUpdate;
    }

    public function setForcedApprovalUpdate(bool $forced = true): void
    {
        $this->forcedApprovalUpdate = $forced;
    }

    /**
     * Whoever is writing, recorded on the modification as its author.
     */
    public function modifier(): ?Model
    {
        $user = Auth::user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * Declared again over RequiresApproval, which returns a bare MorphMany against a
     * configurable class: nothing downstream knew what was on the other end, and the
     * scopes the Modification model carries (activeOnly, inactiveOnly) resolved against
     * Model instead. The class is no longer configurable because the model is ours.
     *
     * @return MorphMany<Modification, $this>
     */
    public function modifications(): MorphMany
    {
        return $this->morphMany(Modification::class, 'modifiable');
    }

    /**
     * Apply a decided modification to the model, or record the decision when nothing is
     * applied. An approved delete, force delete or restore runs the operation; an approved
     * create or update writes its diff. Either way the modification is deactivated and kept.
     * Called only by ModificationVoteService, which wraps it in the vote's transaction.
     *
     * Derived from cloudcake/laravel-approval (MIT), see NOTICES/laravel-approval.md.
     */
    public function applyModificationChanges(Modification $modification, bool $approved): void
    {
        if ($approved && ! $modification->operation->carriesDiff()) {
            $this->setForcedApprovalUpdate(true);

            try {
                match ($modification->operation) {
                    Operation::Delete => $this->delete(),
                    Operation::ForceDelete => method_exists($this, 'forceDelete') ? $this->forceDelete() : $this->delete(),
                    Operation::Restore => $this->restore(),
                    default => null,
                };
            } finally {
                $this->setForcedApprovalUpdate(false);
            }

            $this->settleModification($modification);

            return;
        }

        if ($approved && $this->updateWhenApproved) {
            $this->setForcedApprovalUpdate(true);

            foreach ($modification->modifications as $key => $change) {
                $this->{$key} = $change['modified'];
            }

            $this->save();

            $this->settleModification($modification);

            return;
        }

        if ($approved === false) {
            $this->settleModification($modification);
        }
    }

    public function initializeHasApprovals(): void
    {
        if (preview()) {
            $this->append('preview');
            $this->makeHidden('preview');
        }
    }

    public function toArray(?array $parsed = null): array
    {
        if (! $this->preview) {
            return parent::toArray();
        }

        $preview = array_merge($this->preview, $this->relationsToArray());
        $preview['_'] = array_merge($this->attributesToArray());

        foreach ($preview['_'] as $key => $value) {
            if ($preview[$key] === $value) {
                unset($preview['_'][$key]);
            }
        }

        return $preview;
    }

    /**
     * The change set a capture decides on. `getDirty()` for most models; a model whose write
     * goes through a staging area (translations, pending scores) folds it in here, as
     * {@see \Modules\CMS\Models\Comment::getDirtyForApproval()} does.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForApproval(): array
    {
        return $this->getDirty();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getPreviewAttribute(): ?array
    {
        // preview(), not session('preview'): on app/api the flag is request-scoped and
        // the session is deliberately unread.
        if (! preview()) {
            return null;
        }

        // The preview shows the values somebody proposed and nobody approved yet: only to a caller who may change
        // or approve the record.
        if (! resolve(RelationAuthorizer::class)->actingUserMaySeePendingChanges($this)) {
            return null;
        }

        $preview = $this->attributesToArray();

        /** @var Modification $modification */
        foreach ($this->modifications()->activeOnly()->oldest()->select(['modifications'])->cursor() as $modification) {
            /** @phpstan-ignore property.notFound */
            foreach ($modification->modifications as $key => $mod) {
                $preview[$key] = $mod['modified'];
            }
        }

        return $preview;
    }

    /**
     * Records with no deletion waiting for approval.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withoutPendingDeletion(Builder $query): void
    {
        $query->whereDoesntHave('modifications', static fn (Builder $modifications): Builder => $modifications
            ->where('active', true)
            ->whereIn('operation', [Operation::Delete->value, Operation::ForceDelete->value]));
    }

    /**
     * @param  array<string, mixed>  $modifications
     *
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws TypeError
     */
    protected function requiresApprovalWhen($modifications): bool
    {
        return $modifications !== [] && $this->approvalGate();
    }

    /**
     * Whether this operation needs approval. Default: the shared rule, which knows nothing
     * about fields, so an operation without a diff needs no special case. A model overrides
     * this to exempt some states, as Content does for drafts.
     */
    protected function requiresApprovalForOperation(Operation $operation): bool
    {
        return $this->approvalGate();
    }

    /**
     * The shared write rule, with no change set in it: console never needs approval, a
     * superadmin never does, and a writer holding `approve` does not when one approval is
     * enough. Extracted so requiresApprovalWhen() and requiresApprovalForOperation() share
     * it without either having to fake the other's argument.
     */
    protected function approvalGate(): bool
    {
        if (App::runningInConsole()) {
            return false;
        }

        /** @var User|null $user */
        $user = Auth::user();

        // Whatever a superadmin writes is approved by definition.
        if ($user instanceof User && $user->isSuperAdmin()) {
            return false;
        }

        return ! ($user instanceof User && $this->writerHasApproveCredit($user) && $this->approversRequired <= 1);
    }

    protected function shouldCapture(Operation $operation): bool
    {
        return ! $this->isForcedApprovalUpdate() && in_array($operation, $this->approvalOperations(), true);
    }

    /**
     * Soft delete unless the model has none, the caller forces the deletion, or the table's
     * soft delete is switched off in settings: then the row would really go.
     */
    protected function deletionOperation(): Operation
    {
        $traits = class_uses_recursive($this);
        $soft = isset($traits[EloquentSoftDeletes::class]) || isset($traits[SoftDeletes::class]);

        if (! $soft || (method_exists($this, 'isForceDeleting') && $this->isForceDeleting())) {
            return Operation::ForceDelete;
        }

        if (method_exists($this, 'softDeletesEnabledBySettings') && ! $this->softDeletesEnabledBySettings()) {
            return Operation::ForceDelete;
        }

        return Operation::Delete;
    }

    /**
     * One write-time approve credit from `approve.{connection}.{table}`, held on the request's guard and within
     * the abilities of the writer's token.
     */
    protected function writerHasApproveCredit(User $user): bool
    {
        return resolve(AuthorizationService::class)->userHolds($user, PermissionName::forModel($this, 'approve'));
    }

    /**
     * When N > 1 and the writer holds approve permission, record one automatic Approval
     * (meta source: author_approve_permission). Does not apply when N = 1 (no mod created).
     */
    protected function applyAuthorApproveCredit(Modification $modification): void
    {
        if ((int) $modification->approvers_required <= 1) {
            return;
        }

        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User || ! $this->writerHasApproveCredit($user)) {
            return;
        }

        $connection = $modification->getConnectionName() ?? $this->getConnectionName();

        (new Approval())->setConnection($connection)->newQuery()->updateOrCreate([
            'approver_id' => $user->getKey(),
            'approver_type' => $user::class,
            'modification_id' => $modification->getKey(),
        ], [
            'reason' => null,
            'meta' => ['source' => 'author_approve_permission'],
        ]);

        $modification->refresh();

        if ((int) $modification->approversRemaining <= 0) {
            resolve(ModificationVoteService::class)->applyAuthorCredit($modification, $this);
        }
    }

    /**
     * A decided modification is deactivated and kept, so the decision stays on record.
     */
    private function settleModification(Modification $modification): void
    {
        $modification->active = false;
        $modification->save();
    }
}
