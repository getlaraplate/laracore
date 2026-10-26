<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Whether the mutation and revert paths of a versioned relation are covered by tests (D57). A root offers a complete
 * revert only when every relation it registers is complete.
 */
enum RelationCoverage: string
{
    case Complete = 'complete';
    case Partial = 'partial';
}
