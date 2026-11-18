<?php

declare(strict_types=1);

use Modules\Core\Enums\CoreTables;
use Modules\Core\Support\Hierarchy\OwnerCascadeRegistry;
use Modules\Core\Tests\Support\CascadingForeignKeys;

it('handles every foreign key that deletes a Core hierarchy row by cascade', function (string $hierarchy, array $handled): void {
    expect(CascadingForeignKeys::directOf($hierarchy))->toBe($handled);
})->with([
    'roles' => [CoreTables::Roles->value, []],
    'taxonomies' => [CoreTables::Taxonomies->value, ['core_taxonomies.entity_id', 'core_taxonomies.presettable_id']],
]);

it('registers an owner cascade for every table whose delete reaches a Core hierarchy', function (string $hierarchy): void {
    $owners = CascadingForeignKeys::ownerTablesOf($hierarchy);
    $registered = app(OwnerCascadeRegistry::class)->ownerTablesOf($hierarchy);

    expect($registered)->toBe($owners);
})->with([
    'roles' => [CoreTables::Roles->value],
    'taxonomies' => [CoreTables::Taxonomies->value],
]);
