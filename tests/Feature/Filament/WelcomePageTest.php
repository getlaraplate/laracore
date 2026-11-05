<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Filament\Pages\Welcome;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function welcomePageActor(): void
{
    $actor = User::factory()->create();
    $actor->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    test()->actingAs($actor);
    Filament::setCurrentPanel('admin');
}

it('shows the application description and no big logo', function (): void {
    welcomePageActor();

    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    Livewire::test(Welcome::class)
        ->assertOk()
        ->assertSee((string) $composer['description'])
        ->assertDontSeeHtml('w-90 h-90');
});

it('puts the brand icon beside each module name', function (): void {
    welcomePageActor();

    Livewire::test(Welcome::class)
        ->assertSeeHtml(mb_rtrim((string) config('app.brand_icons'), '/') . '/core.svg')
        ->assertSeeHtml(mb_rtrim((string) config('app.brand_icons'), '/') . '/laraplate.svg');
});

it('lists the application first, then the modules by name', function (): void {
    welcomePageActor();

    $names = array_keys(new Welcome()->getGroupedModules());
    $modules = array_slice($names, 1);
    $sorted = $modules;
    usort($sorted, static fn (string $first, string $second): int => mb_strtolower($first) <=> mb_strtolower($second));

    expect($names[0])->toBe('App')
        ->and($modules)->toBe($sorted);
});

it('lists only the installation state, not settings or database drivers', function (): void {
    welcomePageActor();
    app()->setLocale('en');

    Livewire::test(Welcome::class)
        ->assertSee(__('app.welcome.environment'))
        ->assertSee(__('app.welcome.debugMode'))
        ->assertSee(__('app.welcome.maintenanceMode'))
        ->assertSee(__('app.welcome.migrations'))
        ->assertSee(__('app.welcome.apiVersions'))
        ->assertDontSee('Database drivers:')
        ->assertDontSee('Active modules:')
        ->assertDontSee('Languages:')
        ->assertDontSee('Enable user registration:')
        ->assertDontSee('Verify new user:')
        ->assertDontSee('Enable dynamic entities:');
});

it('reports the public api as disabled when no versioned api route exists', function (): void {
    welcomePageActor();

    $expected = api_versions() === [] ? __('app.welcome.apiDisabled') : implode(', ', api_versions());

    Livewire::test(Welcome::class)->assertSee($expected);
});
