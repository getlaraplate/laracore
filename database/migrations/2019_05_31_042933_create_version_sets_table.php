<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Helpers\MigrateUtils;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(CoreTables::VersionsEpochs->value, static function (Blueprint $table): void {
            $table->id();
            $table->string('root_type');
            $table->string('root_table')->comment('A class whose table varies per instance has an epoch per table');
            $table->string('fingerprint', 64)->comment('sha256 of the versioned shape of the root class (D13, D33, D57)');
            $table->json('definition')->comment('The versioned columns, translation columns and relations the fingerprint covers');
            $table->timestamp('first_seen_at');

            $table->unique(['root_type', 'root_table', 'fingerprint'], 'vepochs_root_fingerprint_UN');
        });

        $table_name = CoreTables::VersionSets->value;

        Schema::create($table_name, static function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('root_type')->nullable();
            $table->string('root_id')->nullable();
            $table->string('root_connection_ref')->nullable();
            $table->string('root_table_ref')->nullable();
            $table->unsignedBigInteger(config('versionable.user_foreign_key', 'user_id'))->nullable();
            $table->enum(
                'kind',
                array_map(static fn (VersionSetKind $case): string => $case->value, VersionSetKind::cases()),
            );
            $table->enum(
                'capture',
                array_map(static fn (VersionCaptureQuality $case): string => $case->value, VersionCaptureQuality::cases()),
            )->default(VersionCaptureQuality::BestEffort->value)->comment('Capture quality of the set (D47, D52)');
            $table->string('reason', 255)->nullable();
            $table->unsignedInteger('baseline_from_sequence')->nullable()->comment('First row of the aggregate image appended to a baseline (D45)');
            $table->unsignedBigInteger('reverted_from_set_id')->nullable();

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                createdAtIndexName: 'vsets_created_IDX',
                hasUpdatedAt: false,
            );

            $table->unique('uuid', 'vsets_uuid_UN');
            $table->index(['root_type', 'root_id'], 'vsets_root_IDX');
            MigrateUtils::prefixIndex($table, 'user_id');
            MigrateUtils::prefixIndex($table, 'reverted_from_set_id');
            // A revert whose target was purged or compacted keeps a null link (D58); Oracle supports SET NULL.
            $table->foreign('reverted_from_set_id', 'vsets_reverted_FK')
                ->references('id')
                ->on($table_name)
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTables::VersionSets->value);
        Schema::dropIfExists(CoreTables::VersionsEpochs->value);
    }
};
