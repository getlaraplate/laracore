<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;

return new class extends Migration
{
    /**
     * Store `source_updated_at` as DATETIME.
     *
     * The registry writes the source instant as a UTC wall-clock string. On MySQL and MariaDB a TIMESTAMP column
     * reads that string in the session time zone, so a UTC instant that falls in a local DST gap (for example
     * 2025-03-30 02:45:37 in Europe/Rome) is rejected with error 1292. DATETIME stores the string as given.
     */
    public function up(): void
    {
        Schema::table(CoreTables::RecordOrigins->value, static function (Blueprint $table): void {
            $table->dateTime('source_updated_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table(CoreTables::RecordOrigins->value, static function (Blueprint $table): void {
            $table->timestamp('source_updated_at')->nullable()->change();
        });
    }
};
