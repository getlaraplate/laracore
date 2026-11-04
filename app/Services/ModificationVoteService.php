<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Modules\Core\Approvals\Operation;
use Modules\Core\Approvals\PendingDeletionStrategy;
use Modules\Core\Events\ModificationApproved;
use Modules\Core\Events\ModificationRejected;
use Modules\Core\Events\ModificationWithdrawn;
use Modules\Core\Models\Approval;
use Modules\Core\Models\Disapproval;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;

/**
 * Casts an approval or disapproval vote on a pending modification and applies it once the quorum is reached.
 *
 * Shared by the CRUD API and the Filament panel so both vote through the same rules.
 *
 * Derived from cloudcake/laravel-approval (MIT), see NOTICES/laravel-approval.md: it took over the
 * voting and application the package's ApprovesChanges trait performed on the user model.
 */
final class ModificationVoteService
{
    /**
     * Whether the user may cast this vote: the author of a modification never votes on it.
     */
    public function canVote(User $user, Modification $modification, bool $approval): bool
    {
        return (bool) $modification->active && $user->isAuthorizedToCastApprovalVote($modification, $approval);
    }

    /**
     * Cast a vote using the modification owner's connection. The vote and, when it completes
     * the quorum, the application of the decision run in one transaction: if applying fails,
     * the vote is rolled back and the request stays pending. The decision's event fires once
     * the transaction commits.
     *
     * @return bool false when the user is not authorized to vote or the request is already decided
     */
    public function cast(User $user, Modification $modification, bool $approval, ?string $reason = null, ?Model $modifiable = null): bool
    {
        return $modification->getConnection()->transaction(fn (): bool => $this->castInTransaction($user, $modification, $approval, $reason, $modifiable));
    }

    /**
     * Set the quorum a request needs and cast a vote on it, in one transaction.
     *
     * The new quorum counts the votes already on the request: lowering it can complete
     * either side, and that side is applied, so the decision always matches the totals.
     * Changing the quorum with a plain save would leave a reached quorum unapplied.
     *
     * @param  array<string, mixed>  $meta  stored on the vote
     * @return bool false when the user is not authorized to vote or the request is already decided
     *
     * @throws InvalidArgumentException when a quorum is lower than one vote
     */
    public function castWithQuorum(
        User $user,
        Modification $modification,
        bool $approval,
        int $approvers_required,
        int $disapprovers_required,
        ?string $reason = null,
        array $meta = [],
        ?Model $modifiable = null,
    ): bool {
        throw_if(
            $approvers_required < 1 || $disapprovers_required < 1,
            InvalidArgumentException::class,
            'A quorum needs at least one vote.',
        );

        return $modification->getConnection()->transaction(fn (): bool => $this->castInTransaction(
            $user,
            $modification,
            $approval,
            $reason,
            $modifiable,
            ['approvers' => $approvers_required, 'disapprovers' => $disapprovers_required],
            $meta,
        ));
    }

    /**
     * Apply a modification whose quorum was completed by the author's own approve credit.
     * Not a vote: cast() would refuse it, because the author never votes on their own request.
     */
    public function applyAuthorCredit(Modification $modification, Model $modifiable): void
    {
        $connection = $modification->getConnection();

        $connection->transaction(function () use ($modification, $modifiable): void {
            $modifiable->applyModificationChanges($modification, true);

            if ($modification->operation->isDeletion()) {
                $this->rejectPendingUpdatesOf($modification, $modification->modifier);
            }
        });

        $connection->afterCommit(static fn () => event(new ModificationApproved($modification, $modifiable)));
    }

    /**
     * Withdraw a request before its decision: the modification and every vote on it are
     * deleted, and the record stays as it was. Only the author may withdraw.
     *
     * @throws AuthorizationException when the user is not the author
     * @throws LogicException when the request is already decided
     */
    public function withdraw(User $user, Modification $modification): void
    {
        throw_unless(
            $modification->modifier_type === $user::class && (string) $modification->modifier_id === (string) $user->getKey(),
            AuthorizationException::class,
            'Only the author can withdraw a request.',
        );
        throw_unless($modification->active, LogicException::class, 'A decided request cannot be withdrawn.');

        $modifiable = $this->recordOf($modification);
        $connection = $modification->getConnection();

        $connection->transaction(static function () use ($modification): void {
            $modification->approvals()->delete();
            $modification->disapprovals()->delete();
            $modification->delete();
        });

        $connection->afterCommit(static fn () => event(new ModificationWithdrawn($modification, $modifiable)));
    }

