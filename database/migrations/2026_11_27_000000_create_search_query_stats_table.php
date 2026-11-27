<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $stats_table = CoreTables::SearchQueryStats->value;
        Schema::create($stats_table, function (Blueprint $table) use ($stats_table): void {
            $table->id();
            $table->string('query', 255)->nullable(false)->comment('The normalized query');
            $table->string('locale', 12)->nullable(false)->comment('The locale of the search');
            $table->string('model', 128)->nullable(false)->comment('The table of the searched model');
            $table->date('day')->nullable(false)->index("{$stats_table}_day_IDX")->comment('The day of the aggregate');
            $table->unsignedInteger('searches')->nullable(false)->default(0)->comment('Searches of the day');
            $table->unsignedInteger('low_result_searches')->nullable(false)->default(0)->comment('Searches of the day with few results');
            $table->unsignedBigInteger('result_sum')->nullable(false)->default(0)->comment('Sum of the results of the day');

            $table->unique(['query', 'locale', 'model', 'day'], "{$stats_table}_query_locale_model_day_UQ");

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTables::SearchQueryStats->value);
    }
};
