<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Support\Db2Drivers;

return new class() extends Migration
{
    /**
     * Db2 name parts, `connection.table.action`. Each pattern captures one part in group 1 or 2 of the whole
     * name, so a single REGEXP_REPLACE gives the PostgreSQL value; the module is the table's prefix before the
     * first underscore.
     */
    public const string DB2_NAME_PATTERN = '^\\w+\\.\\w+\\.\\w+$';

    public const string DB2_CONNECTION_PATTERN = '^(\\w+)\\.(\\w+)\\.(\\w+)$';

    public const string DB2_TABLE_PATTERN = '^(\\w+)\\.(\\w+)\\.(\\w+)$';

    public const string DB2_MODULE_PATTERN = '^\\w+\\.([A-Za-z0-9]*)\\w*\\.\\w+$';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = app('db')->connection();
        $schema = $connection->getSchemaBuilder();
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        $permissions_table = $tableNames['permissions'];
        $roles_table = $tableNames['roles'];
        $model_has_permissions_table = $tableNames['model_has_permissions'];
        $model_has_roles_table = $tableNames['model_has_roles'];
        $role_has_permissions_table = $tableNames['role_has_permissions'];

        if ($tableNames === '' || $tableNames === null) {
            throw new RuntimeException('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        if ($teams && ($columnNames['team_foreign_key'] ?? null) === null) {
            throw new RuntimeException('Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        $schema->create($permissions_table, function (Blueprint $table) use ($connection, $permissions_table): void {
            $table->bigIncrements('id'); // permission id
            $table->string('name', 125)->nullable(false)->comment('The name of the permission');       // For MySQL 8.0 use string('name', 125);
            $table->string('guard_name', 125)->default('web')->nullable(false)->comment('The guard name of the permission'); // For MySQL 8.0 use string('guard_name', 125);
            $table->string('description')->nullable(true)->comment('The description of the permission');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
                connection: $connection,
            );

            $table->unique(['name', 'guard_name'], "{$permissions_table}_UN");
        });

        $grammar = $connection->getQueryGrammar();
        $wrapped_permissions_table = $grammar->wrapTable($permissions_table);
        $wrapped_reference_index = $grammar->wrap("{$permissions_table}_ref_IDX");
        $wrapped_module_index = $grammar->wrap("{$permissions_table}_module_name_IDX");
        $wrapped_name_check = $grammar->wrap("{$permissions_table}_name_CHECK");

        $driver_family = Db2Drivers::family($connection->getDriverName());

        if ($driver_family === 'pgsql') {
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN connection_name VARCHAR(50) GENERATED ALWAYS AS (regexp_replace(regexp_replace(name, '\\.\\w+\\.\\w+$', ''), '\\.', '')) STORED");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN table_name VARCHAR(50) GENERATED ALWAYS AS (regexp_replace(regexp_replace(name, '^\\w+\\.', ''), '\\.\\w+$', '')) STORED");
            $connection->statement("CREATE INDEX {$wrapped_reference_index} ON {$wrapped_permissions_table} (connection_name, table_name)");
            // Module = the table's prefix (chars before the first underscore), e.g. `sao_tickets` -> `sao`.
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN module_name VARCHAR(50) GENERATED ALWAYS AS (regexp_replace(regexp_replace(regexp_replace(name, '^\\w+\\.', ''), '\\.\\w+$', ''), '_.*$', '')) STORED");
            $connection->statement("CREATE INDEX {$wrapped_module_index} ON {$wrapped_permissions_table} (module_name)");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD CONSTRAINT {$wrapped_name_check} CHECK (name ~ '^\\w+\\.\\w+\\.\\w+$')");
        } elseif (in_array($driver_family, ['mysql', 'mariadb'], true)) {
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN connection_name VARCHAR(50) AS (regexp_substr(name, '^\\\\w+')) STORED");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN table_name VARCHAR(50) AS (replace(regexp_substr(name, '\\\\.\\\\w+\\\\.'), '.', '')) STORED");
            $connection->statement("CREATE INDEX {$wrapped_reference_index} ON {$wrapped_permissions_table} (connection_name, table_name)");
            // Module = the table's prefix (chars before the first underscore), e.g. `sao_tickets` -> `sao`.
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN module_name VARCHAR(50) AS (substring_index(replace(regexp_substr(name, '\\\\.\\\\w+\\\\.'), '.', ''), '_', 1)) STORED");
            $connection->statement("CREATE INDEX {$wrapped_module_index} ON {$wrapped_permissions_table} (module_name)");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD CONSTRAINT {$wrapped_name_check} CHECK (REGEXP_INSTR(name, '^\\\\w+\\\\.\\\\w+\\\\.\\\\w+$') = 1)");
        } elseif ($driver_family === Db2Drivers::FAMILY) {
            // Db2 has no generated column with a regular expression: plain columns kept by one BEFORE trigger,
            // with the same values as the PostgreSQL generated columns (module = table prefix before the first underscore).
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN connection_name VARCHAR(50)");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN table_name VARCHAR(50)");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN module_name VARCHAR(50)");

            $wrapped_name = $grammar->wrap('name');
            $wrapped_connection_name = $grammar->wrap('connection_name');
            $wrapped_table_name = $grammar->wrap('table_name');
            $wrapped_module_name = $grammar->wrap('module_name');
            $wrapped_name_trigger = $grammar->wrap("{$permissions_table}_name_parts_trigger");

            $connection->unprepared(sprintf(
                "CREATE OR REPLACE TRIGGER %s NO CASCADE BEFORE INSERT OR UPDATE ON %s REFERENCING NEW AS N FOR EACH ROW BEGIN ATOMIC SET N.%s = REGEXP_REPLACE(N.%s, '%s', '\\1'); SET N.%s = REGEXP_REPLACE(N.%s, '%s', '\\2'); SET N.%s = REGEXP_REPLACE(N.%s, '%s', '\\1'); END",
                $wrapped_name_trigger,
                $wrapped_permissions_table,
                $wrapped_connection_name,
                $wrapped_name,
                self::DB2_CONNECTION_PATTERN,
                $wrapped_table_name,
                $wrapped_name,
                self::DB2_TABLE_PATTERN,
                $wrapped_module_name,
                $wrapped_name,
                self::DB2_MODULE_PATTERN,
            ));
            $connection->statement("CREATE INDEX {$wrapped_reference_index} ON {$wrapped_permissions_table} (connection_name, table_name)");
            $connection->statement("CREATE INDEX {$wrapped_module_index} ON {$wrapped_permissions_table} (module_name)");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD CONSTRAINT {$wrapped_name_check} CHECK (REGEXP_LIKE({$wrapped_name}, '" . self::DB2_NAME_PATTERN . "'))");
        } elseif ($driver_family === 'sqlite') {
            // SQLite doesn't support regexp_replace in generated columns, so we use regular columns with triggers
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN connection_name TEXT");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN table_name TEXT");
            $connection->statement("ALTER TABLE {$wrapped_permissions_table} ADD COLUMN module_name TEXT");
            $connection->statement("CREATE INDEX {$wrapped_reference_index} ON {$wrapped_permissions_table} (connection_name, table_name)");
            $connection->statement("CREATE INDEX {$wrapped_module_index} ON {$wrapped_permissions_table} (module_name)");

            // Trigger to extract connection_name (first part before first dot, removing dots if multiple)
            $connection->statement(sprintf("
                CREATE TRIGGER %s
                AFTER INSERT ON %s
                BEGIN
                    UPDATE %s
                    SET connection_name = substr(name, 1, CASE 
                        WHEN instr(substr(name, instr(name, '.') + 1), '.') > 0 
                        THEN instr(name, '.') - 1 
                        ELSE length(name) 
                    END)
                    WHERE id = NEW.id;
                END
            ", $grammar->wrap('permissions_set_connection_name'), $wrapped_permissions_table, $wrapped_permissions_table));

            $connection->statement(sprintf("
                CREATE TRIGGER %s
                AFTER UPDATE OF name ON %s
                BEGIN
                    UPDATE %s
                    SET connection_name = substr(name, 1, CASE 
                        WHEN instr(substr(name, instr(name, '.') + 1), '.') > 0 
                        THEN instr(name, '.') - 1 
                        ELSE length(name) 
                    END)
                    WHERE id = NEW.id;
                END
            ", $grammar->wrap('permissions_update_connection_name'), $wrapped_permissions_table, $wrapped_permissions_table));

            // Trigger to extract table_name (middle part between first and last dot)
            $connection->statement(sprintf("
                CREATE TRIGGER %s
                AFTER INSERT ON %s
                BEGIN
                    UPDATE %s
                    SET table_name = substr(
                        name, 
                        instr(name, '.') + 1,
                        CASE 
                            WHEN instr(substr(name, instr(name, '.') + 1), '.') > 0 
                            THEN instr(substr(name, instr(name, '.') + 1), '.') - 1
                            ELSE 0
                        END
                    )
                    WHERE id = NEW.id;
                END
            ", $grammar->wrap('permissions_set_table_name'), $wrapped_permissions_table, $wrapped_permissions_table));

            $connection->statement(sprintf("
                CREATE TRIGGER %s
                AFTER UPDATE OF name ON %s
                BEGIN
                    UPDATE %s
                    SET table_name = substr(
                        name, 
                        instr(name, '.') + 1,
                        CASE 
                            WHEN instr(substr(name, instr(name, '.') + 1), '.') > 0 
                            THEN instr(substr(name, instr(name, '.') + 1), '.') - 1
                            ELSE 0
                        END
                    )
                    WHERE id = NEW.id;
                END
            ", $grammar->wrap('permissions_update_table_name'), $wrapped_permissions_table, $wrapped_permissions_table));

            // Module = the table's prefix (before the first underscore). Computed
            // directly from `name` (a sibling AFTER INSERT trigger cannot see the
            // table_name value another trigger set on the same insert).
            $table_expr = "substr(name, instr(name, '.') + 1, CASE WHEN instr(substr(name, instr(name, '.') + 1), '.') > 0 THEN instr(substr(name, instr(name, '.') + 1), '.') - 1 ELSE 0 END)";
            $module_expr = "CASE WHEN instr(({$table_expr}), '_') > 0 THEN substr(({$table_expr}), 1, instr(({$table_expr}), '_') - 1) ELSE ({$table_expr}) END";

            $connection->statement(sprintf('
                CREATE TRIGGER %s
                AFTER INSERT ON %s
                BEGIN
                    UPDATE %s SET module_name = %s WHERE id = NEW.id;
                END
            ', $grammar->wrap('permissions_set_module_name'), $wrapped_permissions_table, $wrapped_permissions_table, $module_expr));

            $connection->statement(sprintf('
                CREATE TRIGGER %s
                AFTER UPDATE OF name ON %s
                BEGIN
                    UPDATE %s SET module_name = %s WHERE id = NEW.id;
                END
            ', $grammar->wrap('permissions_update_module_name'), $wrapped_permissions_table, $wrapped_permissions_table, $module_expr));
        } else {
            throw new RuntimeException('Unsupported database driver');
        }

        $schema->create($roles_table, function (Blueprint $table) use ($connection, $teams, $columnNames, $roles_table): void {
            $table->bigIncrements('id'); // role id

            if ($teams || config('permission.testing')) { // permission.testing is a fix for sqlite testing
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable()->comment('The team foreign key of the role');
                $table->index($columnNames['team_foreign_key'], "{$roles_table}_team_foreign_key_index");
            }
            $table->string('name', 125)->nullable(false)->comment('The name of the role');       // For MySQL 8.0 use string('name', 125);
            $table->string('guard_name', 125)->default('web')->nullable(false)->comment('The guard name of the role'); // For MySQL 8.0 use string('guard_name', 125);
            $table->string('description')->nullable(true)->comment('The description of the role');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
                hasLocks: true,
                connection: $connection,
            );

            if ($teams || config('permission.testing')) {
                $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name'], "{$roles_table}_UN");
            } else {
                $table->unique(['name', 'guard_name'], "{$roles_table}_UN");
            }
        });

        $schema->table($roles_table, function (Blueprint $table) use ($roles_table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->comment('The parent id of the role');
            $table->foreign('parent_id', "{$roles_table}_parent_role_FK")->references('id')->on($roles_table)->nullOnDelete();
            MigrateUtils::prefixIndex($table, 'parent_id');
        });

        MigrateUtils::closureTable('vend_roles', $connection);

        $schema->create($model_has_permissions_table, function (Blueprint $table) use ($connection, $model_has_permissions_table, $columnNames, $pivotPermission, $teams, $permissions_table): void {
            $table->unsignedBigInteger($pivotPermission);

            $table->string('model_type')->nullable(false)->comment('The model type of the permission');
            $table->unsignedBigInteger($columnNames['model_morph_key'])->nullable(false)->comment('The model id of the permission');
            $table->index([$columnNames['model_morph_key'], 'model_type'], "{$model_has_permissions_table}_morph_idx");

            $table->foreign($pivotPermission)
                ->references('id') // permission id
                ->on($permissions_table)
                ->onDelete('cascade');

            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable(false)->comment('The team foreign key of the permission');
                $table->index($columnNames['team_foreign_key'], "{$model_has_permissions_table}_team_idx");

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    "{$model_has_permissions_table}_permission_model_type_primary",
                );
            } else {
                $table->primary(
                    [$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    "{$model_has_permissions_table}_permission_model_type_primary",
                );
            }

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                connection: $connection,
            );
        });

        $schema->create($model_has_roles_table, function (Blueprint $table) use ($connection, $model_has_roles_table, $columnNames, $pivotRole, $teams, $roles_table): void {
            $table->unsignedBigInteger($pivotRole);

            $table->string('model_type')->nullable(false)->comment('The model type of the role');
            $table->unsignedBigInteger($columnNames['model_morph_key'])->nullable(false)->comment('The model id of the role');
            $table->index([$columnNames['model_morph_key'], 'model_type'], "{$model_has_roles_table}_morph_idx");

            $table->foreign($pivotRole)
                ->references('id') // role id
                ->on($roles_table)
                ->onDelete('cascade');

            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable(false)->comment('The team foreign key of the role');
                $table->index($columnNames['team_foreign_key'], "{$model_has_roles_table}_team_idx");

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    "{$model_has_roles_table}_role_model_type_primary",
                );
            } else {
                $table->primary(
                    [$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    "{$model_has_roles_table}_role_model_type_primary",
                );
            }

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                connection: $connection,
            );
        });

        $schema->create($role_has_permissions_table, function (Blueprint $table) use ($connection, $role_has_permissions_table, $pivotRole, $pivotPermission, $permissions_table, $roles_table): void {
            $table->unsignedBigInteger($pivotPermission)->nullable(false)->comment('The permission id of the role');
            $table->unsignedBigInteger($pivotRole)->nullable(false)->comment('The role id of the permission');

            $table->foreign($pivotPermission)
                ->references('id') // permission id
                ->on($permissions_table)
                ->onDelete('cascade');

            $table->foreign($pivotRole)
                ->references('id') // role id
                ->on($roles_table)
                ->onDelete('cascade');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                connection: $connection,
            );

            $table->primary([$pivotPermission, $pivotRole], "{$role_has_permissions_table}_primary");
            MigrateUtils::prefixIndex($table, $pivotRole);
        });

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        if ($tableNames === '' || $tableNames === null) {
            throw new RuntimeException('Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
        }

        $schema = app('db')->connection()->getSchemaBuilder();
        $schema->dropIfExists('vend_roles_closure');
        $schema->drop($tableNames['role_has_permissions']);
        $schema->drop($tableNames['model_has_roles']);
        $schema->drop($tableNames['model_has_permissions']);
        $schema->drop($tableNames['roles']);
        $schema->drop($tableNames['permissions']);
    }
};