    /**
     * @param  array{approvers: int, disapprovers: int}|null  $quorum  null keeps the request's quorum
     * @param  array<string, mixed>  $meta
     */
    private function castInTransaction(
        User $user,
        Modification $modification,
        bool $approval,
        ?string $reason,
        ?Model $modifiable,
        ?array $quorum = null,
        array $meta = [],
    ): bool {
        $connection = $modification->getConnectionName();

        if ($modifiable instanceof Model) {
            $modification->setRelation('modifiable', $modifiable);
        }

        // A decided request is never voted on again: applying it twice would rerun its diff or operation.
        if (! $modification->active || ! $user->isAuthorizedToCastApprovalVote($modification, $approval)) {
            return false;
        }

        $vote = $approval
            ? (new Approval)->setConnection($connection)
            : (new Disapproval)->setConnection($connection);
        $opposite_vote = $approval
            ? (new Disapproval)->setConnection($connection)
            : (new Approval)->setConnection($connection);
        $actor_id_column = $approval ? 'approver_id' : 'disapprover_id';
        $actor_type_column = $approval ? 'approver_type' : 'disapprover_type';
        $opposite_id_column = $approval ? 'disapprover_id' : 'approver_id';
        $opposite_type_column = $approval ? 'disapprover_type' : 'approver_type';

        if ($quorum !== null) {
            $modification->approvers_required = $quorum['approvers'];
            $modification->disapprovers_required = $quorum['disapprovers'];
            $modification->save();
        }

        $opposite_vote->newQuery()->where([
            $opposite_id_column => $user->getKey(),
            $opposite_type_column => $user::class,
            'modification_id' => $modification->getKey(),
        ])->delete();

        $vote->newQuery()->updateOrCreate([
            $actor_id_column => $user->getKey(),
            $actor_type_column => $user::class,
            'modification_id' => $modification->getKey(),
        ], array_merge(['reason' => $reason], $meta === [] ? [] : ['meta' => $meta]));

        $modification->refresh();
        $decision = $this->reachedDecision($modification, $approval);

        if ($decision === null) {
            return true;
        }

        $record = $this->recordOf($modification);

        if ($record instanceof Model) {
            $target = $record;
        } else {
            throw_unless(is_string($modification->modifiable_type), LogicException::class, 'Modifiable type is required.');
            $modifiable_type = $modification->modifiable_type;

            /** @var Model $target */
            $target = (new $modifiable_type)->setConnection($connection);
        }

        $target->applyModificationChanges($modification, $decision);

        if ($decision && $modification->operation->isDeletion()) {
            $this->rejectPendingUpdatesOf($modification, $user);
        }

        $modification->getConnection()->afterCommit(static fn () => event($decision
            ? new ModificationApproved($modification, $target)
            : new ModificationRejected($modification, $record)));

        return true;
    }

    /**
     * The side whose quorum the votes now reach, or null while neither is reached. A side is
     * reached when its votes meet or exceed its quorum: a quorum lowered below the votes
     * already cast counts as reached. The side just voted for wins when both are reached.
     */
    private function reachedDecision(Modification $modification, bool $cast_approval): ?bool
    {
        $approval_reached = (int) $modification->approversRemaining <= 0;
        $disapproval_reached = (int) $modification->disapproversRemaining <= 0;
        $cast_side_reached = $cast_approval ? $approval_reached : $disapproval_reached;
        $other_side_reached = $cast_approval ? $disapproval_reached : $approval_reached;

        if ($cast_side_reached) {
            return $cast_approval;
        }

        if ($other_side_reached) {
            return ! $cast_approval;
        }

        return null;
    }

    /**
     * The record a modification is about, or null for a create. The voter decides on the
     * record, so no scope may hide it: not the soft-delete one (a restore targets a trashed
     * record), not the pending-deletion one.
     */
    private function recordOf(Modification $modification): ?Model
    {
        if ($modification->modifiable_id === null) {
            return null;
        }

        return $modification->modifiable()
            ->withoutGlobalScope(PendingDeletionStrategy::HIDE_SCOPE)
            ->when($modification->operation === Operation::Restore, static fn (Builder $query): Builder => $query->withTrashed())
            ->first();
    }

    /**
     * An approved deletion leaves nothing for the record's pending updates to change: they are
     * rejected on the deciding user's behalf, with a reason saying why.
     */
    private function rejectPendingUpdatesOf(Modification $deletion, Model $decider): void
    {
        $deletion->newQuery()
            ->where('modifiable_type', $deletion->modifiable_type)
            ->where('modifiable_id', $deletion->modifiable_id)
            ->where('operation', Operation::Update->value)
            ->activeOnly()
            ->each(static function (Modification $update) use ($decider): void {
                $update->disapprovals()->create([
                    'disapprover_id' => $decider->getKey(),
                    'disapprover_type' => $decider::class,
                    'reason' => 'record deleted',
                ]);
                $update->active = false;
                $update->save();
            });
    }
}
