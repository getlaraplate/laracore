<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Filament\Resources\Settings\Pages\EditSetting;
use Modules\Core\Jobs\PurgeTrashedRowsJob;
use Modules\Core\Jobs\ReleaseLocksJob;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// Switching soft deletes or record locking off on the settings page asks first: cancelling saves nothing and queues
// nothing.

function softDeletesSwitchPanelActor(): void
{
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    /** @var App\Models\User $actor */
    $actor = App\Models\User::query()->create(User::factory()->raw());
    $actor->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    test()->actingAs($actor);
    Filament::setCurrentPanel('admin');
}

function softDeletesSwitchPanelSetting(string $name = 'soft_deletes.enabled.soft_deletes_panel_stub'): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => $name,
        'type' => 'boolean',
        'value' => true,
        'choices' => [true, false],
        'group_name' => explode('.', $name)[0],
    ]);
}

it('saves nothing and queues no purge when the switch-off is cancelled', function (): void {
    Bus::fake();
    softDeletesSwitchPanelActor();
    $setting = softDeletesSwitchPanelSetting();

    Livewire::test(EditSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['value' => false])
        ->call('save')
        ->assertActionMounted('confirmSettingChange')
        ->assertMountedActionModalSee('Switch soft deletes off?')
        ->unmountAction();

    expect((bool) $setting->fresh()->value)->toBeTrue();
    Bus::assertNotDispatched(PurgeTrashedRowsJob::class);
});

it('saves and queues the purge when the switch-off is confirmed', function (): void {
    Bus::fake();
    softDeletesSwitchPanelActor();
    $setting = softDeletesSwitchPanelSetting();

    Livewire::test(EditSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['value' => false])
        ->call('save')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect((bool) $setting->fresh()->value)->toBeFalse();
    Bus::assertDispatched(PurgeTrashedRowsJob::class, static fn (PurgeTrashedRowsJob $job): bool => $job->table === 'soft_deletes_panel_stub');
});

it('saves nothing and queues no release when switching record locking off is cancelled', function (): void {
    Bus::fake();
    softDeletesSwitchPanelActor();
    $setting = softDeletesSwitchPanelSetting('locking.enabled.locking_panel_stub');

    Livewire::test(EditSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['value' => false])
        ->call('save')
        ->assertActionMounted('confirmSettingChange')
        ->assertMountedActionModalSee('Switch record locking off?')
        ->unmountAction();

    expect((bool) $setting->fresh()->value)->toBeTrue();
    Bus::assertNotDispatched(ReleaseLocksJob::class);
});
