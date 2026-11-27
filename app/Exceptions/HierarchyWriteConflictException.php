<?php

declare(strict_types=1);

namespace Modules\Core\Exceptions;

use Illuminate\Database\DeadlockException;

/**
 * Thrown when a closure write cannot trust what its transaction reads: another writer of the same hierarchy
 * committed after the transaction's snapshot was taken (MySQL and MariaDB under REPEATABLE READ keep that snapshot
 * for the whole transaction). Raised by
 * {@see \Modules\Core\Support\Hierarchy\ClosureTableMaintainer::lock()} before any closure row changes.
 *
 * It carries SQLSTATE 40001 (serialization failure), so Laravel treats it as a concurrency error: thrown inside a
 * nested transaction it reaches the caller as {@see DeadlockException} with this exception as the previous one, and
 * the outermost `DB::transaction($callback, $attempts)` runs the whole callback again, which then reads a fresh
 * snapshot.
 */
final class HierarchyWriteConflictException extends DeadlockException
{
    public static function staleSnapshot(string $closureTable): self
    {
        return new self(sprintf(
            'Another transaction wrote the hierarchy of "%s" after this transaction read it; retry the transaction.',
            $closureTable,
        ), 40001);
    }
}
