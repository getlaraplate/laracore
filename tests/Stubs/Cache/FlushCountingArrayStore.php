<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Cache\ArrayStore;
use Override;

/**
 * Array store that counts how many times the whole store was flushed. `Cache::flush()` reaches the store directly,
 * without the repository's flush events, so a spy has to sit on the store.
 */
final class FlushCountingArrayStore extends ArrayStore
{
    public int $flushes = 0;

    #[Override]
    public function flush()
    {
        $this->flushes++;

        return parent::flush();
    }
}
