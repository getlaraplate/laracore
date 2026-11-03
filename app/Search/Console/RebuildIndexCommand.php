<?php

declare(strict_types=1);

namespace Modules\Core\Search\Console;

use function in_array;
use function Laravel\Prompts\confirm;

use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Exceptions\NotSupportedException;
use Modules\Core\Cache\HasCache;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Overrides\Command;
use Modules\Core\Search\Contracts\ISearchMaintenanceGuard;
use Modules\Core\Search\DTOs\SearchIndexState;
use Modules\Core\Search\Jobs\ReindexSearchJob;
use Modules\Core\Search\Services\SearchIndexInspector;
use Modules\Core\Search\Traits\SearchableCommandUtils;
use Override;
use Symfony\Component\Console\Command\Command as BaseCommand;

/**
 * Brings search indexes back in line with their models in one run.
 *
 * Per model: read the state the way `scout:check-index` does; leave an index in sync alone; else
 * patch the mapping (`scout:sync-mapping`) and reindex (`scout:reindex`, run here and not queued)
 * and read the state again. A model is failed when the mapping cannot be patched, the reindex
 * throws, or the index is still out of sync afterwards. The failed models are then rebuilt
 * (`scout:delete-index`, `scout:index`, `scout:import`) after a single confirmation, because their
 * search returns nothing from the delete until the import ends.
 */
final class RebuildIndexCommand extends Command
{
    use SearchableCommandUtils;

    private const string SKIPPED = 'skipped';

    private const string SYNCED = 'synced';

    private const string REINDEXED = 'reindexed';

    private const string QUEUED = 'queued (unverified)';

    private const string REBUILT = 'rebuilt';

    private const string FAILED = 'failed';

    private const string OUT_OF_SYNC = 'out of sync (dry run)';

    #[Override]
    protected $signature = 'scout:rebuild
            {model? : The model to rebuild}
            {--dry-run : Only report what would be done, change nothing}
            {--force : Rebuild the indexes that cannot be patched without asking}
            {--queue : Queue the reindex instead of running it; its result is then not verified}';

