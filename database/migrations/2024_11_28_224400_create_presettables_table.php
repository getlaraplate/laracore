<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Support\Db2Drivers;

return new class extends Migration
{
    private ?Connection $activeConnection = null;

    /**
     * Create the presettables pivot with row versioning, field snapshots, and DB triggers.
     *
     * Drivers: mysql, mariadb, pgsql, sqlite, sqlsrv, oracle, db2.
     *
     * Unique / FK: InnoDB reuses the unique index behind `presettables_preset_FK`; the composite
     * unique `presettables_version_UN` on (entity_id, preset_id, version) supports the FK on
     * (entity_id, preset_id).
     *
     * `fields_snapshot`: MySQL/MariaDB reject a literal DEFAULT on JSON; nullable JSON is created
     * first, then {@see applyFieldsSnapshotNotNullConstraint()} runs once the table is still empty.
     */
    public function up(): void
    {
        $connection = $this->connection();
        $presettables_table = CoreTables::Presettables->value;
        $connection->getSchemaBuilder()->create($presettables_table, static function (Blueprint $table) use ($connection, $presettables_table): void {
            $table->id();
            $table->unsignedBigInteger('preset_id')->nullable(false)->comment('The preset that the entity preset relation belongs to');
            $table->unsignedBigInteger('entity_id')->nullable(false)->comment('The entity that the entity preset relation belongs to');
            $table->unsignedInteger('version')->default(1)
                ->comment('Incremental version number scoped to preset+entity');
            $table->json('fields_snapshot')->nullable()
                ->comment('Frozen snapshot of the fields configuration at this version');
            $table->timestamp('created_at')->nullable()->useCurrent();

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: false,
                hasSoftDelete: true,
                connection: $connection,
            );

            $table->foreign(['entity_id', 'preset_id'], "{$presettables_table}_preset_FK")
                ->references(['entity_id', 'id'])
                ->on(CoreTables::Presets->value)
                ->cascadeOnDelete();
            $table->unique(['entity_id', 'preset_id', 'version'], "{$presettables_table}_version_UN");
        });

        $this->applyFieldsSnapshotNotNullConstraint();
        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        $this->connection()->getSchemaBuilder()->dropIfExists(CoreTables::Presettables->value);
    }

    /**
     * Enforce NOT NULL on fields_snapshot after the table is created (same on MySQL, PostgreSQL, SQLite, etc.).
     */
    private function applyFieldsSnapshotNotNullConstraint(): void
    {
        if ($this->connection()->table(CoreTables::Presettables->value)->whereNull('fields_snapshot')->exists()) {
            throw new RuntimeException(
                'Cannot enforce NOT NULL on presettables.fields_snapshot: null values remain.',
            );
        }

        if (Db2Drivers::is($this->connection()->getDriverName())) {
            // The Db2 schema grammar cannot modify a column, so the constraint is set with the native statement.
            $this->connection()->statement('ALTER TABLE ' . $this->table(CoreTables::Presettables->value) . ' ALTER COLUMN ' . $this->identifier('fields_snapshot') . ' SET NOT NULL');

            return;
        }

        $this->connection()->getSchemaBuilder()->table(CoreTables::Presettables->value, static function (Blueprint $table): void {
            $table->json('fields_snapshot')->nullable(false)->change();
        });
    }

    /**
     * Create database-specific triggers.
     */
    private function createTriggers(): void
    {
        match (Db2Drivers::family($this->connection()->getDriverName())) {
            'mysql', 'mariadb' => $this->createMySQLTriggers(),
            'pgsql' => $this->createPostgreSQLTriggers(),
            'sqlite' => $this->createSQLiteTriggers(),
            'sqlsrv' => $this->createSQLServerTriggers(),
            'oracle' => $this->createOracleTriggers(),
            Db2Drivers::FAMILY => $this->createDb2Triggers(),
            default => throw new RuntimeException('Unsupported database driver: ' . $this->connection()->getDriverName()),
        };
    }

    /**
     * Drop database-specific triggers.
     */
    private function dropTriggers(): void
    {
        match (Db2Drivers::family($this->connection()->getDriverName())) {
            'mysql', 'mariadb' => $this->dropMySQLTriggers(),
            'pgsql' => $this->dropPostgreSQLTriggers(),
            'sqlite' => $this->dropSQLiteTriggers(),
            'sqlsrv' => $this->dropSQLServerTriggers(),
            'oracle' => $this->dropOracleTriggers(),
            Db2Drivers::FAMILY => $this->dropDb2Triggers(),
            default => throw new RuntimeException('Unsupported database driver: ' . $this->connection()->getDriverName()),
        };
    }

    private function createPostgreSQLTriggers(): void
    {
        $presettables_table = $this->table(CoreTables::Presettables->value);
        $presets_table = $this->table(CoreTables::Presets->value);
        $version_function = $this->identifier('set_presettable_version');
        $version_trigger = $this->identifier('trg_presettables_version');
        $sync_function = $this->identifier('sync_presettables_on_preset');
        $insert_trigger = $this->identifier('trg_presets_insert');
        $update_trigger = $this->identifier('trg_presets_update');
        $delete_trigger = $this->identifier('trg_presets_delete');

        $this->connection()->unprepared("
            CREATE OR REPLACE FUNCTION {$version_function}()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.version := COALESCE(
                    (SELECT MAX(version) FROM {$presettables_table}
                     WHERE preset_id = NEW.preset_id AND entity_id = NEW.entity_id), 0
                ) + 1;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$version_trigger}
            BEFORE INSERT ON {$presettables_table}
            FOR EACH ROW EXECUTE FUNCTION {$version_function}();
        ");

        $this->connection()->unprepared("
            CREATE OR REPLACE FUNCTION {$sync_function}()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    INSERT INTO {$presettables_table} (entity_id, preset_id, fields_snapshot, deleted_at)
                    VALUES (NEW.entity_id, NEW.id, '[]', NEW.deleted_at);
                    RETURN NEW;
                ELSIF TG_OP = 'UPDATE' THEN
                    IF NEW.deleted_at IS DISTINCT FROM OLD.deleted_at THEN
                        UPDATE {$presettables_table}
                        SET deleted_at = NEW.deleted_at
                        WHERE preset_id = NEW.id AND deleted_at IS NULL;
                    END IF;
                    RETURN NEW;
                ELSIF TG_OP = 'DELETE' THEN
                    DELETE FROM {$presettables_table} WHERE preset_id = OLD.id;
                    RETURN OLD;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$insert_trigger}
            AFTER INSERT ON {$presets_table}
            FOR EACH ROW EXECUTE FUNCTION {$sync_function}();
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$update_trigger}
            AFTER UPDATE ON {$presets_table}
            FOR EACH ROW EXECUTE FUNCTION {$sync_function}();
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$delete_trigger}
            AFTER DELETE ON {$presets_table}
            FOR EACH ROW EXECUTE FUNCTION {$sync_function}();
        ");
    }

    private function dropPostgreSQLTriggers(): void
    {
        $presettables_table = $this->table(CoreTables::Presettables->value);
        $presets_table = $this->table(CoreTables::Presets->value);
        $version_function = $this->identifier('set_presettable_version');
        $version_trigger = $this->identifier('trg_presettables_version');
        $sync_function = $this->identifier('sync_presettables_on_preset');
        $insert_trigger = $this->identifier('trg_presets_insert');
        $update_trigger = $this->identifier('trg_presets_update');
        $delete_trigger = $this->identifier('trg_presets_delete');

        $this->connection()->unprepared("DROP TRIGGER IF EXISTS {$version_trigger} ON {$presettables_table}");
        $this->connection()->unprepared("DROP FUNCTION IF EXISTS {$version_function}()");
        $this->connection()->unprepared("DROP TRIGGER IF EXISTS {$insert_trigger} ON {$presets_table}");
        $this->connection()->unprepared("DROP TRIGGER IF EXISTS {$update_trigger} ON {$presets_table}");
        $this->connection()->unprepared("DROP TRIGGER IF EXISTS {$delete_trigger} ON {$presets_table}");
        $this->connection()->unprepared("DROP FUNCTION IF EXISTS {$sync_function}()");
    }

    private function createMySQLTriggers(): void
    {
        $presettables_table = $this->table(CoreTables::Presettables->value);
        $presets_table = $this->table(CoreTables::Presets->value);
        $version_trigger = $this->identifier('trg_presettables_version');
        $insert_trigger = $this->identifier('trg_presets_insert');
        $update_trigger = $this->identifier('trg_presets_update');
        $delete_trigger = $this->identifier('trg_presets_delete');

        $this->connection()->unprepared("
            CREATE TRIGGER {$version_trigger} BEFORE INSERT ON {$presettables_table}
            FOR EACH ROW
            SET NEW.version = COALESCE(
                (SELECT MAX(version) FROM {$presettables_table}
                 WHERE preset_id = NEW.preset_id AND entity_id = NEW.entity_id), 0
            ) + 1;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$insert_trigger} AFTER INSERT ON {$presets_table}
            FOR EACH ROW
            INSERT INTO {$presettables_table} (entity_id, preset_id, fields_snapshot, deleted_at)
            VALUES (NEW.entity_id, NEW.id, '[]', NEW.deleted_at);
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$update_trigger} AFTER UPDATE ON {$presets_table}
            FOR EACH ROW
            BEGIN
                IF NEW.deleted_at <> OLD.deleted_at OR (NEW.deleted_at IS NULL) <> (OLD.deleted_at IS NULL) THEN
                    UPDATE {$presettables_table}
                    SET deleted_at = NEW.deleted_at
                    WHERE preset_id = NEW.id AND deleted_at IS NULL;
                END IF;
            END;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$delete_trigger} AFTER DELETE ON {$presets_table}
            FOR EACH ROW
            DELETE FROM {$presettables_table} WHERE preset_id = OLD.id;
        ");
    }

    private function dropMySQLTriggers(): void
    {
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presettables_version'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_insert'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_update'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_delete'));
    }

    private function createSQLiteTriggers(): void
    {
        $presettables_table = $this->table(CoreTables::Presettables->value);
        $presets_table = $this->table(CoreTables::Presets->value);
        $insert_trigger = $this->identifier('trg_presets_insert');
        $update_trigger = $this->identifier('trg_presets_update');
        $delete_trigger = $this->identifier('trg_presets_delete');

        $this->connection()->unprepared("
            CREATE TRIGGER {$insert_trigger} AFTER INSERT ON {$presets_table}
            BEGIN
                INSERT INTO {$presettables_table} (entity_id, preset_id, fields_snapshot, version, deleted_at)
                VALUES (NEW.entity_id, NEW.id, '[]',
                    COALESCE((SELECT MAX(version) FROM {$presettables_table} WHERE preset_id = NEW.id AND entity_id = NEW.entity_id), 0) + 1,
                    NEW.deleted_at);
            END;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$update_trigger} AFTER UPDATE ON {$presets_table}
            WHEN NEW.deleted_at IS NOT OLD.deleted_at
            BEGIN
                UPDATE {$presettables_table}
                SET deleted_at = NEW.deleted_at
                WHERE preset_id = NEW.id AND deleted_at IS NULL;
            END;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$delete_trigger} AFTER DELETE ON {$presets_table}
            BEGIN
                DELETE FROM {$presettables_table} WHERE preset_id = OLD.id;
            END;
        ");
    }

    private function dropSQLiteTriggers(): void
    {
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_insert'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_update'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_delete'));
    }

    private function createSQLServerTriggers(): void
    {
        $presettables_table = $this->table(CoreTables::Presettables->value);
        $presets_table = $this->table(CoreTables::Presets->value);
        $version_trigger = $this->identifier('trg_presettables_version');
        $insert_trigger = $this->identifier('trg_presets_insert');
        $update_trigger = $this->identifier('trg_presets_update');
        $delete_trigger = $this->identifier('trg_presets_delete');

        $this->connection()->unprepared("
            CREATE TRIGGER {$version_trigger} ON {$presettables_table}
            INSTEAD OF INSERT
            AS
            BEGIN
                SET NOCOUNT ON;
                INSERT INTO {$presettables_table} (entity_id, preset_id, version, fields_snapshot, created_at, deleted_at)
                SELECT i.entity_id, i.preset_id,
                    COALESCE((SELECT MAX(version) FROM {$presettables_table} p
                              WHERE p.preset_id = i.preset_id AND p.entity_id = i.entity_id), 0) + 1,
                    i.fields_snapshot, i.created_at, i.deleted_at
                FROM inserted i;
            END;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$insert_trigger} ON {$presets_table}
            AFTER INSERT
            AS
            BEGIN
                SET NOCOUNT ON;
                INSERT INTO {$presettables_table} (entity_id, preset_id, fields_snapshot, deleted_at)
                SELECT entity_id, id, '[]', deleted_at
                FROM inserted;
            END;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$update_trigger} ON {$presets_table}
            AFTER UPDATE
            AS
            BEGIN
                SET NOCOUNT ON;
                UPDATE {$presettables_table}
                SET deleted_at = i.deleted_at
                FROM {$presettables_table} ep
                INNER JOIN inserted i ON ep.preset_id = i.id
                INNER JOIN deleted d ON ep.preset_id = d.id
                WHERE (i.deleted_at IS NULL AND d.deleted_at IS NOT NULL)
                   OR (i.deleted_at IS NOT NULL AND d.deleted_at IS NULL)
                   OR (i.deleted_at <> d.deleted_at);
            END;
        ");

        $this->connection()->unprepared("
            CREATE TRIGGER {$delete_trigger} ON {$presets_table}
            AFTER DELETE
            AS
            BEGIN
                SET NOCOUNT ON;
                DELETE FROM {$presettables_table}
                WHERE preset_id IN (SELECT id FROM deleted);
            END;
        ");
    }

    private function dropSQLServerTriggers(): void
    {
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presettables_version'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_insert'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_update'));
        $this->connection()->unprepared('DROP TRIGGER IF EXISTS ' . $this->identifier('trg_presets_delete'));
    }

    private function createOracleTriggers(): void
    {
        $presettables_table = $this->table(CoreTables::Presettables->value);
        $presets_table = $this->table(CoreTables::Presets->value);
        $version_trigger = $this->identifier('trg_presettables_version');
        $insert_trigger = $this->identifier('trg_presets_insert');
        $update_trigger = $this->identifier('trg_presets_update');
        $delete_trigger = $this->identifier('trg_presets_delete');

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$version_trigger}
            BEFORE INSERT ON {$presettables_table}
            FOR EACH ROW
            DECLARE
                v_max_version NUMBER;
            BEGIN
                SELECT COALESCE(MAX(version), 0) + 1 INTO v_max_version
                FROM {$presettables_table}
                WHERE preset_id = :NEW.preset_id AND entity_id = :NEW.entity_id;
                :NEW.version := v_max_version;
            END;
        ");

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$insert_trigger}
            AFTER INSERT ON {$presets_table}
            FOR EACH ROW
            BEGIN
                INSERT INTO {$presettables_table} (entity_id, preset_id, fields_snapshot, deleted_at)
                VALUES (:NEW.entity_id, :NEW.id, '[]', :NEW.deleted_at);
            END;
        ");

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$update_trigger}
            AFTER UPDATE ON {$presets_table}
            FOR EACH ROW
            BEGIN
                IF (:NEW.deleted_at IS NULL AND :OLD.deleted_at IS NOT NULL)
                   OR (:NEW.deleted_at IS NOT NULL AND :OLD.deleted_at IS NULL) THEN
                    UPDATE {$presettables_table}
                    SET deleted_at = :NEW.deleted_at
                    WHERE preset_id = :NEW.id AND deleted_at IS NULL;
                END IF;
            END;
        ");

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$delete_trigger}
            AFTER DELETE ON {$presets_table}
            FOR EACH ROW
            BEGIN
                DELETE FROM {$presettables_table} WHERE preset_id = :OLD.id;
            END;
        ");
    }

    private function dropOracleTriggers(): void
    {
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presettables_version'));
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presets_insert'));
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presets_update'));
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presets_delete'));
    }

    /**
     * Db2 for i SQL PL: the version is set by a BEFORE trigger (which only reads the table), the preset
     * synchronisation by AFTER triggers that write presettables.
     */
    private function createDb2Triggers(): void
    {
        $presettables_table = $this->table(CoreTables::Presettables->value);
        $presets_table = $this->table(CoreTables::Presets->value);
        $version_trigger = $this->identifier('trg_presettables_version');
        $insert_trigger = $this->identifier('trg_presets_insert');
        $update_trigger = $this->identifier('trg_presets_update');
        $delete_trigger = $this->identifier('trg_presets_delete');
        $id = $this->identifier('id');
        $version = $this->identifier('version');
        $entity_id = $this->identifier('entity_id');
        $preset_id = $this->identifier('preset_id');
        $snapshot = $this->identifier('fields_snapshot');
        $deleted_at = $this->identifier('deleted_at');

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$version_trigger}
            NO CASCADE BEFORE INSERT ON {$presettables_table}
            REFERENCING NEW AS N
            FOR EACH ROW
            SET N.{$version} = COALESCE(
                (SELECT MAX({$version}) FROM {$presettables_table}
                 WHERE {$preset_id} = N.{$preset_id} AND {$entity_id} = N.{$entity_id}), 0
            ) + 1
        ");

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$insert_trigger}
            AFTER INSERT ON {$presets_table}
            REFERENCING NEW AS N
            FOR EACH ROW
            BEGIN ATOMIC
                INSERT INTO {$presettables_table} ({$entity_id}, {$preset_id}, {$snapshot}, {$deleted_at})
                VALUES (N.{$entity_id}, N.{$id}, '[]', N.{$deleted_at});
            END
        ");

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$update_trigger}
            AFTER UPDATE ON {$presets_table}
            REFERENCING NEW AS N OLD AS O
            FOR EACH ROW
            BEGIN ATOMIC
                IF (N.{$deleted_at} IS NULL AND O.{$deleted_at} IS NOT NULL)
                   OR (N.{$deleted_at} IS NOT NULL AND O.{$deleted_at} IS NULL) THEN
                    UPDATE {$presettables_table}
                    SET {$deleted_at} = N.{$deleted_at}
                    WHERE {$preset_id} = N.{$id} AND {$deleted_at} IS NULL;
                END IF;
            END
        ");

        $this->connection()->unprepared("
            CREATE OR REPLACE TRIGGER {$delete_trigger}
            AFTER DELETE ON {$presets_table}
            REFERENCING OLD AS O
            FOR EACH ROW
            BEGIN ATOMIC
                DELETE FROM {$presettables_table} WHERE {$preset_id} = O.{$id};
            END
        ");
    }

    private function dropDb2Triggers(): void
    {
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presettables_version'));
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presets_insert'));
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presets_update'));
        $this->connection()->unprepared('DROP TRIGGER ' . $this->identifier('trg_presets_delete'));
    }

    private function connection(): Connection
    {
        return $this->activeConnection ??= app('db')->connection();
    }

    private function table(string $table): string
    {
        return $this->connection()->getQueryGrammar()->wrapTable($table);
    }

    private function identifier(string $identifier): string
    {
        return $this->connection()->getQueryGrammar()->wrap($identifier);
    }
};
