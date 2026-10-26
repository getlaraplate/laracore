<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Versioning\Contracts\VersionWriterInterface;
use Modules\Core\Versioning\Data\RelationDescriptor;
use Modules\Core\Versioning\Data\VersionChange;

/**
 * A custom pivot whose events record the membership of a versioned relation on its root (D42): a link made writes a
 * `created` row, a link removed a `deleted` row, a change of a pivot column the relation declares authoritative an
 * `updated` row (D41), each with `relation_path` = the relation and `subject_key` = the linked record. Laravel fires
 * these events for `attach`, `detach` (also without ids), `sync`, `toggle` and `updateExistingPivot` of a relation
 * with a custom pivot, from either side; a raw query on the pivot table or a foreign-key cascade writes nothing.
 *
 * @phpstan-require-extends Pivot
 */
trait RecordsMembershipOnRoot
{
    public static function bootRecordsMembershipOnRoot(): void
    {
        static::created(static function (Pivot $pivot): void {
            $pivot->recordMembership(VersionChangeType::Created);
        });

        static::updated(static function (Pivot $pivot): void {
            $pivot->recordMembership(VersionChangeType::Updated);
        });

        static::deleted(static function (Pivot $pivot): void {
            $pivot->recordMembership(VersionChangeType::Deleted);
        });
    }

    private function recordMembership(VersionChangeType $type): void
    {
        $membership = $this->membershipOnRoot();

        if ($membership === null) {
            return;
        }

        [$root, $descriptor, $subject_id] = $membership;
        $contents = $type === VersionChangeType::Deleted ? [] : Arr::only($this->getAttributes(), $descriptor->pivotColumns);

        if ($type === VersionChangeType::Updated && $contents === Arr::only($this->getRawOriginal(), $descriptor->pivotColumns)) {
            return;
        }

        resolve(VersionWriterInterface::class)->write(new VersionChange(
            model: $root,
            type: $type,
            originalContents: [],
            contents: $contents,
            strategy: VersionStrategy::SNAPSHOT,
            userId: $root->getVersionUserId(),
            relationPath: $descriptor->relation,
            subjectKey: ['id' => $subject_id],
        ));
    }

    /**
     * The root this link belongs to, the relation it registers and the linked record: the pivot's parent when the
     * link was made from the root, otherwise its related record, when the link was made from the other side.
     *
     * @return array{0: Model, 1: RelationDescriptor, 2: int|string}|null
     */
    private function membershipOnRoot(): ?array
    {
        $foreign_key = $this->getForeignKey();
        $related_key = $this->getRelatedKey();

        if ($this->pivotParent instanceof Model) {
            $descriptor = $this->relationRegisteredBy($this->pivotParent, $foreign_key);
            $subject_id = $this->getAttribute($related_key);

            if ($descriptor instanceof RelationDescriptor && (is_int($subject_id) || is_string($subject_id))) {
                return [$this->pivotParent, $descriptor, $subject_id];
            }
        }

        if ($this->pivotRelated instanceof Model) {
            $descriptor = $this->relationRegisteredBy($this->pivotRelated, $related_key);
            $root_id = $this->getAttribute($related_key);
            $subject_id = $this->getAttribute($foreign_key);

            if ($descriptor instanceof RelationDescriptor && (is_int($root_id) || is_string($root_id)) && (is_int($subject_id) || is_string($subject_id))) {
                // The related model of a relation is a blank instance: the root is named by its key, not loaded.
                $root = $this->pivotRelated->newInstance([], true);
                $root->setAttribute($root->getKeyName(), $root_id);

                return [$root, $descriptor, $subject_id];
            }
        }

        return null;
    }

    /**
     * The relation, among those a versioned root registers, that is stored in this pivot's table with the root on the
     * given pivot column; null when the root registers none or its history is off.
     */
    private function relationRegisteredBy(Model $root, string $rootPivotKey): ?RelationDescriptor
    {
        if (! method_exists($root, 'versionedRelationThroughPivot') || ! method_exists($root, 'getVersionStrategy')) {
            return null;
        }

        if (! $root::getVersioning() || $root->getVersionStrategy() === false) {
            return null;
        }

        return $root->versionedRelationThroughPivot($this->getTable(), $rootPivotKey);
    }
}
