<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;

it('fixes the application and the database sessions to UTC', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('database.connections.mysql.timezone'))->toBe('+00:00')
        ->and(config('database.connections.mariadb.timezone'))->toBe('+00:00')
        ->and(config('database.connections.pgsql.timezone'))->toBe('UTC');
});

it('serializes instants in UTC with a Z suffix whatever the zone they were built in', function (): void {
    $user = User::factory()->create();
    $user->created_at = CarbonImmutable::parse('2025-03-30 04:45:37', 'Europe/Rome');

    expect($user->toArray()['created_at'])->toBe('2025-03-30T02:45:37.000000Z');
});
