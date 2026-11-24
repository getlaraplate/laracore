<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

/**
 * A label index whose store may evict keys under memory pressure, and can tell the policy it evicts them with.
 */
interface IReportsCacheEvictionPolicy
{
    /**
     * The eviction policy of the store (Redis `maxmemory-policy`), or null when it cannot be read.
     */
    public function evictionPolicy(): ?string;
}
