<?php

declare(strict_types=1);

namespace Modules\Core\Search\Console;

use function Laravel\Prompts\confirm;

use Exception;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Overrides\Command;
use Modules\Core\Search\Jobs\ReindexSearchJob;
use Modules\Core\Search\Services\SearchIndexInspector;
use Modules\Core\Search\Traits\Searchable;
use Modules\Core\Search\Traits\SearchableCommandUtils;
use Override;
use Symfony\Component\Console\Command\Command as BaseCommand;

final class CheckIndexCommand extends Command
{
    use SearchableCommandUtils;

    #[Override]
    protected $signature = 'scout:check-index {model? : The model to check}';

    #[Override]
    protected $description = 'Check indexes in Search Engine <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Execute the console command.
     */
    public function handle(SearchIndexInspector $inspector): int
    {
        try {
            $model = $this->getModelClass();

            if ($model) {
                $modeles = [$model];
            } else {
                $modeles = array_filter(models(), static fn (string $model): bool => in_array(Searchable::class, class_uses_recursive($model), true));
            }

            $missing_indexes = [];
            $structure_mismatches = [];

            foreach ($modeles as $model) {
                $this->info('Checking model ' . $model);
                $model_instance = new $model();

                if (! $model_instance instanceof ISearchableModel) {
                    continue;
                }

                $state = $inspector->inspect($model_instance);

                if (! $state->exists) {
                    $missing_indexes[] = $model;
                    $this->warn('Model ' . $model . ' has a wrong or missing index.');

                    continue;
                }

                $mismatches = $state->mismatches;

                if ($mismatches !== []) {
                    $structure_mismatches[] = $model;

                    $this->warn('Model ' . $model . ' has a stale index structure; the live mapping no longer matches the model schema:');

                    foreach ($mismatches as $mismatch) {
                        $this->warn('  - ' . $mismatch);
                    }

                    $this->warn('  A changed field type cannot be patched — recreate the index (drops and rebuilds it):');
                    $this->line(sprintf('  php artisan scout:delete-index "%s" && php artisan scout:index "%s"', $model, $model));
                    $this->warn('  If the only differences are new/missing fields, patch without dropping data instead:');
                    $this->line(sprintf('  php artisan scout:sync-mapping "%s"', $model));
                }
            }

            if ($missing_indexes === [] && $structure_mismatches === []) {
                $this->info('All models have the correct indexes.');

                return BaseCommand::SUCCESS;
            }

            if ($missing_indexes !== [] && confirm('Do you want to reindex the models with a missing index?')) {
                Bus::chain(
                    collect($missing_indexes)->map(static fn (string $model): object => new ReindexSearchJob($model)),
                )->dispatch();
                $this->info('Reindexing has been queued for the models with a missing index.');
            }

            if ($structure_mismatches !== []) {
                $this->error('Stale index structure detected for: ' . implode(', ', $structure_mismatches));

                return BaseCommand::FAILURE;
            }

            return BaseCommand::SUCCESS;
        } catch (Exception $exception) {
            Log::error('Error in elasticsearch:reindex command', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->error('An error occurred: ' . $exception->getMessage());

            return 1;
        }
    }
}
