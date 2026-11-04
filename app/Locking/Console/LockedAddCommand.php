<?php

declare(strict_types=1);

namespace Modules\Core\Locking\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Command;
use Modules\Core\Services\PerModelSettingResolver;
use Override;
use ReflectionClass;
use Symfony\Component\Console\Command\Command as BaseCommand;

/**
 * Derived from sfolador/laravel-locked (MIT), see NOTICES/laravel-locked.md.
 */
class LockedAddCommand extends Command
{
    #[Override]
    public $signature = 'model:locked-add {model} {--namespace=}';

    #[Override]
    public $description = 'Add a migration to add locked columns to a model <fg=green>(⚡ Modules\Core)</fg=green>';

    protected string $operation = 'add';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $model = $this->argument('model');

        // $namespace = config('locked.default_namespace');
        if (Str::contains($model, '\\')) {
            $namespace = Str::replaceMatches('/\\\\\w+$/', '', $model);
        } else {
            $namespace = 'App\\Models';
        }

        if ($this->option('namespace')) {
            $namespace = $this->option('namespace');
        }

        $className = $namespace . '\\' . $model;

        if (! class_exists($className)) {
            $this->error(sprintf('Model %s does not exist', $className));

            return BaseCommand::INVALID;
        }

        $instance = new ReflectionClass($className)->newInstanceWithoutConstructor();
        assert($instance instanceof Model);

        $table = $instance->getTable();
        $module = class_module($instance);

        if ($module !== 'App') {
            $enum_class = "Modules\\{$module}\\Enums\\{$module}Tables";

            if (class_exists($enum_class)) {
                $enum_case = $enum_class::tryFrom($table)?->name;

                if ($enum_case) {
                    $table = "{$enum_class}::{$enum_case}->value";
                }
            }
        }

        if (! Str::contains($table, '\\')) {
            $table = "'{$table}'";
        }

        $fileContents = $this->getStubContents($this->getStubPath(), [
            'ModelTable' => $table,
        ]);

        $filePath = now()->format('Y_m_d_His') . $this->generateMigrationPath($instance);
        $directory = $this->migrationDirectoryForNamespace($namespace);

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . $filePath;

        if (! $this->files->exists($path)) {
            $this->files->put($path, $fileContents);
        } else {
            $this->info(sprintf('File : %s already exists', $path));
        }

        $this->updateSettingsTable($instance->getTable(), true);

        $this->info('Done. Review the generated migration and run `php artisan migrate`.');

        return BaseCommand::SUCCESS;
    }

    public function generateMigrationPath(Model $instance): string
    {
        return sprintf('_%s_locked_columns_to_%s.php', $this->operation, $instance->getTable());
    }

    /**
     * Replace the stub variables(key) with the desire value.
     *
     * @param  array<string, string>  $stubVariables
     */
    public function getStubContents(string $stub, array $stubVariables = []): string
    {
        $contents = (string) file_get_contents($stub);

        foreach ($stubVariables as $search => $replace) {
            $contents = str_replace('$' . $search . '$', $replace, $contents);
        }

        return $contents;
    }

    /**
     * Return the stub file path.
     */
    public function getStubPath(): string
    {
        return module_path('Core', sprintf('app/Locking/Stubs/%s_locked_column_to_table.stub', $this->operation));
    }

    protected function updateSettingsTable(string $table, bool $enabled): void
    {
        $key_name = PerModelSettingResolver::nameFor(CoreDatabaseSeeder::LOCK_NAME_PREFIX, $table);

        Setting::query()->updateOrCreate(
            ['name' => $key_name],
            [
                'name' => $key_name,
                'value' => $enabled,
                'encrypted' => false,
                'type' => SettingTypeEnum::Boolean,
                'group_name' => 'locking',
                'description' => "Lock status for {$table}",
            ],
        );
    }

    /**
     * The migration must live in the same module as the model. When the model
     * is not modular (e.g. App\Models\*), it falls back to the app migrations.
     */
    private function migrationDirectoryForNamespace(string $namespace): string
    {
        $segments = explode('\\', $namespace);

        if (($segments[0] ?? null) === 'Modules' && isset($segments[1]) && $segments[1] !== '') {
            return module_path($segments[1], 'database/migrations');
        }

        return App::databasePath('migrations');
    }
}
