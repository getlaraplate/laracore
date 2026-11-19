<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Inspector\ModelMetadataRegistry;
use Modules\Core\Inspector\SchemaInspector;
use Modules\Core\Versioning\VersionEpochs;
use Override;

final class CommandListenerProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    #[Override]
    public function register(): void
    {
        Context::add([
            'scope' => $this->app->runningInConsole() ? 'console' : 'web',
            'locale' => $this->app->getLocale(),
            'user' => Auth::user()?->id,
            'command' => $this->getCommand(),
        ]);
    }

    /**
     * Get the services provided by the provider.
     */
    #[Override]
    public function provides(): array
    {
        return [];
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Clear the inspector label and the in-memory SchemaInspector cache after migrations
        Event::listen(MigrationsEnded::class, static function (): void {
            info('Cleaning Inspected entities and helpers cache');
            resolve(ICacheLabelIndex::class)->flush(CacheLabel::inspector());

            SchemaInspector::getInstance()->clearAll();
            ModelMetadataRegistry::getInstance()->clearAll();
            HelpersCache::clearAll();
            app(VersionEpochs::class)->flush();
        });
    }

    private function getCommand(): ?string
    {
        return $this->app->runningInConsole() && defined('ARTISAN_BINARY')
            ? ($this->app->runningUnitTests()
                ? 'unit-test'
                : (Request::server('argv')[1] ?? null))
            : null;
    }
}
