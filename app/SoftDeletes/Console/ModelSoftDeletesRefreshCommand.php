<?php

declare(strict_types=1);

namespace Modules\Core\SoftDeletes\Console;

use function class_uses_trait;
use function Laravel\Prompts\confirm;
use function models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Command;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\SoftDeletes\SoftDeletes;
use Modules\Core\SoftDeletes\SoftDeletesSettingConfirmation;
use Override;
use ReflectionClass;

final class ModelSoftDeletesRefreshCommand extends Command
{
    #[Override]
    protected $signature = 'model:soft-deletes-refresh';

    #[Override]
    protected $description = 'Check and reconcile SoftDeletes consistency (trait, schema and runtime setting) across models. <fg=green>(⚡ Modules\Core)</fg=green>';

    private bool $quiet_mode = false;

    private bool $changes = false;

    public function handle(): void
    {
        $this->quiet_mode = (bool) $this->option('quiet');
        $this->changes = false;

        foreach (models() as $model_class) {
            $this->checkModel($model_class);
        }

        if (! $this->changes && ! $this->quiet_mode) {
            $this->info('No SoftDeletes changes needed.');
        }
    }

    private function checkModel(string $model_class): void
    {
        if (! class_exists($model_class)) {
            return;
        }

        $instance = new ReflectionClass($model_class)->newInstance();

        if (! $instance instanceof Model) {
            return;
        }

        $table = $instance->getTable();
        $schema = $instance->getConnection()->getSchemaBuilder();
        $has_trait = class_uses_trait($model_class, SoftDeletes::class);
        $has_deleted_at = $schema->hasTable($table) && $schema->hasColumn($table, 'deleted_at');
        $has_is_deleted = $schema->hasTable($table) && $schema->hasColumn($table, 'is_deleted');
        $has_columns = $has_deleted_at && $has_is_deleted;

        if ($has_trait && ! $has_columns) {
            $this->askAndRun(
                sprintf('Model %s uses SoftDeletes but schema columns are missing. Create them?', $model_class),
                'model:soft-deletes-add',
                $model_class,
            );
        } elseif (! $has_trait && ($has_deleted_at || $has_is_deleted)) {
            $this->askAndRun(
                sprintf('Model %s does not use SoftDeletes but soft-delete columns exist. Remove support?', $model_class),
                'model:soft-deletes-remove',
                $model_class,
            );
        }

        $this->reconcileSetting($table, $has_trait);
    }

    private function askAndRun(string $question, string $command, string $model_class): void
    {
        if (! confirm($question, false)) {
            if (! $this->quiet_mode) {
                $this->line(sprintf("Ignoring '%s'.", $model_class));
            }

            return;
        }

        $this->call($command, ['model' => $model_class]);
        $this->changes = true;
    }

    private function reconcileSetting(string $table, bool $has_trait): void
    {
        $setting_model = new Setting;
        $settings_table = $setting_model->getTable();

        if (! $setting_model->getConnection()->getSchemaBuilder()->hasTable($settings_table)) {
            return;
        }

        $name = PerModelSettingResolver::nameFor(CoreDatabaseSeeder::SOFT_DELETES_NAME_PREFIX, $table);
        $setting = $setting_model->newQuery()->where('name', $name)->first();

        if ($setting === null) {
            if ($has_trait && confirm("Create missing runtime setting {$name} = true?", false)) {
                $setting_model->newQuery()->create([
                    'name' => $name,
                    'value' => true,
                    'encrypted' => false,
                    'choices' => [true, false],
                    'type' => 'boolean',
                    'group_name' => 'soft_deletes',
                    'description' => "Enable soft deletes for {$table}",
                ]);
                $this->changes = true;
            }

            return;
        }

        $current = (bool) $setting->value;

        if ($current === $has_trait) {
            return;
        }

        // Switching soft deletes off deletes the rows in the bin for good (SettingObserver queues the purge): the same
        // warning as the settings page comes first, and a refusal changes nothing.
        $warning = app(SoftDeletesSettingConfirmation::class)->warn($setting, $has_trait);

        foreach ($warning === null ? [] : $warning->lines as $line) {
            $this->warn($line);
        }

        if (! confirm(sprintf('Runtime setting %s=%s differs from trait state (%s). Align setting now?', $name, $current ? 'true' : 'false', $has_trait ? 'true' : 'false'), false)) {
            return;
        }

        $setting->update(['value' => $has_trait]);
        $this->changes = true;
    }
}
