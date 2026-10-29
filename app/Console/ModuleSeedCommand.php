<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Support\Str;
use Modules\Core\Console\Concerns\BenchmarksSeedingRun;
use Modules\Core\Console\Concerns\ResolvesDevSeedScale;
use Nwidart\Modules\Commands\Database\SeedCommand as BaseModuleSeedCommand;
use Nwidart\Modules\Module;
use Override;
use Symfony\Component\Console\Input\InputOption;

/**
 * Overrides nwidart's `module:seed` to add a `--dev` mode that runs a module's
 * `Dev{Module}DatabaseSeeder` (fake bulk data), honouring the same
 * `--micro` / `--min` / `--mid` / `--max` volume flags as `db:seed --dev`.
 */
final class ModuleSeedCommand extends BaseModuleSeedCommand
{
    use BenchmarksSeedingRun;
    use ResolvesDevSeedScale;

    #[Override]
    public function handle(): int
    {
        return $this->benchmarkSeedingRun(fn (): int => (int) parent::handle());
    }

    #[Override]
    public function moduleSeed(Module $module): void
    {
        if (! $this->option('dev')) {
            parent::moduleSeed($module);

            return;
        }

        $dev_seeder = $this->devSeederClass($module->getName());

        if (! class_exists($dev_seeder)) {
            $this->components->warn("No development seeder found for module [{$module->getName()}] ({$dev_seeder}).");

            return;
        }

        $this->withPublishedDevSeedScale(function () use ($dev_seeder): int {
            $parameters = ['--class' => $dev_seeder];

            if ($this->option('force')) {
                $parameters['--force'] = true;
            }

            return (int) $this->call('db:seed', $parameters);
        });
    }

    /**
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string, 4?: mixed}>
     */
    #[Override]
    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['dev', null, InputOption::VALUE_NONE, 'Run the module development seeder (fake data) instead of the production seeder'],
        ], $this->devSeedScaleOptions());
    }

    /**
     * Derive the module's development seeder class from its production one:
     * `Modules\Core\Database\Seeders\CoreDatabaseSeeder`
     * -> `Modules\Core\Database\Seeders\DevCoreDatabaseSeeder`.
     */
    private function devSeederClass(string $moduleName): string
    {
        $studly = Str::studly($moduleName);

        // Mirror the segment normalisation nwidart's moduleSeed() applies: the
        // configured seeder path is lowercase (database\seeders), but the PSR-4
        // namespace is StudlyCase (Database\Seeders).
        $production = implode('\\', array_map('ucwords', explode('\\', $this->getSeederName($moduleName))));

        return Str::replaceLast(
            $studly . 'DatabaseSeeder',
            'Dev' . $studly . 'DatabaseSeeder',
            $production,
        );
    }
}
