<?php

declare(strict_types=1);

use App\Casts\EntityType;
use App\Models\Pivot\Presettable as AppPresettable;
use App\Models\Preset as AppPreset;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Tests\Support\AppDynamicEntities;

beforeEach(function (): void {
    DynamicContentsService::reset();
});

/*
 * The models of a dynamic entity type are resolved in the module of the type: an App type reads the App models, never
 * Core's abstract ones. Core declares no type of its own, so the App type is the only mapping reachable.
 */

it('fetches the presets of an App type as App presets', function (): void {
    $preset = AppDynamicEntities::preset(EntityType::Pages);

    $presets = DynamicContentsService::getInstance()->fetchAvailablePresets(EntityType::Pages);

    expect($presets->pluck('id')->all())->toContain($preset->id)
        ->and($presets->first())->toBeInstanceOf(AppPreset::class);
});

it('fetches the presettables of an App type as App presettables', function (): void {
    $preset = AppDynamicEntities::preset(EntityType::Pages);
    (new AppPresettable())->getConnection()->table(Modules\Core\Enums\CoreTables::Presettables->value)->insertOrIgnore([
        'preset_id' => $preset->id,
        'entity_id' => $preset->entity_id,
        'version' => 1,
        'fields_snapshot' => '[]',
    ]);

    $presettables = DynamicContentsService::getInstance()->fetchAvailablePresettables(EntityType::Pages);

    expect($presettables)->not->toBeEmpty()
        ->and($presettables->first())->toBeInstanceOf(AppPresettable::class);
});
