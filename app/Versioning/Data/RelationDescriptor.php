<?php

declare(strict_types=1);

namespace Modules\Core\Versioning\Data;

use Modules\Core\Enums\RelationCoverage;
use Modules\Core\Enums\RelationOwnership;

/**
 * A relation a root registers as part of its history: its name, whether the root owns it, and whether its paths are
 * covered by tests (D57, `partial` until its module declares otherwise).
 */
final readonly class RelationDescriptor
{
    public function __construct(
        public string $relation,
        public RelationOwnership $ownership,
        public RelationCoverage $coverage = RelationCoverage::Partial,
    ) {}

    public function isOwned(): bool
    {
        return $this->ownership === RelationOwnership::Owned;
    }
}
