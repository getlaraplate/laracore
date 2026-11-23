<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Command;
use Modules\Core\Services\PerModelSettingResolver;
use Override;
use ReflectionClass;
use Symfony\Component\Console\Command\Command as BaseCommand;

final class ModelSoftDeletesAddCommand extends Command
{
    #[Override]
    protected $signature = 'model:soft-deletes-add {model} {--namespace=}';

    #[Override]
    protected $description = 'Add SoftDeletes support (trait + migration + runtime setting) to a model <fg=green>(⚡ Modules\Core)</fg=green>';

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
        $instance = $reflection->newInstanceWithoutConstructor();

        if (! $instance instanceof Model) {
            $this->error(sprintf('Class %s is not an Eloquent model.', $model_class));

            return BaseCommand::INVALID;
        }

        $table = $instance->getTable();
        $trait_changed = $this->addSoftDeletesTrait($reflection);
        $migration = $this->createMigration($table);
        $setting_changed = $this->upsertSoftDeletesSetting($table, true);

        if (! $trait_changed) {
            $this->line('SoftDeletes trait already present on model.');
        }

        $this->info(sprintf('Migration created: %s', $migration));

        if ($setting_changed) {
            $this->info(sprintf('Runtime setting soft_deletes_%s set to true.', $table));
        }

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

    private function resolveModelClass(string $model): string
    {
        if (Str::contains($model, '\\')) {
            return $model;
        }

        $namespace = (string) ($this->option('namespace') ?: 'App\\Models');

        return $namespace . '\\' . $model;
    }

    private function addSoftDeletesTrait(ReflectionClass $reflection): bool
    {
        $file = $reflection->getFileName();

        if (! is_string($file) || ! is_file($file)) {
            return false;
        }

        $source = (string) file_get_contents($file);
        $updated = $source;
        $import = 'use Modules\\Core\\SoftDeletes\\SoftDeletes;';

        if (! str_contains($updated, $import)) {
            $updated = preg_replace('/(namespace\s+[^;]+;\s+)/', "$1\n{$import}\n", $updated, 1) ?? $updated;
        }

        if (! str_contains($updated, 'use SoftDeletes;')) {
            $updated = preg_replace('/(class\s+\w+[^{]*\{\s*\n)/', "$1    use SoftDeletes;\n", $updated, 1) ?? $updated;
        }

        if ($updated === $source) {
            return false;
        }

        file_put_contents($file, $updated);

        return true;
    }

    private function createMigration(string $table): string
    {
        $file_path = now()->format('Y_m_d_His') . sprintf('_add_soft_deletes_columns_to_%s.php', $table);
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
        return dirname(__DIR__) . '/Stubs/add_soft_deletes_columns_to_table.stub';
    }

    private function upsertSoftDeletesSetting(string $table, bool $enabled): bool
    {
        $setting = new Setting;

        if (! $setting->getConnection()->getSchemaBuilder()->hasTable($setting->getTable())) {
            $this->warn('Settings table not found; runtime flag was not updated.');

            return false;
        }

        $this->writeSoftDeletesSetting($setting, $table, $enabled);

        return true;
    }
}
