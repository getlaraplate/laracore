<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Approvals\Operation;
use Modules\Core\Enums\RelationCoverage;
use Modules\Core\Enums\RevertMode;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Locking\Exceptions\LockedModelException;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\RevertReport;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;
use Modules\Core\Versioning\Exceptions\MissingRevertSubjectException;
use Modules\Core\Versioning\Exceptions\RevertRefusedException;
use Modules\Core\Versioning\Exceptions\RevisionConflictException;

/**
 * The one implementation of a revert (D56): `HasVersions::revertToRevision()` and the shorthand `revertToVersion()`
 * both end here. It opens a `Revert` set for the root and, inside it, after the root is locked, decides whether the
 * revert may run: the lock of D39, the expected revision read under that lock, the mode's own checks. Only then it
 * writes, so a change committed between the caller's read and the revert is never overwritten (D48).
 */
final readonly class RevisionReverter
{
    public function __construct(
        private VersionSetManagerInterface $manager,
        private VersionEpochs $epochs,
    ) {}

    /**
     * @param  Model&HasVersions  $root
     *
     * @throws RevertRefusedException when the revert may not run at all
     * @throws RevisionConflictException when the record moved past `$expectedRevision`
     * @throws LockedModelException when somebody else holds the record
     */
    public function revert(Model $root, int $revision, int $expectedRevision, RevertMode $mode, bool $force): RevertReport
    {
        throw_if($root->getVersionStrategy() === false, RevertRefusedException::class, RevertRefusedException::NOT_VERSIONED);
        throw_unless($this->isRevisionOf($root, $revision), RevertRefusedException::class, RevertRefusedException::NOT_A_REVISION_OF_THIS_RECORD);

        if (method_exists($root, 'wouldRequireApproval') && $root->wouldRequireApproval(Operation::Revert)) {
            return $this->requestApproval($root, $revision, $expectedRevision, $mode, $force);
        }

        /** @var RevertReport $report */
        $report = $this->manager->run(
            VersionSetRoot::forModel($root),
            fn (): RevertReport => $this->revertUnderLock($root, $revision, $expectedRevision, $mode, $force),
            new VersionSetOptions(
                kind: VersionSetKind::Revert,
                actor: $root->getVersionUserId(),
                revertedFrom: $revision,
            ),
        );

        return $report;
    }

    /**
     * A revert the writer may not run alone becomes one request (D26, D62). The expected revision is checked now, so
     * a request is never made from a stale read; the approval checks it again, under the lock.
     *
     * @param  Model&HasVersions  $root
     */
    private function requestApproval(Model $root, int $revision, int $expectedRevision, RevertMode $mode, bool $force): RevertReport
    {
        $current = $root->currentRevision();

        throw_if($current !== $expectedRevision, new RevisionConflictException($expectedRevision, $current));

        $pending = $root->captureRevertRequest([
            'revision' => $revision,
            'expected_revision' => $expectedRevision,
            'mode' => $mode->value,
            'force' => $force,
        ]);

        if ($pending instanceof Modification) {
            return new RevertReport($revision, $mode, null, pendingRequest: $pending);
        }

        // The author's own approval completed the quorum: the revert ran as the request was applied.
        $after = $root->currentRevision();

        return new RevertReport($revision, $mode, $after !== $current ? $after : null);
    }

    /**
     * Whether the set is one of the record's: same root class, key and table. Read before the set opens, since what a
     * set belongs to never changes.
     *
     * @param  Model&HasVersions  $root
     */
    public function isRevisionOf(Model $root, int $revision): bool
    {
        return $this->setsOf($root)->whereKey($revision)->exists();
    }

    /**
     * The latest complete anchor of the record at or before the revision: its creation or a whole-aggregate baseline,
     * captured atomically (D45, D47), in the same schema epoch as the revision. A stream with no row after it is
     * absent at the revision; with no anchor at all, nothing says what the stream held, so a revert of the
     * translations or the links is refused (D45 rule 2).
     *
     * @param  Model&HasVersions  $root
     */
    public function completeAnchorAtOrBefore(Model $root, int $revision): ?int
    {
        return $this->anchorSet($root, $revision)?->getKey();
    }

    /**
     * Why a complete revert to the revision may not run, empty when it may (spec "Complete revert", D57): a relation
     * not covered by tests, an owned relation a revert cannot put back, no complete anchor, a set captured less than
     * atomically between the anchor and the revision, a revision written in another schema epoch than the current one.
     * A record with neither translations nor registered relations has only its columns, whose revert depends on
     * none of these.
     *
     * @param  Model&HasVersions  $root
     * @return list<string> the reasons, `RevertRefusedException` constants
     */
    public function completeRevertBlockers(Model $root, int $revision): array
    {
        if (! self::hasTranslations($root) && ! self::hasRegisteredRelations($root)) {
            return [];
        }

        $blockers = [];
        $descriptors = method_exists($root, 'versionedRelationDescriptors') ? $root->versionedRelationDescriptors() : [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->isOwned()) {
                $blockers[] = RevertRefusedException::UNSUPPORTED_RELATION;
            } elseif ($descriptor->coverage !== RelationCoverage::Complete) {
                $blockers[] = RevertRefusedException::INCOMPLETE_COVERAGE;
            }
        }

        $anchor = $this->anchorSet($root, $revision);

        if (! $anchor instanceof VersionSet) {
            $blockers[] = RevertRefusedException::NO_COMPLETE_ANCHOR;
        } elseif ($this->setsOf($root)->whereBetween('id', [(int) $anchor->getKey(), $revision])->where('capture', '!=', VersionCaptureQuality::Atomic)->exists()) {
            $blockers[] = RevertRefusedException::NOT_ATOMIC;
        }

        $revision_epoch = $root->versions()->reorder()->where('version_set_id', $revision)->max('epoch_id');

        if ($revision_epoch !== null && (int) $revision_epoch !== (int) $this->epochs->currentFor($root)->getKey()) {
            $blockers[] = RevertRefusedException::EPOCH_CHANGED;
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  Model&HasVersions  $root
     */
    private function revertUnderLock(Model $root, int $revision, int $expectedRevision, RevertMode $mode, bool $force): RevertReport
    {
        // Every check runs here, after the set has reread the root under lock (D48).
        throw_if(
            method_exists($root, 'blocksWritesByCurrentUser') && $root->blocksWritesByCurrentUser(),
            LockedModelException::class,
            'This model is locked',
        );

        $current = $root->currentRevision();

        throw_if($current !== $expectedRevision, new RevisionConflictException($expectedRevision, $current));

        if ($current === $revision) {
            return new RevertReport($revision, $mode, null);
        }

        // Everything that can refuse the revert is decided before the first write.
        $has_parts = self::hasTranslations($root);
        $has_links = self::hasRegisteredRelations($root);

        if ($mode === RevertMode::Complete && ($has_parts || $has_links)) {
            $blockers = $this->completeRevertBlockers($root, $revision);

            throw_if($blockers !== [], new RevertRefusedException($blockers[0] ?? RevertRefusedException::NO_COMPLETE_ANCHOR));
        }

        $anchor = null;

        if (($mode->includesLinks() && $has_links) || ($mode->includesOwnedParts() && $has_parts)) {
            $anchor = $this->anchorSet($root, $revision);

            throw_unless($anchor instanceof VersionSet, RevertRefusedException::class, RevertRefusedException::NO_COMPLETE_ANCHOR);
        }

        $columns = $mode->includesColumns() ? $this->columnsAsOf($root, $revision) : null;
        $translations = $mode->includesOwnedParts() && $has_parts && $anchor instanceof VersionSet
            ? $this->translationsFromAnchor($root, $revision, $anchor)
            : null;
        [$link_targets, $skipped_subjects] = $mode->includesLinks() && $has_links && $anchor instanceof VersionSet
            ? $this->linksAsOf($root, $revision, $anchor, $force)
            : [[], []];

        if ($columns !== null) {
            $this->applyColumns($root, $columns['state']);
        }

        if ($translations !== null) {
            $this->applyTranslations($root, $translations);
        }

        foreach ($link_targets as $relation => $target) {
            $root->replaceMembership($relation, $target);
        }

        $active = $this->manager->current();
        $written = $active?->hasWrittenVersions() === true ? $active->versionSet()->getKey() : null;

        return new RevertReport(
            targetRevision: $revision,
            mode: $mode,
            revision: is_numeric($written) ? (int) $written : null,
            restoredRelations: array_keys($link_targets),
            skippedColumns: $columns['skipped'] ?? [],
            skippedSubjects: $skipped_subjects,
        );
    }

    /**
     * The root's columns as of the revision (C08). A column the table no longer has is left out and named, so a revert
     * towards an older shape never ends in a raw query error.
     *
     * @param  Model&HasVersions  $root
     * @return array{state: array<string, mixed>, skipped: list<string>}
     */
    private function columnsAsOf(Model $root, int $revision): array
    {
        $row = $root->versions()
            ->whereNull('relation_path')
            ->where('version_set_id', '<=', $revision)
            ->orderByDesc('version_set_id')
            ->orderByDesc('sequence')
            ->first();

        throw_unless($row instanceof Version, RevertRefusedException::class, RevertRefusedException::NO_COMPLETE_ANCHOR);

        $state = $row->stateAsOf();

        // A revert never moves the record into or out of the bin (D61).
        if (method_exists($root, 'getDeletedAtColumn')) {
            unset($state[$root->getDeletedAtColumn()]);
        }

        $columns = $root->getConnection()->getSchemaBuilder()->getColumnListing($root->getTable());

        return [
            'state' => array_intersect_key($state, array_flip($columns)),
            'skipped' => array_values(array_diff(array_keys($state), $columns)),
        ];
    }

    /**
     * @param  Model&HasVersions  $root
     * @param  array<string, mixed>  $state
     */
    private function applyColumns(Model $root, array $state): void
    {
        $root->setRawAttributes(array_merge($root->getRawOriginal(), $state));

        if (in_array(HasApprovals::class, class_uses_recursive($root), true)) {
            // Whether the revert needs approval was decided before the set opened: its own writes ask nothing.
            $root->setForcedApprovalUpdate(true);
        }

        $root->save();
    }

    /**
     * The membership of every registered `reference` relation as of the revision, replayed from the anchor, and the
     * referenced records that no longer exist: refused, or with force skipped and named (D25).
     *
     * @param  Model&HasVersions  $root
     * @return array{array<string, array<int|string, array<string, mixed>>>, array<string, list<int|string>>}
     *
     * @throws MissingRevertSubjectException
     */
    private function linksAsOf(Model $root, int $revision, VersionSet $anchor, bool $force): array
    {
        if (! method_exists($root, 'versionedRelationDescriptors')) {
            return [[], []];
        }

        $targets = [];
        $skipped = [];

        foreach ($root->versionedRelationDescriptors() as $descriptor) {
            if ($descriptor->isOwned()) {
                continue;
            }

            $relation = $descriptor->relation;
            $members = [];

            foreach ($this->rowsFromAnchor($root, $relation, $revision, $anchor) as $row) {
                $id = $row->subject_key['id'] ?? null;

                if (! is_int($id) && ! is_string($id)) {
                    continue;
                }

                if ($row->change_type === VersionChangeType::Deleted) {
                    unset($members[$id]);
                } else {
                    $members[$id] = is_array($row->contents) ? $row->contents : [];
                }
            }

            $missing = array_values(array_filter(
                array_keys($members),
                static fn (int|string $id): bool => ! $root->referenceSubjectExists($relation, $id),
            ));

            throw_if($missing !== [] && ! $force, new MissingRevertSubjectException($relation, $missing));

            $targets[$relation] = array_diff_key($members, array_flip($missing));

            if ($missing !== []) {
                $skipped[$relation] = $missing;
            }
        }

        return [$targets, $skipped];
    }

    /**
     * The rows of one stream of the record from its anchor up to the revision, in revision order. In a baseline set
     * only the image counts: the set's own rows came before it.
     *
     * @param  Model&HasVersions  $root
     * @return Collection<int, Version>
     */
    private function rowsFromAnchor(Model $root, string $relationPath, int $revision, VersionSet $anchor): Collection
    {
        $anchor_id = (int) $anchor->getKey();
        $from_sequence = $anchor->baseline_from_sequence;

        return $root->versions()
            ->reorder()
            ->where('relation_path', $relationPath)
            ->where('version_set_id', '<=', $revision)
            ->where(static fn (Builder $query): Builder => $query
                ->where('version_set_id', '>', $anchor_id)
                ->orWhere(static fn (Builder $in_anchor): Builder => $in_anchor
                    ->where('version_set_id', $anchor_id)
                    ->when($from_sequence !== null, static fn (Builder $image): Builder => $image->where('sequence', '>=', $from_sequence))))
            ->orderBy('version_set_id')
            ->orderBy('sequence')
            ->get();
    }

    /**
     * The record's translations as of the revision, locale to fields, replayed from its latest complete anchor: a
     * locale whose last row up to the revision is `deleted` is absent.
     *
     * @param  Model&HasVersions  $root
     * @return array<string, array<string, mixed>>
     *
     * @throws RevertRefusedException when no complete anchor exists at or before the revision
     */
    public function translationsAsOf(Model $root, int $revision): array
    {
        $anchor = $this->anchorSet($root, $revision);

        throw_unless($anchor instanceof VersionSet, RevertRefusedException::class, RevertRefusedException::NO_COMPLETE_ANCHOR);

        return $this->translationsFromAnchor($root, $revision, $anchor);
    }

    /**
     * @param  Model&HasVersions  $root
     * @return array<string, array<string, mixed>>
     */
    private function translationsFromAnchor(Model $root, int $revision, VersionSet $anchor): array
    {
        $locales = [];

        foreach ($this->rowsFromAnchor($root, 'translations', $revision, $anchor) as $row) {
            $locale = $row->subject_key['locale'] ?? null;

            if (! is_string($locale)) {
                continue;
            }

            $contents = is_array($row->contents) ? $row->contents : [];

            $locales[$locale] = match (true) {
                $row->change_type === VersionChangeType::Deleted => null,
                // The first row of a locale and its row in a baseline are complete images (D45 rule 3).
                $row->change_type === VersionChangeType::Created, $row->getStoredVersionStrategy() === VersionStrategy::SNAPSHOT => $contents,
                default => array_merge($locales[$locale] ?? [], $contents),
            };
        }

        return array_filter($locales, static fn (?array $fields): bool => $fields !== null);
    }

    /**
     * Writes each locale of the target through the record, so the translation records its row on this instance
     * (D28), and deletes the locales the target does not hold.
     *
     * @param  Model&HasVersions  $root
     * @param  array<string, array<string, mixed>>  $target
     */
    private function applyTranslations(Model $root, array $target): void
    {
        foreach ($root->translations()->get() as $translation) {
            $locale = $translation->getAttribute('locale');

            if (is_string($locale) && array_key_exists($locale, $target)) {
                continue;
            }

            if (method_exists($translation, 'recordOnHistoryOf')) {
                $translation->recordOnHistoryOf($root);
            }

            $translation->delete();
        }

        foreach ($target as $locale => $fields) {
            $root->setTranslation($locale, $fields);
        }
    }

    /**
     * @param  Model&HasVersions  $root
     */
    private function anchorSet(Model $root, int $revision): ?VersionSet
    {
        $anchor = $this->setsOf($root)
            ->where('kind', VersionSetKind::Baseline)
            ->where('capture', VersionCaptureQuality::Atomic)
            ->where('id', '<=', $revision)
            ->orderByDesc('id')
            ->first();

        if (! $anchor instanceof VersionSet) {
            return null;
        }

        $epoch_of = static fn (int $set_id): mixed => $root->versions()->reorder()->where('version_set_id', $set_id)->max('epoch_id');
        $anchor_epoch = $epoch_of((int) $anchor->getKey());
        $revision_epoch = $epoch_of($revision);

        return $anchor_epoch !== null && $revision_epoch !== null && (int) $anchor_epoch !== (int) $revision_epoch ? null : $anchor;
    }

    /**
     * The version sets of the record: same root class, key and table.
     *
     * @param  Model&HasVersions  $root
     * @return Builder<VersionSet>
     */
    private function setsOf(Model $root): Builder
    {
        $root_ref = VersionSetRoot::forModel($root);
        $version_set = new VersionSet;
        $version_set->setConnection($root_ref->connectionName());

        return $version_set->newQuery()
            ->where('root_type', $root_ref->type())
            ->where('root_id', $root_ref->id())
            ->where('root_table_ref', $root_ref->tableName());
    }

    private static function hasTranslations(Model $root): bool
    {
        return method_exists($root, 'setTranslation') && method_exists($root, 'translations');
    }

    private static function hasRegisteredRelations(Model $root): bool
    {
        return method_exists($root, 'hasRegisteredRelations') && $root->hasRegisteredRelations();
    }
}
