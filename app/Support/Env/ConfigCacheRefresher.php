<?php

declare(strict_types=1);

namespace Modules\Core\Support\Env;

use Illuminate\Support\Facades\Artisan;
use Modules\Core\Contracts\RefreshesConfigCache;
use Override;
use RuntimeException;

/**
 * Runs `config:clear` and then `config:cache`, so a changed `.env` takes effect on a deployment that caches its configuration.
 * A command that does not exit with 0 is a failure: the cache must not be reported rebuilt when it is not.
 */
final readonly class ConfigCacheRefresher implements RefreshesConfigCache
{
    #[Override]
    public function refresh(): void
    {
        foreach (['config:clear', 'config:cache'] as $command) {
            $exit = Artisan::call($command);

            if ($exit !== 0) {
                throw new RuntimeException("{$command} failed (exit {$exit})");
            }
        }
    }
}
