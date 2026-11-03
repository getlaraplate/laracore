<?php

declare(strict_types=1);

namespace Modules\Core\Search\Contracts;

/**
 * Says whether search index maintenance (dropping, recreating or refilling an index) may run now.
 * Core binds a guard that never blocks; a module whose own work rebuilds indexes, such as an
 * embedding model switch, replaces the binding so the two never run at the same time.
 */
interface ISearchMaintenanceGuard
{
    /**
     * Why index maintenance must not start now, in words an operator can act on, or null when it may.
     */
    public function blockedReason(): ?string;
}
