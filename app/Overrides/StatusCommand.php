<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Illuminate\Database\Console\Migrations\StatusCommand as BaseStatusCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Stringable;
use Modules\Core\Helpers\MigrationModuleResolver;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'migrate:status')]
class StatusCommand extends BaseStatusCommand
{
    #[Override]
    protected $description = 'Show the status of each migration <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Handle the command.
     */
    public function handle() // @pest-ignore-type
    {
        return $this->migrator->usingConnection($this->option('database'), function () {
            if (! $this->migrator->repositoryExists()) {
                $this->components->error('Migration table not found.');

                return 1;
            }

            $ran = $this->migrator->getRepository()->getRan();

            $batches = $this->migrator->getRepository()->getMigrationBatches();

            $migrations = $this->getStatusFor($ran, $batches)
                ->when($this->option('pending') !== false, fn (Collection $collection): Collection => $collection->filter(static function (array $migration): bool {
                    return (new Stringable($migration[1]))->contains('Pending');
                }));

            if (count($migrations) > 0) {
                $this->newLine();

                $this->components->twoColumnDetail('<fg=gray>Migration name</>', '<fg=gray>Module / Batch / Status</>');

                $migrations
                    ->each(
                        function (array $migration): void {
                            $this->components->twoColumnDetail($migration[0], $migration[1]);
                        },
                    );

                $this->newLine();
            } elseif ($this->option('pending') !== false) {
                $this->components->info('No pending migrations');
            } else {
                $this->components->info('No migrations found');
            }

            if ($this->option('pending') && $migrations->some(static fn (array $m): bool => (new Stringable($m[1]))->contains('Pending'))) {
                return (int) $this->option('pending');
            }
        });
    }

    /**
     * @param  array<int, string>  $ran
     * @param  array<string, int>  $batches
     * @return Collection<int, array{0: string, 1: string}>
     */
    #[Override]
    protected function getStatusFor(array $ran, array $batches): Collection
    {
        return (new Collection($this->getAllMigrationFiles()))
            ->map(function (string $migration_path) use ($ran, $batches): array {
                $migration_name = $this->migrator->getMigrationName($migration_path);
                $module = MigrationModuleResolver::resolveFromPath($migration_path);

                $status = in_array($migration_name, $ran, true)
                    ? '<fg=green;options=bold>Ran</>'
                    : '<fg=yellow;options=bold>Pending</>';

                if (in_array($migration_name, $ran, true)) {
                    $status = '[' . $batches[$migration_name] . '] ' . $status;
                }

                $status = sprintf('<fg=cyan>%s</> %s', $module, $status);

                return [$migration_name, $status];
            });
    }
}
