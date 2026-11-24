<?php

declare(strict_types=1);

namespace Modules\Core\Locking\Console;

use function Laravel\Prompts\confirm;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Locking\LockedRowsReleaser;
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

    /**
     * Locked records keep their lock columns until the migration drops them, and a worker still running the class with
     * the trait honours them: they are released first, after a confirmation. A refusal changes nothing: no lock,
     * migration or setting.
     */
    #[Override]
    protected function beforeChanges(Model $instance): bool
    {
        $releaser = app(LockedRowsReleaser::class);
        $table = $instance->getTable();
        $locked = $releaser->countFor($table);

        if ($locked === 0) {
            return true;
        }

        $this->warn(sprintf('Found %d locked records on `%s`.', $locked, $table));

        if (! confirm('Unlock those records before removing the lock columns?', false)) {
            $this->warn('Operation cancelled. No columns were removed.');

            return false;
        }

        $this->info(sprintf('Unlocked %d record(s).', $releaser->release($table)));

        return true;
    }

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
