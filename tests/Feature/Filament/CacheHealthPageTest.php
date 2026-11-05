<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Modules\Core\Filament\Pages\CacheHealth;
use Modules\Core\Filament\Widgets\CacheStoreHealthWidget;
use Modules\Core\Filament\Widgets\SearchEngineHealthTableWidget;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function cacheHealthPageActor(): void
{
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    test()->actingAs($actor);
    Filament::setCurrentPanel('admin');
}

it('renders the cache store and search engine health widgets', function (): void {
    cacheHealthPageActor();

    Livewire::test(CacheHealth::class)
        ->assertOk()
        ->assertSeeLivewire(CacheStoreHealthWidget::class)
        ->assertSeeLivewire(SearchEngineHealthTableWidget::class);
});

it('renders the cache store table', function (): void {
    cacheHealthPageActor();
    config(['cache.default' => 'array']);

    Livewire::test(CacheStoreHealthWidget::class)
        ->assertOk()
        ->assertSee(__('app.widgets.store'))
        ->assertSee(__('app.widgets.reachable'));
});

it('shows only the grouped redis connections for a single redis store', function (): void {
    cacheHealthPageActor();
    config([
        'cache.default' => 'redis',
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'default'],
        'database.redis' => [
            'client' => config('database.redis.client'),
            'options' => [],
            'default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => '0', 'timeout' => 0.1],
            'horizon' => ['host' => '127.0.0.1', 'port' => 1, 'database' => '0', 'timeout' => 0.1],
        ],
    ]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance();

    Livewire::test(CacheStoreHealthWidget::class)
        ->assertOk()
        ->assertSee('default, horizon')
        ->assertSee(__('app.widgets.keys'))
        ->assertDontSee(__('app.widgets.driver'));
});

it('keeps the search engine health widget off the dashboard', function (): void {
    cacheHealthPageActor();

    expect(Filament::getWidgets())->not->toContain(SearchEngineHealthTableWidget::class)
        ->not->toContain(CacheStoreHealthWidget::class);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertDontSeeLivewire(SearchEngineHealthTableWidget::class);
});
