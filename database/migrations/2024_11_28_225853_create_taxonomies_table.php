<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = app('db')->connection();
        $schema = $connection->getSchemaBuilder();
        $taxonomies_table = CoreTables::Taxonomies->value;
        $schema->create($taxonomies_table, static function (Blueprint $table) use ($connection, $taxonomies_table): void {
            $table->id();
            $table->foreignId('entity_id')->nullable(false)->constrained(CoreTables::Entities->value, 'id', "{$taxonomies_table}_entity_id_FK")->cascadeOnDelete()->comment('The entity that the taxonomy belongs to');
            $table->foreignId('presettable_id')->nullable(false)->constrained(CoreTables::Presettables->value, 'id', "{$taxonomies_table}_presettable_id_FK")->cascadeOnDelete()->comment('The entity preset that the taxonomy belongs to');
            $table->json('shared_components')->nullable()->comment('The shared dynamic components of the taxonomy');
            $table->foreignId('parent_id')->nullable(true)->constrained($taxonomies_table, 'id', "{$taxonomies_table}_parent_id_FK")->nullOnDelete()->comment('The parent taxonomy');
            $table->string('logo')->nullable(true)->comment('The logo of the taxonomy');
            $table->string('logo_full')->nullable(true)->comment('The full logo of the taxonomy');
            $table->boolean('is_active')->default(true)->nullable(false)->index("{$taxonomies_table}_is_active_IDX")->comment('Whether the taxonomy is active');
            $table->integer('order_column')->nullable(false)->default(0)->index("{$taxonomies_table}_order_column_IDX")->comment('The order of the taxonomy');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
                hasLocks: true,
                hasValidity: true,
                connection: $connection,
            );

            // Unique constraints for name and slug are now in taxonomies_translations table (per locale)
            $table->unique(['id', 'parent_id'], "{$taxonomies_table}_parent_UN");
            $table->unique(['id', 'entity_id'], "{$taxonomies_table}_entity_UN");
            MigrateUtils::prefixIndex($table, 'entity_id');
            MigrateUtils::prefixIndex($table, 'presettable_id');
            MigrateUtils::prefixIndex($table, 'parent_id');
        });

        MigrateUtils::closureTable($taxonomies_table, $connection);

        // Evita auto-relazione (categoria che punta sé stessa)
        $driver_name = $connection->getDriverName();
        $grammar = $connection->getQueryGrammar();
        $wrapped_taxonomies_table = $grammar->wrapTable($taxonomies_table);

        if ($driver_name === 'pgsql') {
            $wrapped_constraint = $grammar->wrap("{$taxonomies_table}_parent_id_check");
            $connection->statement("ALTER TABLE {$wrapped_taxonomies_table} ADD CONSTRAINT {$wrapped_constraint} CHECK (parent_id <> id)");
        } elseif (in_array($driver_name, ['mysql', 'mariadb'], true)) {
            // MySQL/MariaDB non consentono il CHECK con colonna usata da una FK (errore 3823),
            // quindi usiamo trigger per bloccare parent_id = id.
            $wrapped_insert_trigger = $grammar->wrap('taxonomies_parent_check_insert');
            $connection->unprepared(<<<SQL
                CREATE TRIGGER {$wrapped_insert_trigger}
                BEFORE INSERT ON {$wrapped_taxonomies_table}
                FOR EACH ROW
                BEGIN
                    IF NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'parent_id cannot reference self';
                    END IF;
                END;
            SQL);

            $wrapped_update_trigger = $grammar->wrap('taxonomies_parent_check_update');
            $connection->unprepared(<<<SQL
                CREATE TRIGGER {$wrapped_update_trigger}
                BEFORE UPDATE ON {$wrapped_taxonomies_table}
                FOR EACH ROW
                BEGIN
                    IF NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'parent_id cannot reference self';
                    END IF;
                END;
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = app('db')->connection()->getSchemaBuilder();
        $schema->dropIfExists('core_taxonomies_closure');
        $schema->dropIfExists(CoreTables::Taxonomies->value);
    }
};
