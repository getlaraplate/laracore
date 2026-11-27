<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Modules\Core\Cache\HasCache;

/**
 * An article model of the `scout:rebuild` tests that is opted into the response cache.
 */
final class RebuildCachedArticleStubModel extends RebuildSearchStubModel
{
    use HasCache;

    public function searchableAs(): string
    {
        return 'core_test_rebuild_cached_articles';
    }
}
