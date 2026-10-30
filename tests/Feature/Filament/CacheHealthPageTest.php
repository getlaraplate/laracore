<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Filament\Pages\CacheHealth;
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

it('renders the search engine health widget', function (): void {
    cacheHealthPageActor();

    Livewire::test(CacheHealth::class)
        ->assertOk()
        ->assertSeeLivewire(SearchEngineHealthTableWidget::class);
});

it('keeps the search engine health widget off the dashboard', function (): void {
    cacheHealthPageActor();

    expect(Filament::getWidgets())->not->toContain(SearchEngineHealthTableWidget::class);

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertDontSeeLivewire(SearchEngineHealthTableWidget::class);
});
