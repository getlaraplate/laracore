<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;

/**
 * The hierarchy locks, dated before the first closure table (the roles') so a fresh install creates it first.
 * An install whose closure tables already exist gets it as a pending migration; the helper skips an existing table,
 * so either order works.
 */
return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        MigrateUtils::hierarchyLocksTable();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_hierarchy_locks');
    }
};
