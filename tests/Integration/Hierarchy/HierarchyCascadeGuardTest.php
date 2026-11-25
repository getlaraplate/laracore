<?php

declare(strict_types=1);

use Modules\Core\Enums\CoreTables;
use Modules\Core\Support\Hierarchy\OwnerCascadeRegistry;
use Modules\Core\Tests\Support\CascadingForeignKeys;

/**
 * Every chain of cascading foreign keys into a Core hierarchy, reviewed and handled by a registered owner cascade.
 * A new chain, even between owner tables already registered, fails here until its cascade covers it.
 */
it('handles every chain of foreign keys that deletes a Core hierarchy row by cascade', function (string $hierarchy, array $handled): void {
    expect(CascadingForeignKeys::pathsInto($hierarchy))->toBe($handled)
        ->and(app(OwnerCascadeRegistry::class)->ownerTablesOf($hierarchy))->toBe(CascadingForeignKeys::ownerTablesOf($hierarchy));
})->with([
    'roles' => [CoreTables::Roles->value, []],
    'taxonomies' => [CoreTables::Taxonomies->value, [
        'core_entities -> core_presets.entity_id -> core_presettables.entity_id,preset_id -> core_taxonomies.presettable_id',
        'core_entities -> core_taxonomies.entity_id',
        'core_presets -> core_presettables.entity_id,preset_id -> core_taxonomies.presettable_id',
        'core_presettables -> core_taxonomies.presettable_id',
        'core_templates -> core_presets.template_id -> core_presettables.entity_id,preset_id -> core_taxonomies.presettable_id',
    ]],
]);
