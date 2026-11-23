<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

use Modules\Core\Cache\Labels\CacheDependencies;

/**
 * A label index that can tell the key an entry is stored under without reading the entry, so a value can be forgotten
 * without a lookup (which reads the value and counts a hit or a miss).
 */
interface IResolvesCacheLabelKeys
{
    /**
     * The key the entry of the key and dependencies is stored under, or null when it cannot be told without a lookup.
     */
    public function resolveKey(string $key, CacheDependencies $dependencies): ?string;
}
