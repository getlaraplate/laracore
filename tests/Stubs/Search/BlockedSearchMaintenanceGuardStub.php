<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Modules\Core\Search\Contracts\ISearchMaintenanceGuard;

/**
 * A maintenance guard that always blocks, with the reason the test gives it.
 */
final readonly class BlockedSearchMaintenanceGuardStub implements ISearchMaintenanceGuard
{
    public function __construct(private string $reason) {}

    public function blockedReason(): string
    {
        return $this->reason;
    }
}
