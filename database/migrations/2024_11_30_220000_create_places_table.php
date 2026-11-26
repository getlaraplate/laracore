<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Support\Db2Drivers;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = app('db')->connection();
        $schema = $connection->getSchemaBuilder();
        $places_table = CoreTables::Places->value;
        $schema->create($places_table, function (Blueprint $table) use ($connection, $places_table): void {
            $table->id();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->string('postcode')->nullable();
            $table->string('zone')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $driver = Db2Drivers::family($connection->getDriverName());

            if ($driver === 'pgsql') {
                // Create PostGIS extension first
                $connection->unprepared('CREATE EXTENSION IF NOT EXISTS postgis;');
                $table->geometry('geolocation', 'point', 4326)->nullable()->spatialIndex()->comment('The geolocation of the location');
            } elseif (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
                // Campo opzionale, nessun spatial index per evitare NOT NULL forzato
                $table->geometry('geolocation')->nullable()->comment('The geolocation of the location');
            } elseif ($driver === Db2Drivers::FAMILY) {
                // No spatial column on Db2: latitude and longitude hold the point, as SQLite does for spatial reads.
                // Nothing writes `geolocation` on this driver (see HasPlace and Place), so there is no column to create.
            } elseif ($driver === 'oracle') {
                // Campo opzionale; registriamo metadata SDO e creiamo indice spaziale
                $table->geometry('geolocation')->nullable()->comment('The geolocation of the location');

                $upper_places_table = mb_strtoupper($places_table);
                $wrapped_metadata_table = $connection->getQueryGrammar()->wrap('user_sdo_geom_metadata');
                $connection->unprepared("
                    DECLARE
                        tbl VARCHAR2(128) := '{$upper_places_table}';
                        col VARCHAR2(128) := 'GEOLOCATION';
                        srid NUMBER := 4326;
                    BEGIN
                        BEGIN
                            DELETE FROM {$wrapped_metadata_table} WHERE table_name = tbl AND column_name = col;
                        EXCEPTION
                            WHEN NO_DATA_FOUND THEN NULL;
                        END;

                        INSERT INTO {$wrapped_metadata_table} (table_name, column_name, diminfo, srid)
                        VALUES (
                            tbl,
                            col,
                            MDSYS.SDO_DIM_ARRAY(
                                MDSYS.SDO_DIM_ELEMENT('LONG', -180, 180, 0.005),
                                MDSYS.SDO_DIM_ELEMENT('LAT', -90, 90, 0.005)
                            ),
                            srid
                        );
                    END;
                ");

                $grammar = $connection->getQueryGrammar();
                $wrapped_index = $grammar->wrap("{$places_table}_geolocation_spx");
                $wrapped_table = $grammar->wrapTable($places_table);
                $wrapped_column = $grammar->wrap('geolocation');
                $connection->unprepared("CREATE INDEX {$wrapped_index} ON {$wrapped_table}({$wrapped_column}) INDEXTYPE IS MDSYS.SPATIAL_INDEX");
            }

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
                connection: $connection,
            );

            $table->index('city', "{$places_table}_city_IDX");
            $table->index('province', "{$places_table}_province_IDX");
        });

        MigrateUtils::fuzzyIndex($places_table, 'address', connection: $connection);
        MigrateUtils::fuzzyIndex($places_table, 'city', connection: $connection);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app('db')->connection()->getSchemaBuilder()->dropIfExists(CoreTables::Places->value);
    }
};
