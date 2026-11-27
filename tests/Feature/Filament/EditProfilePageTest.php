<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Core\Filament\Pages\EditProfile;
use Modules\Core\Models\User;

it('saves the language and the timezone from the profile page', function (): void {
    config(['app.available_locales' => ['en', 'it']]);
    $user = User::factory()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel('admin');

    Livewire::test(EditProfile::class)
        ->fillForm(['lang' => 'it', 'timezone' => 'America/New_York'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->lang)->toBe('it')
        ->and($user->timezone)->toBe('America/New_York');
});

it('refuses a timezone that is not in the list', function (): void {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');

    Livewire::test(EditProfile::class)
        ->fillForm(['timezone' => '+02:00'])
        ->call('save')
        ->assertHasFormErrors(['timezone']);
});
