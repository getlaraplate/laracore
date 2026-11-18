<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes\Console;

use function Laravel\Prompts\confirm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Concerns\HasClosureTable;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Command;
use Modules\Core\Services\PerModelSettingResolver;
use Override;
use ReflectionClass;
use Symfony\Component\Console\Command\Command as BaseCommand;

final class ModelSoftDeletesRemoveCommand extends Command
{
    #[Override]
    protected $signature = 'model:soft-deletes-remove {model} {--namespace=}';

    #[Override]
    protected $description = 'Remove SoftDeletes support (trait + migration + runtime setting) from a model. <fg=green>(⚡ Modules\Core)</fg=green>';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $model_class = $this->resolveModelClass((string) $this->argument('model'));

        if ($model_class === null || ! class_exists($model_class)) {
            $this->error(sprintf('Model %s does not exist.', (string) $this->argument('model')));

            return BaseCommand::INVALID;
        }

        $reflection = new ReflectionClass($model_class);
        $instance = $this->instantiateModel($reflection);

        if ($instance === null) {
            $this->error(sprintf('Class %s is not an Eloquent model.', $model_class));

            return BaseCommand::INVALID;
        }

        $table = $instance->getTable();
        $soft_deleted_count = $this->countSoftDeletedRows($instance);

        if ($soft_deleted_count > 0) {
            $this->warn(sprintf('Found %d logical-deleted records on `%s`.', $soft_deleted_count, $table));

            if (! confirm('Purge those records with hard delete before removing soft delete columns?', false)) {
                $this->warn('Operation cancelled. No columns were removed.');

                return BaseCommand::FAILURE;
            }

            $purged = $this->purgeSoftDeletedRows($instance);
            $this->info(sprintf('Purged %d record(s).', $purged));
        }

        $trait_changed = $this->removeSoftDeletesTrait($reflection);
        $migration = $this->createMigration($table);
        $setting_changed = $this->upsertSoftDeletesSetting($table, false);

        if (! $trait_changed) {
            $this->line('SoftDeletes trait not found on model (skipped trait removal).');
        }

        $this->info(sprintf('Migration created: %s', $migration));

        if ($setting_changed) {
            $this->info(sprintf('Runtime setting soft_deletes_%s set to false.', $table));
        }

        $this->updateSettingsTable($table);

        $this->info('Done. Review the generated migration and run `php artisan migrate`.');

        return BaseCommand::SUCCESS;
    }

    /**
     * Persist the runtime flag, bypassing the approval workflow: this runs from
     * a maintenance command, so the change must apply immediately instead of
     * queueing a modification nobody is going to approve.
     */
    private function writeSoftDeletesSetting(Setting $prototype, string $table, bool $enabled): void
    {
        $setting = $prototype->newQuery()->firstOrNew([
            'name' => PerModelSettingResolver::nameFor(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $table),
        ]);

        $setting->setForcedApprovalUpdate(true);
        $setting->setSkipValidation(true);
        $setting->forceFill([
            'value' => $enabled,
            'encrypted' => false,
            'choices' => [true, false],
            'type' => 'boolean',
            'group_name' => 'soft_deletes',
            'description' => "Enable soft deletes for {$table}",
        ])->save();
    }

    private function updateSettingsTable(string $table): void
    {
        $key_name = PerModelSettingResolver::nameFor(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $table);

        Setting::query()->where('name', $key_name)->forceDelete();
    }

    private function resolveModelClass(string $model): string
    {
        if (Str::contains($model, '\\')) {
            return $model;
        }

        $namespace = (string) ($this->option('namespace') ?: 'App\\Models');

        return $namespace . '\\' . $model;
    }

    private function instantiateModel(ReflectionClass $reflection): ?Model
    {
        if (! $reflection->isSubclassOf(Model::class)) {
            return null;
        }

        $instance = $reflection->newInstance();

        return $instance instanceof Model ? $instance : null;
    }

    /**
     * Hard delete the trashed rows. The delete bypasses model events, so a
     * closure table would keep stale links and is rebuilt from parent_id.
     */
    private function purgeSoftDeletedRows(Model $model): int
    {
        $purged = $model->getConnection()->table($model->getTable())->whereNotNull('deleted_at')->delete();

        if ($purged > 0 && in_array(HasClosureTable::class, class_uses_recursive($model), true)) {
            $model::rebuildClosure();
        }

        return $purged;
    }

    private function countSoftDeletedRows(Model $model): int
    {
        $table = $model->getTable();
        $schema = $model->getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'deleted_at')) {
            return 0;
        }

        return (int) $model->getConnection()->table($table)->whereNotNull('deleted_at')->count();
    }

    private function removeSoftDeletesTrait(ReflectionClass $reflection): bool
    {
        $file = $reflection->getFileName();

        if (! is_string($file) || ! is_file($file)) {
            return false;
        }

        $source = (string) file_get_contents($file);
        $updated = str_replace(
            ["use Modules\\Core\\SoftDeletes\\SoftDeletes;\n", "    use SoftDeletes;\n"],
            '',
            $source,
        );

        if ($updated === $source) {
            return false;
        }

        file_put_contents($file, $updated);

        return true;
    }

    private function createMigration(string $table): string
    {
        $file_path = now()->format('Y_m_d_His') . sprintf('_remove_soft_deletes_columns_from_%s.php', $table);
        $path = App::databasePath('migrations/' . $file_path);
        $contents = $this->getStubContents($this->getStubPath(), [
            'ModelTable' => $table,
        ]);

        if (! $this->files->exists($path)) {
            $this->files->put($path, $contents);
        }

        return $path;
    }

    private function getStubContents(string $stub, array $variables): string
    {
        $contents = (string) file_get_contents($stub);

        foreach ($variables as $key => $value) {
            $contents = str_replace('$' . $key . '$', (string) $value, $contents);
        }

        return $contents;
    }

    private function getStubPath(): string
    {
        return dirname(__DIR__) . '/Stubs/remove_soft_deletes_columns_from_table.stub';
    }

    private function upsertSoftDeletesSetting(string $table, bool $enabled): bool
    {
        $settings = new Setting;
        $settings_table = $settings->getTable();

        if (! $settings->getConnection()->getSchemaBuilder()->hasTable($settings_table)) {
            $this->warn('Settings table not found; runtime flag was not updated.');

            return false;
        }

        $this->writeSoftDeletesSetting($settings, $table, $enabled);

        return true;
    }
}
