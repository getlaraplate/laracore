<?php

declare(strict_types=1);

use MatanYadaev\EloquentSpatial\Objects\Point;
use Modules\Core\Models\Place;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;
use Modules\Core\Tests\Stubs\Place\PlaceAffinityModel;

/**
 * @return list<string>
 */
function core_db2_run_migration(string $file): array
{
    return Db2PretendConnection::capture(static function () use ($file): void {
        $migration = require base_path('Modules/Core/database/migrations/' . $file);
        $migration->up();
    });
}

/**
 * @param  list<string>  $statements
 */
function core_db2_assert_portable(array $statements): void
{
    $joined = implode("\n", $statements);

    foreach (['~', 'GENERATED ALWAYS AS (', ':NEW', ':OLD', 'DECLARE', '`', 'generated always as ('] as $foreign) {
        expect($joined)->not->toContain($foreign);
    }
}

/**
 * @param  list<string>  $statements
 * @return list<string>
 */
function core_db2_normalised(array $statements, string $needle): array
{
    return array_values(array_map(
        static fn (string $sql): string => preg_replace('/\s+/', ' ', mb_trim($sql)),
        array_filter($statements, static fn (string $sql): bool => str_contains($sql, $needle)),
    ));
}

it('gives the permissions table plain name columns, one trigger, two indexes and a check on Db2', function (): void {
    $sql = core_db2_run_migration('2024_03_15_224941_create_permission_tables.php');
    $joined = implode("\n", $sql);

    core_db2_assert_portable($sql);

    expect($joined)->toContain('ADD COLUMN connection_name VARCHAR(50)')
        ->and($joined)->toContain('ADD COLUMN table_name VARCHAR(50)')
        ->and($joined)->toContain('ADD COLUMN module_name VARCHAR(50)');

    $triggers = core_db2_normalised($sql, 'permissions_name_parts_trigger');

    expect($triggers)->toHaveCount(1)
        ->and($triggers[0])->toContain('NO CASCADE BEFORE INSERT OR UPDATE ON')
        ->and($triggers[0])->toContain('REFERENCING NEW AS N FOR EACH ROW BEGIN ATOMIC')
        ->and($triggers[0])->toContain("IF NOT REGEXP_LIKE(N.name, '^\\w+\\.\\w+\\.\\w+$') THEN SIGNAL SQLSTATE '75001' SET MESSAGE_TEXT = 'Invalid permission name'; END IF;")
        ->and($triggers[0])->toContain("SET N.connection_name = REGEXP_SUBSTR(N.name, '[^.]+', 1, 1);")
        ->and($triggers[0])->toContain("SET N.table_name = REGEXP_SUBSTR(N.name, '[^.]+', 1, 2);")
        ->and($triggers[0])->toContain("SET N.module_name = REGEXP_SUBSTR(REGEXP_SUBSTR(N.name, '[^.]+', 1, 2), '^[A-Za-z0-9]*');")
        ->and($triggers[0])->not->toContain('\\1')
        ->and($triggers[0])->not->toContain('REGEXP_REPLACE')
        ->and(implode("\n", $sql))->not->toContain('CHECK (REGEXP_LIKE')
        ->and(mb_strpos($triggers[0], 'IF NOT REGEXP_LIKE'))->toBeLessThan(mb_strpos($triggers[0], 'SET N.connection_name'))
        ->and($triggers[0])->toEndWith('END');

    $positions = [];

    foreach (['ADD COLUMN connection_name', 'ADD COLUMN module_name', 'permissions_name_parts_trigger', '_ref_IDX', '_module_name_IDX'] as $needle) {
        $found = array_keys(array_filter($sql, static fn (string $statement): bool => str_contains($statement, $needle)));
        expect($found)->not->toBeEmpty();
        $positions[$needle] = $found[0];
    }

    expect($positions['ADD COLUMN connection_name'])->toBeLessThan($positions['ADD COLUMN module_name'])
        ->and($positions['ADD COLUMN module_name'])->toBeLessThan($positions['permissions_name_parts_trigger'])
        ->and($positions['permissions_name_parts_trigger'])->toBeLessThan($positions['_ref_IDX'])
        ->and($positions['_ref_IDX'])->toBeLessThan($positions['_module_name_IDX']);
});

it('derives the permission columns like PostgreSQL on Db2', function (): void {
    $migration = require base_path('Modules/Core/database/migrations/2024_03_15_224941_create_permission_tables.php');

    // The same pattern constants as the trigger: REGEXP_SUBSTR(name, part, 1, n) is the n-th match of the part pattern.
    $part = static function (string $name, int $occurrence) use ($migration): string {
        preg_match_all('~' . $migration::DB2_PART_PATTERN . '~', $name, $matches);

        return $matches[0][$occurrence - 1] ?? '';
    };
    $module = static function (string $name) use ($migration, $part): string {
        preg_match('~' . $migration::DB2_MODULE_PATTERN . '~', $part($name, $migration::DB2_TABLE_OCCURRENCE), $found);

        return $found[0] ?? '';
    };

    expect($part('default.core_users.select', $migration::DB2_CONNECTION_OCCURRENCE))->toBe('default')
        ->and($part('default.core_users.select', $migration::DB2_TABLE_OCCURRENCE))->toBe('core_users')
        ->and($module('default.core_users.select'))->toBe('core')
        ->and($module('default.sao_tickets.update'))->toBe('sao')
        ->and($module('default.settings.update'))->toBe('settings')
        ->and(preg_match('~' . $migration::DB2_NAME_PATTERN . '~', 'default.core_users.select'))->toBe(1)
        ->and(preg_match('~' . $migration::DB2_NAME_PATTERN . '~', 'core_users.select'))->toBe(0);
});

