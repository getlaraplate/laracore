<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Versioning\Data\RelationDescriptor;

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
     * The relations this root registers as part of its history, checked as everywhere else (D24).
     *
     * @return list<RelationDescriptor>
     */
    public function versionedRelationDescriptors(): array
    {
        return $this->registeredRelations();
    }

    /**
     * Whether this root registers versioned relations, whose changes its save must group with its own (D34).
     */
    public function hasRegisteredRelations(): bool
    {
        return $this->registeredRelations() !== [];
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
     * The links of every registered relation as the pivot tables hold them now, with their authoritative pivot
     * columns: what a hard delete keeps in the snapshot set (D55).
     *
     * @return list<array{relation: string, id: int|string, pivot: array<string, mixed>}>
     */
    public function currentVersionedMembership(): array
    {
        $links = [];

        foreach ($this->registeredRelations() as $descriptor) {
            $relation = $this->{$descriptor->relation}();

            if (! $relation instanceof BelongsToMany) {
                continue;
            }

            $related_key = $relation->getRelatedPivotKeyName();

            foreach ($relation->newPivotQuery()->orderBy($related_key)->get() as $row) {
                $row = (array) $row;
                $id = $row[$related_key] ?? null;

                if (is_int($id) || is_string($id)) {
                    $links[] = ['relation' => $descriptor->relation, 'id' => $id, 'pivot' => Arr::only($row, $descriptor->pivotColumns)];
                }
            }
        }

        return $links;
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
     * Brings a registered relation into line with a target membership, compared with the links the pivot table holds
     * now: a link missing from the target is detached, a missing one attached, one whose declared pivot columns differ
     * updated, the others left alone. The pivot's events write one membership row per change (D42), in the scope the
     * caller opened.
     *
     * @param  array<int|string, array<string, mixed>>  $target  related key to its declared pivot columns
     */
    public function replaceMembership(string $relation, array $target): void
    {
        $descriptor = $this->versionedRelationDescriptor($relation);
        $pivot_columns = $descriptor?->pivotColumns ?? [];
        $live = [];

        foreach ($this->currentVersionedMembership() as $link) {
            if ($link['relation'] === $relation) {
                $live[$link['id']] = $link['pivot'];
            }
        }

        foreach (array_keys(array_diff_key($live, $target)) as $subject_id) {
            $this->{$relation}()->detach($subject_id);
        }

        foreach ($target as $subject_id => $pivot) {
            $pivot = Arr::only($pivot, $pivot_columns);

            if (! array_key_exists($subject_id, $live)) {
                $this->{$relation}()->attach($subject_id, $pivot);
            } elseif ($pivot !== [] && self::comparablePivot($pivot) !== self::comparablePivot($live[$subject_id])) {
                $this->{$relation}()->updateExistingPivot($subject_id, $pivot);
            }
        }
    }

    /**
     * Whether the record a link points at still exists, in the bin included (D25).
     */
    public function referenceSubjectExists(string $relation, int|string $subjectId): bool
    {
        return $this->{$relation}()->getRelated()->newQueryWithoutScopes()->whereKey($subjectId)->exists();
    }

    /**
     * Pivot values as the database returns them and as the history stored them differ in type (`1` and `"1"`): both
     * are compared as strings, keys sorted.
     *
     * @param  array<string, mixed>  $pivot
     * @return array<string, string|null>
     */
    private static function comparablePivot(array $pivot): array
    {
        ksort($pivot);

        return array_map(
            static fn (mixed $value): ?string => match (true) {
                $value === null => null,
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_THROW_ON_ERROR),
            },
            $pivot,
        );
    }
}
