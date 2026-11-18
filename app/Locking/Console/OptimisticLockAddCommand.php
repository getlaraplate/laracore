<?php

declare(strict_types=1);

namespace Modules\Core\Locking\Console;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PerModelSettingResolver;
use Override;

class OptimisticLockAddCommand extends LockedAddCommand
{
    #[Override]
    public $signature = 'model:optimistic-lock-add {model} {--namespace=}';

    #[Override]
    public $description = 'Add a migration to add optimistic locking columns to a model <fg=green>(⚡ Modules\Core)</fg=green>';

    #[Override]
    public function generateMigrationPath(Model $instance): string
    {
        return sprintf('_%s_optimistic_columns_to_%s.php', $this->operation, $instance->getTable());
    }

    /**
     * Return the stub file path.
     */
    #[Override]
    public function getStubPath(): string
    {
        return module_path('Core', sprintf('app/Locking/Stubs/%s_optimistic_lock_column_to_table.stub', $this->operation));
    }

    #[Override]
    protected function updateSettingsTable(string $table, bool $enabled): void
    {
        $key_name = PerModelSettingResolver::nameFor(CoreDatabaseSeeder::OPTIMISTIC_LOCK_NAME_PREFIX, $table);

        $setting = new Setting;
        $setting->newQuery()->insertOrIgnore([
            'name' => $key_name,
            'value' => json_encode($enabled),
            'type' => SettingTypeEnum::Boolean->value,
            'group_name' => 'locking',
            'description' => "Lock status for {$table}",
        ]);

        // A mass insert fires no model event: flush the settings table after its commit.
        app(CacheLabelInvalidator::class)->flushTable($setting->getTable(), $setting);
    }
}
