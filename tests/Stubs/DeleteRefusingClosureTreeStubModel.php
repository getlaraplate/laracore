<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Override;
use RuntimeException;

/**
 * A tree node whose own `deleting` listener, registered after the hierarchy's, refuses every delete with an
 * exception, as the lock subscriber and the validation rules do.
 */
final class DeleteRefusingClosureTreeStubModel extends ClosureTreeStubModel
{
    #[Override]
    protected static function booted(): void
    {
        self::deleting(static function (): never {
            throw new RuntimeException('Delete refused.');
        });
    }
}