    #[Override]
    protected $description = 'Bring search indexes back in sync: sync the mapping and reindex, recreate only the indexes that cannot be patched <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(SearchIndexInspector $inspector, ISearchMaintenanceGuard $guard): int
    {
        try {
            $blocked_reason = $guard->blockedReason();

            if ($blocked_reason !== null) {
                $this->error('Search index maintenance is blocked: ' . $blocked_reason);

                return BaseCommand::FAILURE;
            }

            $models = $this->modelsToRebuild();

            if ($models === null) {
                return BaseCommand::FAILURE;
            }

            $dry_run = (bool) $this->option('dry-run');
            $queue = (bool) $this->option('queue');

            /** @var array<string, string> $outcomes */
            $outcomes = [];

            /** @var list<ISearchableModel> $failed */
            $failed = [];

            foreach ($models as $model_class) {
                $model = new $model_class();

                if (! $model instanceof ISearchableModel) {
                    continue;
                }

                $outcome = $this->repairInPlace($model, $inspector, $dry_run, $queue);
                $outcomes[$model_class] = $outcome;

                if ($outcome === self::FAILED || $outcome === self::OUT_OF_SYNC) {
                    $failed[] = $model;
                }
            }

            if ($dry_run) {
                $this->describeDryRun($failed);
                $this->printOutcomes($outcomes);
                $this->printEmbeddingsNotice();

                return BaseCommand::SUCCESS;
            }

            if ($failed !== [] && $this->confirmRebuild($failed)) {
                foreach ($failed as $model) {
                    $outcomes[$model::class] = $this->rebuild($model, $inspector);
                }
            }

            $this->printOutcomes($outcomes);
            $this->printEmbeddingsNotice();

            return in_array(self::FAILED, $outcomes, true) ? BaseCommand::FAILURE : BaseCommand::SUCCESS;
        } catch (Exception $exception) {
            Log::error('Error in scout:rebuild command', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->error('An error occurred (' . $exception::class . '); see the log for details.');

            return BaseCommand::FAILURE;
        }
    }

    /**
     * The model given as argument, or every searchable model when none is given; null when the
     * argument names no searchable model.
     *
     * @return list<string>|null
     */
    private function modelsToRebuild(): ?array
    {
        $argument = $this->argument('model');

        if (! is_string($argument) || $argument === '') {
            // Filtered by the contract, like scout:sync-mapping.
            return array_values(array_filter(models(), static fn (string $model): bool => is_a($model, ISearchableModel::class, true)));
        }

        $model = $this->getModelClass();

        return $model === false ? null : [$model];
    }

    /**
     * The non-destructive path: sync the mapping, reindex, check again.
     */
    private function repairInPlace(ISearchableModel $model, SearchIndexInspector $inspector, bool $dry_run, bool $queue): string
    {
        $model_class = $model::class;
        $state = $inspector->inspect($model);

        if (! $state->managed) {
            $this->line(sprintf('Skipped %s: its search engine keeps no index of its own.', $model_class));

            return self::SKIPPED;
        }

        if ($state->isInSync()) {
            $this->line(sprintf('Skipped %s: its index is already in sync.', $model_class));

            return self::SKIPPED;
        }

        $this->warn(sprintf('Model %s is out of sync:', $model_class));
        $this->describeState($state);

        $engine = $model->searchableUsing();

        if ($dry_run) {
            $this->line(sprintf(
                '  Would %sreindex %s now%s, then check the index again.',
                method_exists($engine, 'syncMapping') ? 'sync the mapping, ' : '',
                $model_class,
                $queue ? ' (queued, unverified)' : '',
            ));

            return self::OUT_OF_SYNC;
        }

        $mapping_synced = false;

        if (method_exists($engine, 'syncMapping')) {
            if (! (bool) $engine->syncMapping($model)) {
                $this->warn(sprintf('  Could not sync the mapping of %s: a changed field type cannot be patched.', $model_class));

                return self::FAILED;
            }

            $mapping_synced = true;
            $this->info(sprintf('  Mapping synced for %s.', $model_class));
        }

        if ($queue) {
            dispatch(new ReindexSearchJob($model_class));
            $this->invalidateCache($model);
            $this->warn(sprintf('  Reindex of %s queued; its result is not verified.', $model_class));

            return self::QUEUED;
        }

        try {
            $model::reindex();
        } catch (Exception $exception) {
            $this->logFailure('reindex', $model_class, $exception);
            $this->warn(sprintf('  Reindex of %s failed (%s); see the log for details.', $model_class, $exception::class));

            return self::FAILED;
        }

        $this->invalidateCache($model);

        $after = $inspector->inspect($model);

        if (! $after->isInSync()) {
            $this->warn(sprintf('  %s is still out of sync after the reindex:', $model_class));
            $this->describeState($after);

            return self::FAILED;
        }

        $this->info(sprintf('  %s reindexed and in sync.', $model_class));

        return $mapping_synced ? self::SYNCED : self::REINDEXED;
    }

    /**
     * Lists the models to rebuild and asks once for all of them.
     *
     * @param  list<ISearchableModel>  $failed
     */
    private function confirmRebuild(array $failed): bool
    {
        $this->newLine();
        $this->warn('These indexes cannot be repaired in place and must be rebuilt (delete, create, import):');
        $this->listRebuildTargets($failed);
        $this->warn('Search on these models returns nothing from the delete of their index until their import ends.');

        if ((bool) $this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Not rebuilding without confirmation in a non-interactive run: pass --force to rebuild.');

            return false;
        }

        if (! confirm('Delete, recreate and re-import the indexes listed above?', false)) {
            $this->warn('Rebuild declined: nothing was deleted.');

            return false;
        }

        return true;
    }

    /**
     * The destructive path: delete the index, create it from the model's mapping, import every
     * record, check again.
     */
    private function rebuild(ISearchableModel $model, SearchIndexInspector $inspector): string
    {
        $model_class = $model::class;
        $engine = $model->searchableUsing();
        $index = $this->indexName($model);

        try {
            if ($inspector->inspect($model)->exists) {
                $engine->deleteIndex($index);
                $this->info(sprintf('Index "%s" deleted.', $index));
            }

            try {
                $engine->createIndex($index, method_exists($model, 'getSearchMapping') ? $model->getSearchMapping() : []);
                $this->info(sprintf('Index "%s" created.', $index));
            } catch (NotSupportedException) {
                // Engines that create indexes on first write, as scout:index tolerates.
            }

            // Searchable::reindex() is makeAllSearchable(), the scan scout:import runs.
            $model::reindex();
            $this->info(sprintf('All [%s] records have been imported.', $model_class));
        } catch (Exception $exception) {
            $this->logFailure('rebuild', $model_class, $exception);
            $this->warn(sprintf('Rebuild of %s failed (%s); see the log for details.', $model_class, $exception::class));

            return self::FAILED;
        }

        $this->invalidateCache($model);

        $after = $inspector->inspect($model);

        if (! $after->isInSync()) {
            $this->warn(sprintf('%s is still out of sync after the rebuild:', $model_class));
            $this->describeState($after);

            return self::FAILED;
        }

        return self::REBUILT;
    }

    /**
     * @param  list<ISearchableModel>  $failed
     */
    private function describeDryRun(array $failed): void
    {
        if ($failed === []) {
            return;
        }

        $this->newLine();
        $this->warn('Dry run: for the models above still out of sync after the reindex, the run would ask once, unless --force, to rebuild (delete, create, import):');
        $this->listRebuildTargets($failed);
        $this->warn('Search on a rebuilt model returns nothing from the delete of its index until its import ends.');
        $this->info('Nothing was changed.');
    }

    /**
     * @param  list<ISearchableModel>  $models
     */
    private function listRebuildTargets(array $models): void
    {
        foreach ($models as $model) {
            $this->line(sprintf(
                '  - %s: index "%s", %d records to import',
                $model::class,
                $this->indexName($model),
                $model::makeAllSearchableQuery()->reorder()->count(),
            ));
        }
    }

    private function describeState(SearchIndexState $state): void
    {
        if (! $state->exists) {
            $this->warn('  - the index is missing');

            return;
        }

        foreach ($state->mismatches as $mismatch) {
            $this->warn('  - ' . $mismatch);
        }
    }

    /**
     * @param  array<string, string>  $outcomes
     */
    private function printOutcomes(array $outcomes): void
    {
        if ($outcomes === []) {
            $this->info('No searchable models found.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Model', 'Outcome'],
            array_map(static fn (string $model, string $outcome): array => [$model, $outcome], array_keys($outcomes), $outcomes),
        );
    }

    private function printEmbeddingsNotice(): void
    {
        $this->newLine();
        $this->line('Embeddings are not touched: they live in database rows, not in the search index.');
        $this->line('Content whose embedding text changed is embedded again by the bulk reindex only when the embeddings feature is on and a queue worker runs; ai:embeddings:repair does not detect such changes.');
    }

    private function indexName(ISearchableModel $model): string
    {
        return method_exists($model, 'indexableAs') ? (string) $model->indexableAs() : (string) $model->searchableAs();
    }

    private function invalidateCache(ISearchableModel $model): void
    {
        if (in_array(HasCache::class, class_uses_recursive($model), true) && method_exists($model, 'invalidateCache')) {
            $model->invalidateCache();
        }
    }

    private function logFailure(string $step, string $model_class, Exception $exception): void
    {
        Log::error(sprintf('scout:rebuild %s failed', $step), [
            'model' => $model_class,
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
