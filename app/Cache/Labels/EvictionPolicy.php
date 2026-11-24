<?php

declare(strict_types=1);

namespace Modules\Core\Cache\Labels;

use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IReportsCacheEvictionPolicy;

/**
 * The label index needs a Redis that evicts nothing: under an `allkeys-*` policy a label set (kept without expiry) can
 * be evicted, and a later flush misses the entries it listed; under a `volatile-*` policy a flush record can be evicted
 * before the stores it must refuse, and a value computed before the flush is stored afterwards.
 */
final class EvictionPolicy
{
    public const string REQUIRED = 'noeviction';

    /**
     * The warning to give when the store of the index may evict its keys; null when it evicts nothing or the index
     * cannot tell.
     */
    public static function warningFor(ICacheLabelIndex $index): ?string
    {
        $policy = $index instanceof IReportsCacheEvictionPolicy ? $index->evictionPolicy() : null;

        if ($policy === null || $policy === self::REQUIRED) {
            return null;
        }

        return sprintf(
            'The Redis of the cache label index runs with maxmemory-policy [%s]: an evicted label set makes a flush miss '
            . 'entries, an evicted flush record lets a stale value be stored. Set maxmemory-policy to %s.',
            $policy,
            self::REQUIRED,
        );
    }
}
