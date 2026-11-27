<?php

declare(strict_types=1);

use Modules\Core\Filament\Plugins\BrowserTimezonePlugin;
use Modules\Core\Models\User;

it('renders the script for a user without a timezone', function (): void {
    $this->actingAs(User::factory()->create(['timezone' => null]));

    $html = BrowserTimezonePlugin::make()->render();

    expect($html)->toContain('Intl.DateTimeFormat().resolvedOptions().timeZone')
        ->and($html)->toContain(route('core.auth.updatePreferences'));
});

it('renders nothing for a user who already has a timezone', function (): void {
    $this->actingAs(User::factory()->create(['timezone' => 'Europe/Rome']));

    expect(BrowserTimezonePlugin::make()->render())->toBe('');
});

it('renders nothing for a guest', function (): void {
    expect(BrowserTimezonePlugin::make()->render())->toBe('');
});
