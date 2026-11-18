<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Modules\Core\Contracts\RefreshesConfigCache;
use Override;
use RuntimeException;

/**
 * Counts the refreshes it is asked for and touches nothing: a test must never run the real `config:cache`.
 */
final class RecordingConfigCacheRefresher implements RefreshesConfigCache
{
    public int $calls = 0;

    /**
     * Makes the next refresh fail, as `config:cache` does when a configuration file cannot be loaded.
     */
    public bool $fails = false;

    #[Override]
    public function refresh(): void
    {
        $this->calls++;

        if ($this->fails) {
            throw new RuntimeException('config:cache failed (exit 1)');
        }
    }
}
