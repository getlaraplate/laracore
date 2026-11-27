<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Support\DateFormatter;

it('writes the wire form in UTC whatever the zone of the instant', function (): void {
    $date = CarbonImmutable::parse('2025-03-30 04:45:37', 'Europe/Rome');

    expect(DateFormatter::wire($date))->toBe('2025-03-30T02:45:37.000000Z')
        ->and(DateFormatter::wire(null))->toBeNull();
});

it('shows an instant in the timezone it is asked for', function (): void {
    $date = CarbonImmutable::parse('2025-03-30T02:45:37Z');

    expect(DateFormatter::display($date, 'Europe/Rome', 'en', 'YYYY-MM-DD HH:mm'))->toBe('2025-03-30 04:45')
        ->and(DateFormatter::display($date, 'America/New_York', 'en', 'YYYY-MM-DD HH:mm'))->toBe('2025-03-29 22:45')
        ->and(DateFormatter::display(null))->toBeNull();
});

it('shows an instant in the timezone of the signed in user by default', function (): void {
    $this->actingAs(User::factory()->create(['timezone' => 'Asia/Tokyo']));

    expect(DateFormatter::display(CarbonImmutable::parse('2025-03-30T02:45:37Z'), null, 'en', 'YYYY-MM-DD HH:mm'))
        ->toBe('2025-03-30 11:45');
});

it('uses UTC for a guest and for a timezone that is not an IANA identifier', function (): void {
    $date = CarbonImmutable::parse('2025-03-30T02:45:37Z');

    expect(DateFormatter::display($date, null, 'en', 'YYYY-MM-DD HH:mm'))->toBe('2025-03-30 02:45')
        ->and(DateFormatter::display($date, '+02:00', 'en', 'YYYY-MM-DD HH:mm'))->toBe('2025-03-30 02:45');
});

it('does not move the instant it formats', function (): void {
    $date = CarbonImmutable::parse('2025-03-30T02:45:37Z');

    DateFormatter::display($date, 'Asia/Tokyo');

    expect($date->toIso8601String())->toBe('2025-03-30T02:45:37+00:00');
});

it('names the timezone for a reader whose timezone is not known', function (): void {
    $date = CarbonImmutable::parse('2025-03-30T02:45:37Z');

    expect(DateFormatter::displayWithZone($date, 'Europe/Rome', 'en', 'YYYY-MM-DD HH:mm'))->toBe('2025-03-30 04:45 CEST');
});

it('renders a time element with the wire form and escaped text', function (): void {
    $html = DateFormatter::html(CarbonImmutable::parse('2025-03-30T02:45:37Z'), 'Europe/Rome', 'en', 'YYYY-MM-DD HH:mm');

    expect($html)->toBe('<time datetime="2025-03-30T02:45:37.000000Z">2025-03-30 04:45 CEST</time>')
        ->and(DateFormatter::html(null))->toBe('');
});
