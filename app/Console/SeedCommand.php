<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Database\Seeders\DevDatabaseSeeder;
use Illuminate\Database\Console\Seeds\SeedCommand as BaseSeedCommand;
use Modules\Core\Console\Concerns\BenchmarksSeedingRun;
use Modules\Core\Console\Concerns\ResolvesDevSeedScale;
use Override;
use Symfony\Component\Console\Input\InputOption;

final class SeedCommand extends BaseSeedCommand
{
    use BenchmarksSeedingRun;
    use ResolvesDevSeedScale;

    #[Override]
    protected $description = 'Seed the database with records. <fg=green>(⚡ Modules\Core)</fg=green>';

    #[Override]
    public function handle(): int
    {
        return $this->benchmarkSeedingRun($this->seed(...));
    }

    /**
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string, 4?: mixed}>
     */
    #[Override]
    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['dev', null, InputOption::VALUE_NONE, 'Seed the database with development data'],
            ['resume', null, InputOption::VALUE_NONE, 'Skip nodes that succeeded in the last failed run'],
        ], $this->devSeedScaleOptions());
    }

    private function seed(): int
    {
        if ($this->option('dev')) {
            // Resolve the scale once, here, from the real invocation flags and
            // publish it through the container: the db:seed command is a shared
            // container instance whose input is rebound by the nested
            // module:seed -> db:seed calls the dev seeders make, so a seeder
            // reading $this->command->option('min') later would read that
            // mutated input, not the flags the operator actually passed.
            return $this->withPublishedDevSeedScale(
                fn (): int => (int) $this->call('db:seed', ['--class' => DevDatabaseSeeder::class]),
            );
        }

        return parent::handle();
    }
}
