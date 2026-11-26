<?php

declare(strict_types=1);

use App\Casts\EntityType;
use App\Models\Pivot\Presettable as AppPresettable;
use App\Models\Preset as AppPreset;
use Modules\Core\Models\Pivot\Presettable;
use Modules\Core\Models\Preset;
use Modules\Core\Services\DynamicContentsService;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;
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

/**
 * The same-module mapping serves modules that declare their own dynamic entity types (CMS, for one); Core declares none,
 * so no public path reaches it from Core's tests and the private resolver is called directly.
 *
 * @return class-string
 */
function dynamic_contents_invoke_get_module_model_class(string $local_class, string $target_class): string
{
    $ref = new ReflectionClass(DynamicContentsService::class);
    $method = $ref->getMethod('getModuleModelClass');
    $method->setAccessible(true);

    return $method->invoke(DynamicContentsService::getInstance(), $local_class, $target_class);
}

it('maps module target model to the local module namespace', function (): void {
    $resolved = dynamic_contents_invoke_get_module_model_class(Preset::class, Preset::class);

    expect($resolved)->toBe(Preset::class);
});

it('returns the target class unchanged when local and target share the same module', function (): void {
    $resolved = dynamic_contents_invoke_get_module_model_class(Presettable::class, Presettable::class);

    expect($resolved)->toBe(Presettable::class);
});

it('scores the default presets and entities by comparing their flags, as Db2 has no boolean predicate', function (): void {
    $sql = Db2PretendConnection::capture(static function (): void {
        DynamicContentsService::getInstance()->fetchAvailablePresettables(EntityType::Pages);
    });

    expect(implode("\n", $sql))
        ->toContain('CASE WHEN core_presets.is_default = ? THEN 1 ELSE 0 END + CASE WHEN core_entities.is_default = ? THEN 1 ELSE 0 END as order_score')
        ->not->toContain('is_default THEN');
});
