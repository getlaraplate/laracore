<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Database\Seeders\DevDatabaseSeeder;
use Illuminate\Database\ConnectionResolverInterface as Resolver;
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

    public function __construct(Resolver $resolver)
    {
        parent::__construct($resolver);

        /**
         * Laravel 13 declares `db:seed` in `$signature`, which bypasses `getOptions()` entirely. Overriding
         * that method, as this command did on Laravel 12, left `--dev`, `--resume` and every scale flag
         * unregistered, and `$this->option('dev')` threw "The \"dev\" option does not exist" on 174 tests.
         * Adding them to the resolved definition works whichever mechanism the parent uses.
         */
        foreach ($this->customOptions() as [$name, $shortcut, $mode, $description]) {
            $this->getDefinition()->addOption(new InputOption($name, $shortcut, $mode, $description));
        }
    }

    #[Override]
    public function handle(): int
    {
        return $this->benchmarkSeedingRun($this->seed(...));
    }

    /**
     * The options this command adds to the framework's own.
     *
     * @return array<int, array{0: string, 1: string|null, 2: int, 3: string}>
     */
    private function customOptions(): array
    {
        return array_merge([
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