it('creates the presettables triggers in Db2 SQL PL', function (): void {
    $sql = core_db2_run_migration('2024_11_28_224400_create_presettables_table.php');

    core_db2_assert_portable($sql);

    $triggers = core_db2_normalised($sql, 'CREATE OR REPLACE TRIGGER trg_');

    expect(implode("\n", $sql))->not->toContain('SET NOT NULL')
        ->and(implode("\n", $sql))->toMatch('/fields_snapshot[^,]*not null/i')
        ->and($triggers)->toHaveCount(4)
        ->and($triggers[0])->toContain('trg_presettables_version')
        ->and($triggers[0])->toContain('NO CASCADE BEFORE INSERT ON')
        ->and($triggers[0])->toContain('REFERENCING NEW AS N FOR EACH ROW SET N."VERSION" = COALESCE( (SELECT MAX("VERSION") FROM')
        ->and($triggers[0])->toContain('WHERE preset_id = N.preset_id AND entity_id = N.entity_id), 0 ) + 1')
        ->and($triggers[1])->toContain('trg_presets_insert')
        ->and($triggers[1])->toContain('AFTER INSERT ON')
        ->and($triggers[1])->toContain('VALUES (N.entity_id, N."ID", \'[]\', N.deleted_at);')
        ->and($triggers[2])->toContain('trg_presets_update')
        ->and($triggers[2])->toContain('REFERENCING NEW AS N OLD AS O')
        ->and($triggers[2])->toContain('SET deleted_at = N.deleted_at WHERE preset_id = N."ID" AND deleted_at IS NULL; END IF; END')
        ->and($triggers[3])->toContain('trg_presets_delete')
        ->and($triggers[3])->toContain('AFTER DELETE ON')
        ->and($triggers[3])->toContain('DELETE FROM core_presettables WHERE preset_id = O."ID";');
});

it('drops the presettables triggers on Db2', function (): void {
    $sql = Db2PretendConnection::capture(static function (): void {
        $migration = require base_path('Modules/Core/database/migrations/2024_11_28_224400_create_presettables_table.php');
        $migration->down();
    });

    expect($sql)->toContain('DROP TRIGGER trg_presettables_version')
        ->and($sql)->toContain('DROP TRIGGER trg_presets_insert')
        ->and($sql)->toContain('DROP TRIGGER trg_presets_update')
        ->and($sql)->toContain('DROP TRIGGER trg_presets_delete');
});

it('forbids a taxonomy to be its own parent with a check constraint on Db2', function (): void {
    $sql = core_db2_run_migration('2024_11_28_225853_create_taxonomies_table.php');
    $joined = implode("\n", $sql);

    core_db2_assert_portable($sql);

    $check = core_db2_normalised($sql, 'parent_id_check');

    expect($check)->toHaveCount(1)
        ->and($check[0])->toContain('CHECK (parent_id <> "ID")')
        ->and($joined)->not->toContain('SIGNAL SQLSTATE');
});

it('creates the places table without a geolocation column on Db2', function (): void {
    $sql = core_db2_run_migration('2024_11_30_220000_create_places_table.php');
    $joined = implode("\n", $sql);

    core_db2_assert_portable($sql);

    expect($joined)->toContain('latitude')
        ->and($joined)->toContain('longitude')
        ->and(mb_strtolower($joined))->not->toContain('geolocation')
        ->and(mb_strtolower($joined))->not->toContain('postgis')
        ->and(mb_strtolower($joined))->not->toContain('sdo_');
});

it('never writes geolocation on Db2', function (): void {
    $sql = Db2PretendConnection::capture(static function (): void {
        $host = new PlaceAffinityModel();
        $host->setAttribute('latitude', 41.9);
        $host->setAttribute('longitude', 12.5);
        $host->setAttribute('geolocation', new Point(41.9, 12.5));

        expect($host->persistsSpatialGeometry())->toBeFalse()
            ->and($host->placeRow())->not->toHaveKey('geolocation')
            ->and($host->placeRow())->toMatchArray(['latitude' => 41.9, 'longitude' => 12.5]);

        $place = new Place(['city' => 'Milano']);
        $place->exists = true;
        $place->setAttribute('id', 7);
        $place->syncOriginal();

        $linked = new PlaceAffinityModel();
        $linked->setAttribute('place_id', 7);
        $linked->setRelation('place', $place);
        Place::withoutEvents(static fn (): mixed => $linked->setAttribute('geolocation', new Point(45.4, 9.1)));

        expect($place->latitude)->toBe(45.4)
            ->and($place->longitude)->toBe(9.1);

        $place->setAttribute('latitude', 45.5);
        Place::withoutEvents(static fn (): bool => $place->save());
    });

    expect($sql)->not->toBeEmpty()
        ->and(mb_strtolower(implode("\n", $sql)))->not->toContain('geolocation');
});
