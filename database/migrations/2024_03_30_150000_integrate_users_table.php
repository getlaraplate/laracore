<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Fortify;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $users_table = CoreTables::Users->value;
        Schema::table($users_table, function (Blueprint $table) use ($users_table): void {
            $table->string('username')->unique("{$users_table}_username_UN")->nullable(false)->comment('The username of the user');
            $table->string('lang')->nullable(true)->comment('The language of the user');
            $table->string('timezone')->nullable(true)->comment('The IANA timezone of the user, for example Europe/Rome');
            $table->datetime('last_login_at')->nullable(true)->comment('The last login date of the user');
            $table->foreignId('license_id')->nullable()->comment('The license assigned to the user');
            $table->text('two_factor_secret')->after('password')->nullable()->comment('The two factor secret of the user');
            $table->text('two_factor_recovery_codes')->nullable()->comment('The two factor recovery codes of the user');

            if (Fortify::confirmsTwoFactorAuthentication()) {
                $table->timestamp('two_factor_confirmed_at')->after('two_factor_recovery_codes')->nullable()->comment('The two factor confirmed date of the user');
            }

            $table->boolean('is_first_login')->default(true)->comment('Whether the account still needs the first-login onboarding flow');
            $table->json('preferences')->nullable()->comment('Server-persisted UI preferences (theme, density, layout) synced from the SPA');
            $table->boolean('is_service_account')->default(false)->comment('Whether the account is non-interactive: it has no usable password, never signs in and authenticates with API tokens only');
            $table->string('social_id')->nullable()->comment('The id of the user at the social login provider');
            $table->string('social_service')->nullable()->comment('The social login provider the user signed in with');
            $table->text('social_token')->nullable()->comment('The access token of the social login, encrypted');
            $table->text('social_refresh_token')->nullable()->comment('The refresh token of the social login, encrypted');
            $table->text('social_token_secret')->nullable()->comment('The token secret of an OAuth 1 social login, encrypted');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
                hasLocks: true,
                hasValidity: true,
            );

            $table->foreign('license_id', "{$users_table}_licenses_FK")->references('id')->on(CoreTables::Licenses->value)->nullOnDelete();
            MigrateUtils::prefixIndex($table, 'license_id');
            $table->index('social_id', "{$users_table}_social_id_IDX");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $users_table = CoreTables::Users->value;
        Schema::table($users_table, function (Blueprint $table) use ($users_table): void {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'sqlite') {
                $table->dropUnique("{$users_table}_username_UN");
            }
            $table->dropColumn('username');
            $table->dropColumn('lang');
            $table->dropColumn('timezone');
            $table->dropColumn('last_login_at');
            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );
        });
    }
};
