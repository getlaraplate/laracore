<?php

declare(strict_types=1);

namespace Modules\Core\Locking\Console;

use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PerModelSettingResolver;
use Override;

final class LockedRemoveCommand extends LockedAddCommand
{
    #[Override]
    public $signature = 'model:locked-remove {model} {--namespace=}';

    #[Override]
    public $description = 'Add a migration to remove locked columns to a model <fg=green>(⚡ Modules\Core)</fg=green>';

    #[Override]
    protected string $operation = 'remove';

    #[Override]
    protected function updateSettingsTable(string $table, bool $enabled): void
    {
        $key_name = PerModelSettingResolver::nameFor(CoreDatabaseSeeder::LOCK_NAME_PREFIX, $table);

        $setting = new Setting;
        $setting->newQuery()->where('name', $key_name)->forceDelete();

        // A mass delete fires no model event: flush the settings table after its commit.
        app(CacheLabelInvalidator::class)->flushTable($setting->getTable(), $setting);
    }
}
