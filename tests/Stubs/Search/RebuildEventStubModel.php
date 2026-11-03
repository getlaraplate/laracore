<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

/**
 * An event model of the `scout:rebuild` tests, with an index of its own.
 */
final class RebuildEventStubModel extends RebuildSearchStubModel
{
    public function searchableAs(): string
    {
        return 'core_test_rebuild_events';
    }
}
