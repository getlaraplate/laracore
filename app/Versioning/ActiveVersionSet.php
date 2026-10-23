<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use LogicException;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\VersionSet;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;
use Modules\Core\Versioning\Exceptions\PendingVersionSequenceException;
use Modules\Core\Versioning\Exceptions\VersionSequenceMismatchException;

/**
 * The version set of one root inside a scope of the version-set manager: its options, its row, written lazily on
 * the first version, and the sequence of its rows. A scope holds one of these per root it touches (D43).
 */
final class ActiveVersionSet
{
    private ?VersionSet $version_set = null;

    /**
     * The kind the set row is stored with when it differs from the scope's options (D53, D60).
     */
    private ?VersionSetKind $stored_kind = null;

    private int $sequence = 0;

    private bool $sequence_allocated = false;

    private bool $version_written = false;

    private bool $root_row_written = false;

    private bool $translation_row_written = false;

    public function __construct(
        private readonly VersionSetRoot $root,
        private readonly string $connection_name,
        private readonly VersionSetOptions $options,
        private readonly VersionCaptureQuality $capture = VersionCaptureQuality::Atomic,
    ) {}

    public function capture(): VersionCaptureQuality
    {
        return $this->capture;
    }

    public function root(): VersionSetRoot
    {
        return $this->root;
    }

    public function options(): VersionSetOptions
    {
        return $this->options;
    }

    public function connectionName(): string
    {
        return $this->connection_name;
    }

    public function versionSet(): VersionSet
    {
        if ($this->version_set instanceof VersionSet) {
            return $this->version_set;
        }

        $version_set = new VersionSet;
        $version_set->setConnection($this->connection_name);
        $version_set->forceFill([
            'uuid' => (string) str()->uuid(),
            'root_type' => $this->root->type(),
            'root_id' => $this->root->id(),
            'root_connection_ref' => $this->root->connectionName(),
            'root_table_ref' => $this->root->tableName(),
            config('versionable.user_foreign_key', 'user_id') => $this->options->actorId(),
            'kind' => $this->stored_kind ?? $this->options->kind,
            'capture' => $this->capture,
            'reason' => $this->options->reason,
            'reverted_from_set_id' => $this->options->revertedFromId(),
        ])->saveOrFail();
        $this->version_set = $version_set;

        return $version_set;
    }

    /**
     * Stores the set as a baseline: its first row is the creation of its root, a complete anchor (D53). The
     * scope's options keep their kind, so a nested run() with ordinary options still joins it (D60). Too late
     * once the set row exists, and never for a revert.
     */
    public function storeAsBaseline(): void
    {
        if (! $this->version_set instanceof VersionSet && $this->options->kind === VersionSetKind::Change) {
            $this->stored_kind = VersionSetKind::Baseline;
        }
    }

    public function nextSequence(): int
    {
        $this->versionSet();

        if ($this->sequence_allocated) {
            throw new LogicException('The allocated version sequence must be recorded before requesting another.');
        }

        $this->sequence_allocated = true;

        return $this->sequence + 1;
    }

    /**
     * Notes which stream a written row belongs to: the root's own columns, or a translation of it.
     */
    public function noteStream(?string $relationPath): void
    {
        if ($relationPath === null) {
            $this->root_row_written = true;
        } elseif ($relationPath === 'translations') {
            $this->translation_row_written = true;
        }
    }

    /**
     * Whether the revision changed the root's translations but not its own row: the form's lock version covers the
     * translations, so it must rise once all the same (D23). Membership changes do not count.
     */
    public function changedOnlyTranslations(): bool
    {
        return $this->translation_row_written && ! $this->root_row_written;
    }

    public function markVersionWritten(): void
    {
        if (! $this->version_set instanceof VersionSet || ! $this->sequence_allocated) {
            throw new LogicException('A version sequence must be allocated before recording a write.');
        }

        $this->sequence++;
        $this->sequence_allocated = false;
        $this->version_written = true;
    }

    /**
     * Checks the rows against the confirmed sequences, removes a set that received no row and stamps the root of a
     * set whose root was saved during the scope.
     */
    public function finish(): void
    {
        if ($this->sequence_allocated) {
            throw new PendingVersionSequenceException;
        }

        if (! $this->version_set instanceof VersionSet) {
            return;
        }

        $persisted_sequences = $this->version_set->versions()
            ->reorder('sequence')
            ->pluck('sequence')
            ->map(static fn (mixed $sequence): int => (int) $sequence)
            ->values()
            ->all();
        $confirmed_sequences = $this->sequence === 0 ? [] : range(1, $this->sequence);

        if ($persisted_sequences !== $confirmed_sequences) {
            throw new VersionSequenceMismatchException($confirmed_sequences, $persisted_sequences);
        }

        if (! $this->version_written) {
            $this->version_set->deleteOrFail();
            $this->version_set = null;

            return;
        }

        $root_id = $this->root->id();

        if ($root_id === null || $root_id === '') {
            throw new LogicException('A non-empty version set requires a persisted aggregate root.');
        }

        $this->version_set->forceFill([
            'root_type' => $this->root->type(),
            'root_id' => $root_id,
            'root_connection_ref' => $this->root->connectionName(),
            'root_table_ref' => $this->root->tableName(),
        ])->saveOrFail();
    }
}
