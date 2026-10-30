<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;

it('timestamps adds created_at and updated_at when missing', function (): void {
    Schema::create('migrate_utils_ts', function (Blueprint $table): void {
        $table->id();
        MigrateUtils::timestamps($table, hasCreateUpdate: true, hasSoftDelete: false, hasLocks: false, hasValidity: false);
    });

    expect(Schema::hasColumn('migrate_utils_ts', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('migrate_utils_ts', 'updated_at'))->toBeTrue();
});

it('timestamps accepts an explicit created-at index name', function (): void {
    Schema::create('migrate_utils_named_ts', function (Blueprint $table): void {
        $table->id();
        MigrateUtils::timestamps(
            $table,
            hasCreateUpdate: true,
            createdAtIndexName: 'mu_created_IDX',
        );
    });

    expect(Schema::hasIndex('migrate_utils_named_ts', 'mu_created_idx'))->toBeTrue();
});

it('dropTimestamps removes created_at and updated_at', function (): void {
    Schema::create('migrate_utils_drop', function (Blueprint $table): void {
        $table->id();
        MigrateUtils::timestamps($table, true, false, false, false);
    });

    Schema::table('migrate_utils_drop', function (Blueprint $table): void {
        MigrateUtils::dropTimestamps($table, true, false, false, false);
    });

    expect(Schema::hasColumn('migrate_utils_drop', 'created_at'))->toBeFalse()
        ->and(Schema::hasColumn('migrate_utils_drop', 'updated_at'))->toBeFalse();
});

it('uses the explicit schema connection without changing the default connection', function (): void {
    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('affinity');

    $table_name = 'migrate_utils_affinity';
    $affinity_schema = Schema::connection('affinity');
    $affinity_connection = $affinity_schema->getConnection();

    Schema::create($table_name, static function (Blueprint $table): void {
        $table->id();
    });

    $affinity_schema->create($table_name, static function (Blueprint $table) use ($affinity_connection): void {
        $table->id();
        MigrateUtils::timestamps($table, true, false, false, false, true, null, $affinity_connection);
    });

    $affinity_schema->table($table_name, static function (Blueprint $table) use ($affinity_connection): void {
        MigrateUtils::dropTimestamps($table, true, false, false, false, $affinity_connection);
    });

    expect($affinity_schema->hasColumn($table_name, 'created_at'))->toBeFalse()
        ->and($affinity_schema->hasColumn($table_name, 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn($table_name, 'created_at'))->toBeFalse()
        ->and(Schema::hasColumn($table_name, 'updated_at'))->toBeFalse();
});

it('runs migration raw statements on the migrator connection', function (): void {
    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'aff_',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('affinity');

    $permissions_table = config('permission.table_names.permissions');
    $default_connection = config('database.default');
    $default_had_permissions = DB::connection($default_connection)->getSchemaBuilder()->hasTable($permissions_table);

    DB::connection($default_connection)->getSchemaBuilder()->create('migration_affinity_sentinel', static function (Blueprint $table): void {
        $table->id();
    });
    DB::connection($default_connection)->table('migration_affinity_sentinel')->insert(['id' => 1]);

    $migration = require module_path('Core', 'database/migrations/2024_03_15_224941_create_permission_tables.php');

    app('migrator')->usingConnection('affinity', static function () use ($migration, $permissions_table): void {
        expect(app('db')->connection()->getName())->toBe('affinity');
        $migration->up();
        expect(app('db')->connection()->getSchemaBuilder()->hasTable($permissions_table))->toBeTrue();

        app('db')->connection()->table($permissions_table)->insert([
            'name' => 'affinity.widgets.select',
            'guard_name' => 'web',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $trigger_count = (int) app('db')->connection()->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
            ['aff_' . $permissions_table],
        );

        expect(app('db')->connection()->table($permissions_table)->value('connection_name'))->toBe('affinity')
            ->and(app('db')->connection()->table($permissions_table)->value('table_name'))->toBe('widgets')
            ->and(app('db')->connection()->table($permissions_table)->value('module_name'))->toBe('widgets')
            ->and($trigger_count)->toBe(6);

        $migration->down();

        $trigger_count = (int) app('db')->connection()->scalar(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?",
            ['aff_' . $permissions_table],
        );

        expect(app('db')->connection()->getSchemaBuilder()->hasTable($permissions_table))->toBeFalse()
            ->and($trigger_count)->toBe(0);
    });

    expect(DB::connection('affinity')->getSchemaBuilder()->hasTable($permissions_table))->toBeFalse()
        ->and(DB::connection($default_connection)->getSchemaBuilder()->hasTable($permissions_table))->toBe($default_had_permissions)
        ->and(DB::connection($default_connection)->table('migration_affinity_sentinel')->count())->toBe(1);
});

it('creates portable prefix indexes and safely degrades specialized indexes on sqlite', function (): void {
    Schema::create('migrate_utils_search', function (Blueprint $table): void {
        $table->id();
        $table->string('slug');
        $table->text('body');
        MigrateUtils::prefixIndex($table, 'slug');
    });

    MigrateUtils::fuzzyIndex('migrate_utils_search', 'slug');
    MigrateUtils::fullTextIndex('migrate_utils_search', 'body');

    expect(Schema::hasIndex('migrate_utils_search', 'migrate_utils_search_slug_prefix_idx'))->toBeTrue();
});

it('rejects unsafe search index identifiers and unsupported oracle sync modes', function (): void {
    expect(fn () => MigrateUtils::fuzzyIndex('unsafe-table', 'name'))
        ->toThrow(InvalidArgumentException::class)
        ->and(function (): void {
            Schema::create('migrate_utils_unsafe_ts', function (Blueprint $table): void {
                MigrateUtils::timestamps($table, createdAtIndexName: 'unsafe index');
            });
        })
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => MigrateUtils::fullTextIndex('safe_table', 'body', oracleSync: 'hourly'))
        ->toThrow(InvalidArgumentException::class);
});

function createLiveUniqueTable(string $table_name): void
{
    Schema::create($table_name, function (Blueprint $table): void {
        $table->id();
        $table->string('locale');
        $table->string('slug');
        $table->softDeletes();
    });

    MigrateUtils::liveUniqueIndex($table_name, ['locale', 'slug'], $table_name . '_locale_slug_UN');
}

it('liveUniqueIndex refuses a second live row with the same key', function (): void {
    createLiveUniqueTable('mu_live');

    DB::table('mu_live')->insert(['locale' => 'it', 'slug' => 'jazz']);

    expect(fn () => DB::table('mu_live')->insert(['locale' => 'it', 'slug' => 'jazz']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('liveUniqueIndex lets deleted rows share the key with each other and with one live row', function (): void {
    createLiveUniqueTable('mu_live_deleted');

    DB::table('mu_live_deleted')->insert([
        ['locale' => 'it', 'slug' => 'jazz', 'deleted_at' => now()],
        ['locale' => 'it', 'slug' => 'jazz', 'deleted_at' => now()],
        ['locale' => 'it', 'slug' => 'jazz', 'deleted_at' => null],
        ['locale' => 'en', 'slug' => 'jazz', 'deleted_at' => null],
    ]);

    expect(DB::table('mu_live_deleted')->count())->toBe(4);
});

it('liveUniqueIndex rejects unsafe identifiers', function (): void {
    expect(fn () => MigrateUtils::liveUniqueIndex('mu_live_unsafe', ['slug; drop'], 'mu_live_unsafe_UN'))
        ->toThrow(InvalidArgumentException::class);
});
