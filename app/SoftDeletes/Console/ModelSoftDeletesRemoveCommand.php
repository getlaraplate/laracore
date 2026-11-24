<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes\Console;

use function Laravel\Prompts\confirm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Command;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\SoftDeletes\TrashedRowsPurger;
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
        $purger = app(TrashedRowsPurger::class);
        $soft_deleted_count = $purger->count($instance);

        if ($soft_deleted_count > 0) {
            $this->warn(sprintf('Found %d logical-deleted records on `%s`.', $soft_deleted_count, $table));

            // A refusal changes nothing: no record, trait, migration or setting.
            if (! confirm('Purge those records with hard delete before removing soft delete columns?', false)) {
                $this->warn('Operation cancelled. No columns were removed.');

                return BaseCommand::FAILURE;
            }

            $this->info(sprintf('Purged %d record(s).', $purger->purge($instance)));
        }

        $trait_changed = $this->removeSoftDeletesTrait($reflection);
        $migration = $this->createMigration($table);

        if (! $trait_changed) {
            $this->line('SoftDeletes trait not found on model (skipped trait removal).');
        }

        $this->info(sprintf('Migration created: %s', $migration));

        if ($this->deleteSoftDeletesSetting($table)) {
            $this->info(sprintf('Runtime setting %s deleted.', PerModelSettingResolver::nameFor(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $table)));
        }

        $this->info('Done. Review the generated migration and run `php artisan migrate`.');

        return BaseCommand::SUCCESS;
    }

    /**
     * Deletes the runtime flag of the table: a flag left for a feature the model no longer has would let an
     * administrator switch on something that does nothing. Returns whether a row was deleted.
     */
    private function deleteSoftDeletesSetting(string $table): bool
    {
        $setting = new Setting;

        if (! $setting->getConnection()->getSchemaBuilder()->hasTable($setting->getTable())) {
            $this->warn('Settings table not found; runtime flag was not deleted.');

            return false;
        }

        $deleted = $setting->newQuery()->where('name', PerModelSettingResolver::nameFor(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $table))->forceDelete();

        // A mass delete fires no model event: flush the settings table after its commit.
        app(CacheLabelInvalidator::class)->flushTable($setting->getTable(), $setting);

        return $deleted > 0;
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
}
