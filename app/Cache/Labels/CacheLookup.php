<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

/**
 * Outcome of an index lookup, handed back to store() or forget().
 */
final readonly class CacheLookup
{
    /**
     * @param  int  $started_at  Ordering token taken before the read. Its unit is implementation-defined and only compared within the index that issued it.
     */
    public function __construct(
        public string $key,
        public bool $hit,
        public mixed $value,
        public int $started_at,
        public CacheDependencies $dependencies,
    ) {}
}
