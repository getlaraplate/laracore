<?php

declare(strict_types=1);

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Facades\FilamentTimezone;
use Modules\Core\Models\User;
use Modules\Core\Support\DisplayTimezone;

it('reads date-times in the timezone saved on the signed in user', function (): void {
    $this->actingAs(User::factory()->create(['timezone' => 'America/New_York']));

    expect(DisplayTimezone::current())->toBe('America/New_York')
        ->and(FilamentTimezone::get())->toBe('America/New_York')
        ->and(DateTimePicker::make('published_at')->getTimezone())->toBe('America/New_York');
});

it('follows the user of the current request, not the one of the previous request', function (): void {
    $this->actingAs(User::factory()->create(['timezone' => 'Europe/Rome']));
    expect(FilamentTimezone::get())->toBe('Europe/Rome');

    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Tokyo']));
    expect(FilamentTimezone::get())->toBe('Asia/Tokyo');
});

it('falls back to UTC for a guest and for a user without a timezone', function (): void {
    expect(FilamentTimezone::get())->toBe(DisplayTimezone::FALLBACK);

    $this->actingAs(User::factory()->create(['timezone' => null]));

    expect(FilamentTimezone::get())->toBe('UTC');
});

it('falls back to UTC when the saved value is not an IANA identifier', function (string $timezone): void {
    $this->actingAs(User::factory()->create(['timezone' => $timezone]));

    expect(FilamentTimezone::get())->toBe('UTC');
})->with([
    'offset' => '+02:00',
    'abbreviation' => 'CET',
    'unknown name' => 'Mars/Olympus',
    'empty' => '',
]);

it('keeps date-only fields out of the user timezone', function (): void {
    $this->actingAs(User::factory()->create(['timezone' => 'Pacific/Auckland']));

    expect(DatePicker::make('birthday')->getTimezone())->toBe('UTC');
});

it('accepts IANA identifiers only', function (?string $timezone, bool $expected): void {
    expect(DisplayTimezone::isValid($timezone))->toBe($expected);
})->with([
    'rome' => ['Europe/Rome', true],
    'new york' => ['America/New_York', true],
    'utc' => ['UTC', true],
    'offset' => ['+02:00', false],
    'abbreviation' => ['CET', false],
    'null' => [null, false],
    'empty' => ['', false],
]);
