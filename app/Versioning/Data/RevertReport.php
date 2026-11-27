<?php

declare(strict_types=1);

namespace Modules\Core\Versioning\Data;

use Modules\Core\Enums\RevertMode;
use Modules\Core\Models\Modification;

/**
 * What a revert did: the `Revert` set it wrote, or none when nothing changed or the revert became an approval request,
 * and what it had to leave out.
 */
final readonly class RevertReport
{
    /**
     * @param  list<string>  $restoredRelations  the registered relations whose membership was put back
     * @param  list<string>  $skippedColumns  the columns of the revision the table no longer has
     * @param  array<string, list<int|string>>  $skippedSubjects  per relation, the referenced records that no longer exist, skipped with force
     */
    public function __construct(
        public int $targetRevision,
        public RevertMode $mode,
        public ?int $revision,
        public array $restoredRelations = [],
        public array $skippedColumns = [],
        public array $skippedSubjects = [],
        public ?Modification $pendingRequest = null,
    ) {}

    /**
     * Something of the revision could not be put back: a column the table lost, a reference that no longer exists.
     */
    public function isPartial(): bool
    {
        return $this->skippedColumns !== [] || $this->skippedSubjects !== [];
    }

    /**
     * The revert needs approval and waits as a request: nothing was reverted yet.
     */
    public function isPending(): bool
    {
        return $this->pendingRequest instanceof Modification;
    }
}
