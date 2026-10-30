<?php

declare(strict_types=1);

use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;

it('defines core runtime settings with current defaults and choices', function (): void {
    $definitions = collect(CoreDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions->get('auth.registration.enabled')['value'])->toBeFalse()
        ->and($definitions->get('auth.registration.enabled')['type'])->toBe(SettingTypeEnum::Boolean)
        ->and($definitions->has('translations.provider'))->toBeFalse()
        ->and($definitions->has('translations.fallback_to_ai'))->toBeFalse()
        ->and($definitions->get('media.search_visibility')['value'])->toBe('owner')
        ->and($definitions->get('media.search_visibility')['choices'])->toBe(['owner', 'open']);
});

it('defines no search.vector setting, the vector profile belongs to the AI module', function (): void {
    $names = collect(CoreDatabaseSeeder::runtimeSettingDefinitions())->pluck('name');

    expect($names->filter(static fn (string $name): bool => str_starts_with($name, 'search.vector.')))->toBeEmpty();
});
