<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Approvals\Operation;
use Modules\Core\Contracts\RestrictsCrudWrites;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\DeniesGenericCrudWrites;
use Override;

/**
 * A write captured before it happened, with the votes cast on it.
 *
 * Derived from cloudcake/laravel-approval (MIT), see NOTICES/laravel-approval.md.
 * The relations, the remaining-vote counters and the active/inactive scopes were the
 * package's; the unused parts of its model (`forceApprovalUpdate()`, the
 * `approvalsRemaining`/`disapprovalsRemaining` aliases, the `changes`/`creations`
 * scopes) were not brought over.
 *
 * Only the approval flow writes it: votes and withdrawals go through ModificationVoteService,
 * which keeps the decision coherent with the quorum. The generic CRUD layer may read it but
 * never write it, since a plain save of the quorum would leave a reached decision unapplied.
 */
final class Modification extends Model implements RestrictsCrudWrites
{
    use DeniesGenericCrudWrites;
    use HasFactory;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Modifications->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $guarded = ['id'];

    /**
     * @var array<int,string>
     *
     * @psalm-suppress NonInvariantPropertyType
     */
    #[Override]
    protected $hidden = [
        'modifiable_id',
        'modifiable_type',
        'md5',
        // `active` and the modifier identity (modifier_id/type) stay visible so an
        // eager-loaded modifications relation lets the UI flag records with a
        // pending (active) modification and tell whether the viewer authored it.
        'operation',
        'approvers_required',
        'disapprovers_required',
    ];

    /**
     * The record the captured write belongs to. Null while a `create` waits for approval:
     * there is no record yet.
     *
     * @return MorphTo<Model, $this>
     */
    public function modifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whoever asked for the write. Only they may withdraw the request.
     *
     * @return MorphTo<Model, $this>
     */
    public function modifier(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * @return HasMany<Disapproval, $this>
     */
    public function disapprovals(): HasMany
    {
        return $this->hasMany(Disapproval::class);
    }

    /**
     * Latest vote meta from approvals or disapprovals (e.g. AI moderation payload).
     *
     * @return array<string, mixed>|null
     */
    public function latestAutomatedVoteMeta(): ?array
    {
        /** @var Approval|null $approval */
        $approval = Approval::query()
            ->where('modification_id', $this->id)
            ->whereNotNull('meta')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'meta', 'created_at']);

        /** @var Disapproval|null $disapproval */
        $disapproval = Disapproval::query()
            ->where('modification_id', $this->id)
            ->whereNotNull('meta')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'meta', 'created_at']);

        if ($approval === null && $disapproval === null) {
            return null;
        }

        if ($approval === null) {
            /** @var array<string, mixed>|null */
            return $disapproval->meta;
        }

        if ($disapproval === null) {
            /** @var array<string, mixed>|null */
            return $approval->meta;
        }

        $latest = $approval->created_at >= $disapproval->created_at ? $approval : $disapproval;

        /** @var array<string, mixed>|null */
        return $latest->meta;
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function activeOnly(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inactiveOnly(Builder $query): void
    {
        $query->where('active', false);
    }

    /**
     * How many approvals are still missing before the decision is complete.
     *
     * @return Attribute<int, never>
     */
    protected function approversRemaining(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->approvers_required - $this->approvals()->count());
    }

    /**
     * @return Attribute<int, never>
     */
    protected function disapproversRemaining(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->disapprovers_required - $this->disapprovals()->count());
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'modifications' => 'json',
            'active' => 'boolean',
            'operation' => Operation::class,
        ];
    }
}
