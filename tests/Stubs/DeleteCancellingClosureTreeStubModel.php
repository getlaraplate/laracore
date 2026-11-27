<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Override;

/**
 * A tree node whose own `deleting` listener, registered after the hierarchy's, cancels every delete by returning
 * false, as a validation or lock listener can.
 */
final class DeleteCancellingClosureTreeStubModel extends ClosureTreeStubModel
{
    #[Override]
    protected static function booted(): void
    {
        self::deleting(static fn (): bool => false);
    }
}
