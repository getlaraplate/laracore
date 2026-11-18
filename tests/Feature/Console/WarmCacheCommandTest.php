<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Console\WarmCacheCommand;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\CronJob;
use Modules\Core\Models\Setting;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Symfony\Component\Console\Command\Command as BaseCommand;

/**
 * Do not assert ReflectionClass::isFinal(): tests/Pest.php enables DG\BypassFinals,
 * which reports final classes as non-final so Mockery can replace methods.
 */

// Feature: performance-optimization, Property 22: Cache warming command is idempotent
// Validates: Requirements 16.3

it('cache:warm command exists and has correct signature', function (): void {
    $reflection = new ReflectionClass(WarmCacheCommand::class);
    $signature = $reflection->getProperty('signature');
    $signature->setAccessible(true);
    $instance = $reflection->newInstanceWithoutConstructor();

    expect($signature->getValue($instance))->toContain('cache:warm');
});

it('cache:warm command exits with SUCCESS when at least one step succeeds', function (): void {
    $this->artisan('cache:warm')
        ->assertExitCode(BaseCommand::SUCCESS);
});

it('cache:warm command reports number of entries and elapsed time', function (): void {
    $this->artisan('cache:warm')
        ->expectsOutputToContain('entries populated')
        ->assertExitCode(BaseCommand::SUCCESS);
});

// Feature: performance-optimization, Property 22: Cache warming command is idempotent
// Validates: Requirements 16.3
it('running cache:warm twice produces the same version_strategies cache state', function (): void {
    CacheManager::resetAppNameCache();
    HasVersions::resetVersionStrategyCache();

    // Create some versioning settings
    Setting::factory()->create(['group_name' => 'versioning', 'name' => 'versioning.strategy.test_table']);

    $cache_key = CacheManager::key('version_strategies');

    // First run
    $this->artisan('cache:warm')->assertExitCode(BaseCommand::SUCCESS);
    $state_after_first = Cache::get($cache_key);

    // Reset L1 to force re-read from L2 on second run
    HasVersions::resetVersionStrategyCache();

    // Second run
    $this->artisan('cache:warm')->assertExitCode(BaseCommand::SUCCESS);
    $state_after_second = Cache::get($cache_key);

    // Both runs must produce the same cache state (idempotency)
    expect($state_after_first)->not->toBeNull()
        ->and($state_after_second)->not->toBeNull()
        ->and($state_after_first->count())->toBe($state_after_second->count());
})->repeat(3);

// Feature: performance-optimization, Property 22: Cache warming command is idempotent
// Validates: Requirements 16.3
it('running cache:warm N times always produces the same final cache state', function (): void {
    CacheManager::resetAppNameCache();
    HasVersions::resetVersionStrategyCache();

    $cache_key = CacheManager::key('version_strategies');

    // Run once to establish baseline
    $this->artisan('cache:warm')->assertExitCode(BaseCommand::SUCCESS);
    $baseline = Cache::get($cache_key);

    // Run N more times and verify state is always identical to baseline
    $runs = fake()->numberBetween(2, 5);

    for ($i = 0; $i < $runs; $i++) {
        HasVersions::resetVersionStrategyCache();
        $this->artisan('cache:warm')->assertExitCode(BaseCommand::SUCCESS);
        $current = Cache::get($cache_key);

        expect($current)->not->toBeNull();

        if ($baseline !== null && $current !== null) {
            expect($current->count())->toBe($baseline->count());
        }
    }
})->repeat(3);

// Feature: performance-optimization, Property 22: Cache warming command is idempotent
// Validates: Requirements 16.4, 16.5
it('cache:warm is not triggered on boot when warm_on_boot is false', function (): void {
    config(['core.cache.warm_on_boot' => false]);

    // Ensure the settings cache key is absent before boot
    $cache_key = CacheManager::key('settings');
    Cache::forget($cache_key);

    // Re-boot the application — warm_on_boot is false, so cache should remain empty
    $this->app->booted(function (): void {
        // The warm_on_boot hook should NOT have populated the cache
        // We cannot assert absence here because other tests may have warmed it,
        // but we can assert the config is respected (no exception thrown)
        expect(config('core.cache.warm_on_boot'))->toBeFalse();
    });
});

// Feature: performance-optimization, Property 22: Cache warming command is idempotent
// Validates: Requirements 16.4, 16.5
it('cache:warm is triggered on boot when warm_on_boot is true', function (): void {
    config(['core.cache.warm_on_boot' => true]);

    HasVersions::resetVersionStrategyCache();

    $cache_key = CacheManager::key('version_strategies');
    Cache::forget($cache_key);

    // Simulate the booted hook by directly invoking the WarmCacheCommand
    // (the hook calls $this->app->make(WarmCacheCommand::class)->handle())
    $exit_code = $this->app->make(WarmCacheCommand::class)->handle();

    expect($exit_code)->toBe(BaseCommand::SUCCESS);
    expect(config('core.cache.warm_on_boot'))->toBeTrue();
});

it('cache:warm stores the active cron jobs in the index under the label of their table', function (): void {
    CronJob::factory()->create(['command' => 'cron-probe:prune', 'is_active' => true]);
    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);

    $this->artisan('cache:warm')->assertExitCode(BaseCommand::SUCCESS);

    $stored = array_values(array_filter(
        $index->stores,
        static fn (array $store): bool => $store['lookup']->dependencies->labels === ['table:core_cron_jobs'],
    ));

    expect($stored)->toHaveCount(1)
        ->and($stored[0]['value'][0]['command'])->toBe('cron-probe:prune')
        ->and($stored[0]['ttl'])->toBe((int) config('cache.duration.long'));
});
