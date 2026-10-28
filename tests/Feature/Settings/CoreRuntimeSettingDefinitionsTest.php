<?php

declare(strict_types=1);

use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;

it('defines the tunables that were env variables, with the defaults they had', function (string $name, int $value, string $group): void {
    $definition = collect(CoreDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name')->get($name);

    expect($definition)->not->toBeNull()
        ->and($definition['value'])->toBe($value)
        ->and($definition['type'])->toBe(SettingTypeEnum::Integer)
        ->and($definition['choices'])->toBeNull()
        ->and($definition['encrypted'])->toBeFalse()
        ->and($definition['managed'])->toBeFalse()
        ->and($definition['group_name'])->toBe($group)
        ->and($definition['description'])->toBeString()->not->toBeEmpty()
        ->and(config('core.' . $name))->toBe($value);
})->with([
    'expiring window' => ['validity.expiring_within_hours', 48, 'validity'],
    'tab counts TTL' => ['filament.tabs_counts_ttl_seconds', 60, 'filament'],
    'media draft TTL' => ['media.draft_ttl_hours', 24, 'media'],
]);
