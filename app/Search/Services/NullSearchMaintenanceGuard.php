<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Modules\Core\Search\Contracts\ISearchMaintenanceGuard;

/**
 * Default binding: nothing in Core competes with index maintenance, so it is never blocked.
 */
final readonly class NullSearchMaintenanceGuard implements ISearchMaintenanceGuard
{
    public function blockedReason(): ?string
    {
        return null;
    }
}
