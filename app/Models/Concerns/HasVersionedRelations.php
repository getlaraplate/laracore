<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\RelationDescriptor;
use Modules\Core\Versioning\Data\RestoreReport;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;
use Modules\Core\Versioning\Exceptions\AggregateRestoreConflictException;
use Modules\Core\Versioning\Exceptions\MissingRestoreSubjectException;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasVersionedRelations
{
    public function versionedRelationDescriptor(string $relation): ?RelationDescriptor
    {
        foreach ($this->registeredRelations() as $descriptor) {
            if ($descriptor->relation === $relation) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * The registered relation stored in a pivot table with this root on the given pivot column, which the pivot's
     * events record on this root (D42).
     */
    public function versionedRelationThroughPivot(string $table, string $foreignPivotKey): ?RelationDescriptor
    {
        foreach ($this->registeredRelations() as $descriptor) {
            $relation = $this->{$descriptor->relation}();

            if ($relation instanceof BelongsToMany && $table === $relation->getTable() && $foreignPivotKey === $relation->getForeignPivotKeyName()) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Whether this root registers versioned relations, whose changes its save must group with its own (D34).
     */
    public function hasRegisteredRelations(): bool
    {
        return $this->registeredRelations() !== [];
    }

    /**
     * The current aggregate revision: the latest version set that touched this root. Callers pass it
     * back as the expected revision on a restore, so a concurrent change is rejected instead of clobbered.
     */
    public function currentRevision(): ?int
    {
        $max = $this->versions()->max('version_set_id');

        return $max === null ? null : (int) $max;
    }

    /**
     * Restore the aggregate to the state represented by revision `$targetSetId`, as a single, reversible
     * `Revert` version set. Scalars and every declared `reference` relation are reset to that revision;
     * owned relations are out of scope for this milestone. The restore is rejected when the aggregate has
     * moved past `$expectedSetId`. A referenced subject that no longer exists aborts the restore unless
     * `$force` is set, in which case it is skipped and reported.
     */
    public function restoreToRevision(int $targetSetId, int $expectedSetId, bool $force = false): RestoreReport
    {
        $current = $this->currentRevision();

        if ($current !== $expectedSetId) {
            throw new AggregateRestoreConflictException($expectedSetId, $current);
        }

        $targets = [];
        $skipped = [];

        foreach ($this->registeredRelations() as $descriptor) {
            if ($descriptor->isOwned()) {
                continue;
            }

            $relation = $descriptor->relation;
            $existing = [];
            $missing = [];

            foreach ($this->replayMembership($relation, $targetSetId) as $entry) {
                if ($this->referenceSubjectExists($relation, $entry['id'])) {
                    $existing[$entry['id']] = $entry['pivot'];
                } else {
                    $missing[] = $entry['id'];
                }
            }

            if ($missing !== [] && ! $force) {
                throw new MissingRestoreSubjectException($relation, $missing);
            }

            $targets[$relation] = $existing;

            if ($missing !== []) {
                $skipped[$relation] = $missing;
            }
        }

        $this->runInRevertScope($targetSetId, function () use ($targetSetId, $targets): void {
            $this->restoreScalarState($targetSetId);

            foreach ($targets as $relation => $normalized) {
                $this->applyMembershipTarget($relation, $normalized);
            }
        });

        return new RestoreReport(
            targetRevision: $targetSetId,
            restoredRelations: array_keys($targets),
            skippedSubjects: $skipped,
        );
    }

    /**
     * Current membership of a versioned relation, reconstructed by replaying its membership
     * version rows in revision order: a `Created` event adds/updates the subject, a `Deleted`
     * event removes it.
     *
     * @return list<array{id: int|string, pivot: array<string, mixed>}>
     */
    public function versionedRelationMembership(string $relation): array
    {
        return $this->replayMembership($relation, null);
    }

    /**
     * @return list<RelationDescriptor>
     */
    protected function versionedRelations(): array
    {
        return [];
    }

    /**
     * The relations this root registers, checked: a relation to records of the root's own class touches two roots
     * at once and is not versioned in milestone 1 (D24).
     *
     * @return list<RelationDescriptor>
     */
    private function registeredRelations(): array
    {
        $descriptors = $this->versionedRelations();

        foreach ($descriptors as $descriptor) {
            if ($this->{$descriptor->relation}()->getRelated() instanceof static) {
                throw new InvalidArgumentException("Relation '{$descriptor->relation}' links records of the same class, so a change of it touches more than one root: it cannot be versioned (D24).");
            }
        }

        return $descriptors;
    }

    private function versionActor(): ?int
    {
        if (! method_exists($this, 'getVersionUserId')) {
            return null;
        }

        $actor = $this->getVersionUserId();

        return is_numeric($actor) ? (int) $actor : null;
    }

    /**
     * Reconstruct a relation's membership by replaying its version rows in revision order, optionally
     * bounded to sets up to `$uptoSetId` (used to read the membership as of a target revision).
     *
     * @return list<array{id: int|string, pivot: array<string, mixed>}>
     */
    private function replayMembership(string $relation, ?int $uptoSetId): array
    {
        $query = $this->versions()
            ->where('relation_path', $relation)
            ->orderBy('version_set_id')
            ->orderBy('sequence');

        if ($uptoSetId !== null) {
            $query->where('version_set_id', '<=', $uptoSetId);
        }

        $membership = [];

        foreach ($query->get() as $event) {
            $id = $event->subject_key['id'] ?? null;

            if ($id === null) {
                continue;
            }

            if ($event->change_type === VersionChangeType::Deleted) {
                unset($membership[$id]);

                continue;
            }

            $membership[$id] = ['id' => $id, 'pivot' => $event->contents ?? []];
        }

        return array_values($membership);
    }

    /**
     * Bring the live relation into line with a target membership; the pivot's events write one membership row per
     * change (D42). Assumes an active version-set scope; detaches members absent from the target and upserts the rest.
     *
     * @param  array<int|string, array<string, mixed>>  $normalized
     */
    private function applyMembershipTarget(string $relation, array $normalized): void
    {
        $current_ids = array_map(
            static fn (array $entry): int|string => $entry['id'],
            $this->versionedRelationMembership($relation),
        );
        $to_detach = array_diff($current_ids, array_keys($normalized));

        // The pivot's events write the membership rows (D42).
        foreach ($to_detach as $subject_id) {
            $this->{$relation}()->detach($subject_id);
        }

        foreach ($normalized as $subject_id => $pivot) {
            $this->{$relation}()->syncWithoutDetaching([$subject_id => $pivot]);
        }
    }

    private function runInRevertScope(int $targetSetId, Closure $operation): void
    {
        app(VersionSetManagerInterface::class)->run(
            VersionSetRoot::forModel($this),
            $operation,
            new VersionSetOptions(
                kind: VersionSetKind::Revert,
                actor: $this->versionActor(),
                revertedFrom: $targetSetId,
            ),
        );
    }

    private function restoreScalarState(int $targetSetId): void
    {
        $version = $this->versions()
            ->whereNull('relation_path')
            ->where('version_set_id', '<=', $targetSetId)
            ->orderByDesc('version_set_id')
            ->orderByDesc('sequence')
            ->first();

        $version?->setRelation('versionable', $this)->revert();
    }

    private function referenceSubjectExists(string $relation, int|string $subjectId): bool
    {
        return $this->{$relation}()->getRelated()->newQueryWithoutScopes()->whereKey($subjectId)->exists();
    }
}
