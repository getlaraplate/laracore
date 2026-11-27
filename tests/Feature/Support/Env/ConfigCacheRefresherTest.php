<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\Core\Contracts\RefreshesConfigCache;
use Modules\Core\Support\Env\ConfigCacheRefresher;

it('clears the configuration cache and then caches it again', function (): void {
    Artisan::shouldReceive('call')->once()->ordered()->with('config:clear')->andReturn(0);
    Artisan::shouldReceive('call')->once()->ordered()->with('config:cache')->andReturn(0);

    new ConfigCacheRefresher()->refresh();
});

it('is what the container hands out for the contract', function (): void {
    expect(resolve(RefreshesConfigCache::class))->toBeInstanceOf(ConfigCacheRefresher::class)
        ->and(file_exists(base_path('bootstrap/cache/config.php')))->toBeFalse();
});

it('fails when one of the two commands does not succeed, instead of reporting a rebuilt cache', function (string $failing): void {
    Artisan::shouldReceive('call')->with('config:clear')->andReturn($failing === 'config:clear' ? 1 : 0);
    Artisan::shouldReceive('call')->with('config:cache')->andReturn($failing === 'config:cache' ? 1 : 0);

    expect(fn () => new ConfigCacheRefresher()->refresh())->toThrow(RuntimeException::class, $failing);
})->with(['config:clear', 'config:cache']);
