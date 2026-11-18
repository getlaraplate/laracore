<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

/**
 * The soft-deletable tree node with soft deletes switched off in code: `delete()` removes the row and fires no
 * `forceDeleting` event, only `deleting`.
 */
final class HardDeletingClosureTreeStubModel extends SoftDeletingClosureTreeStubModel
{
    protected bool $softDeletesEnabled = false;
}
