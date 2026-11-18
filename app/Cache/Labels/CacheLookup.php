<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

/**
 * Outcome of an index lookup, handed back to store() or forget().
 */
final readonly class CacheLookup
{
    /**
     * @param  int  $started_at  Microseconds since the epoch, taken before the read.
     */
    public function __construct(
        public string $key,
        public bool $hit,
        public mixed $value,
        public int $started_at,
        public CacheDependencies $dependencies,
    ) {}
}
