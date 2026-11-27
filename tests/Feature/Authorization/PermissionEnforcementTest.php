<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Authorization\PermissionEnforcement;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Services\PerModelSettingResolver;

/**
 * A permission whose capability is switched off in settings keeps its row, so the
 * person configuring a role has to be told that granting it changes nothing today.
 */
function writeCapabilitySetting(string $prefix, string $table, bool $enabled): void
{
    DB::table(CoreTables::Settings->value)->updateOrInsert(
        ['name' => PerModelSettingResolver::nameFor($prefix, $table)],
        [
            'value' => json_encode($enabled),
            'encrypted' => false,
            'type' => SettingTypeEnum::Boolean->value,
            'group_name' => $prefix === CoreDatabaseSeeder::LOCK_NAME_PREFIX ? 'locking' : 'soft_deletes',
            'description' => "Capability status for {$table}",
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    app(PerModelSettingResolver::class)->flush();
    app(PerModelSettingResolver::class)->flushGroup('locking');
    app(PerModelSettingResolver::class)->flushGroup('soft_deletes');
    app(PerModelSettingResolver::class)->flushNameIndex();
    app()->forgetInstance(PerModelSettingResolver::class);
}

it('says nothing about a permission that is enforced as written', function (): void {
    $enforcement = app(PermissionEnforcement::class);

    expect($enforcement->noteFor('default.core_users.lock', 'core_users'))->toBeNull()
        ->and($enforcement->hasNote('default.core_users.select', 'core_users'))->toBeFalse();
});

it('explains both lock verbs when locking is switched off', function (): void {
    writeCapabilitySetting(CoreDatabaseSeeder::LOCK_NAME_PREFIX, 'core_widgets', false);

    $enforcement = app(PermissionEnforcement::class);

    expect($enforcement->hasNote('default.core_widgets.lock', 'core_widgets'))->toBeTrue()
        ->and($enforcement->hasNote('default.core_widgets.unlock', 'core_widgets'))->toBeTrue()
        ->and($enforcement->noteFor('default.core_widgets.lock', 'core_widgets')?->reason)->toContain('core_widgets')
        ->and($enforcement->noteFor('default.core_widgets.lock', 'core_widgets')?->label)->toBe(__('app.permissions.note.inert'));
});

/**
 * The two verbs of the soft-delete pair earn different warnings. `restore` has nothing
 * left to act on. `delete` still works and turns destructive, because the inactivate
 * operation it gates goes through a model that now removes the row instead of hiding
 * it. `forceDelete` is unaffected: it destroyed the row all along.
 */
it('separates the verb that goes quiet from the one that changes meaning', function (): void {
    writeCapabilitySetting(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, 'core_widgets', false);

    $enforcement = app(PermissionEnforcement::class);

    expect($enforcement->noteFor('default.core_widgets.restore', 'core_widgets')?->label)->toBe(__('app.permissions.note.inert'))
        ->and($enforcement->noteFor('default.core_widgets.delete', 'core_widgets')?->label)->toBe(__('app.permissions.note.changed'))
        ->and($enforcement->noteFor('default.core_widgets.delete', 'core_widgets')?->reason)->toContain('core_widgets')
        ->and($enforcement->hasNote('default.core_widgets.forceDelete', 'core_widgets'))->toBeFalse();
});

it('answers for a permission with no table behind it', function (): void {
    expect(app(PermissionEnforcement::class)->hasNote('*', null))->toBeFalse();
});

it('leaves another table alone when one table is switched off', function (): void {
    writeCapabilitySetting(CoreDatabaseSeeder::LOCK_NAME_PREFIX, 'core_widgets', false);

    expect(app(PermissionEnforcement::class)->hasNote('default.core_gadgets.lock', 'core_gadgets'))->toBeFalse();
});

it('treats a table whose model no longer declares the capability as off, whatever its setting says', function (): void {
    Modules\Core\Helpers\HelpersCache::setModels('active', [...models(), Modules\Core\Tests\Stubs\Console\MassWriteStubModel::class]);
    writeCapabilitySetting(CoreDatabaseSeeder::LOCK_NAME_PREFIX, 'mass_write_stub', true);
    writeCapabilitySetting(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, 'mass_write_stub', true);

    try {
        $enforcement = app(PermissionEnforcement::class);

        expect($enforcement->noteFor('default.mass_write_stub.restore', 'mass_write_stub')?->label)->toBe(__('app.permissions.note.inert'))
            ->and($enforcement->noteFor('default.mass_write_stub.delete', 'mass_write_stub')?->label)->toBe(__('app.permissions.note.changed'))
            ->and($enforcement->noteFor('default.mass_write_stub.lock', 'mass_write_stub')?->label)->toBe(__('app.permissions.note.inert'));
    } finally {
        Modules\Core\Helpers\HelpersCache::clearModels();
    }
});

it('follows the setting for a table whose model declares the capability', function (): void {
    $users = (new Modules\Core\Models\User())->getTable();
    $enforcement = app(PermissionEnforcement::class);
    $before = $enforcement->hasNote('default.' . $users . '.restore', $users);

    writeCapabilitySetting(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $users, false);

    expect($before)->toBeFalse()
        ->and(app(PermissionEnforcement::class)->noteFor('default.' . $users . '.restore', $users)?->label)->toBe(__('app.permissions.note.inert'));
});
