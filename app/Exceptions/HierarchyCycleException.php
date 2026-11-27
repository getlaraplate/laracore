<?php

declare(strict_types=1);

namespace Modules\Core\Exceptions;

use LogicException;

/**
 * Thrown when a hierarchy node would be moved under itself or under one of its own descendants, which would
 * turn the tree into a cycle. Raised by {@see \Modules\Core\Support\Hierarchy\ClosureTableMaintainer::moveSubtree()}
 * before any closure row changes, and by {@see \Modules\Core\Models\Concerns\HasClosureTable} before a new parent
 * is saved.
 */
final class HierarchyCycleException extends LogicException
{
    public static function forMove(string $table, int|string $id, int|string $newParentId): self
    {
        return new self(sprintf(
            'Cannot move node [%s] of "%s" under [%s]: the new parent is the node itself or one of its descendants.',
            $id,
            $table,
            $newParentId,
        ));
    }
}
