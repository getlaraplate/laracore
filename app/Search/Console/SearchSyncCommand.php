<?php

declare(strict_types=1);

namespace Modules\Core\Search\Console;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Laravel\Prompts\Progress;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Helpers\ModelKey;
use Modules\Core\Overrides\Command;
use Modules\Core\Overrides\CustomSoftDeletingScope;
use Override;

final class SearchSyncCommand extends Command
{
    #[Override]
    protected $signature = 'scout:sync {model : The model to sync} {--id= : The ID of the document to sync} {--from= : The date to sync from}';

    #[Override]
    protected $description = 'Sync documents modified after the last indexing in Search Engine <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(): int
    {
        try {
            $model_class = $this->argument('model');
            $id = $this->option('id');
            $from = $this->option('from');

            if ($from && $id) {
                $this->error('You must specify either --id or --from option, but not both.');

                return 1;
            }

            if (! class_exists($model_class)) {
                $this->error(sprintf('Model class %s does not exist.', $model_class));

                return 1;
            }

            if (! is_a($model_class, ISearchableModel::class, true)) {
                $this->error(sprintf('Model class %s does not use the Searchable trait.', $model_class));

                return 1;
            }

            // The records `scout:import` indexes, trashed ones included, so a record trashed since the last indexing
            // leaves the index.
            $model = $model_class::makeAllSearchableQuery();

            if (class_uses_trait($model_class, SoftDeletes::class)) {
                $model->withoutGlobalScopes([SoftDeletingScope::class, CustomSoftDeletingScope::class]);
            }

            if ($id) {
                $model->where('id', $id);
            } elseif ($from) {
                $model->where('updated_at', '>', Date::parse($from));
            } else {
                // An index that cannot tell when it was last written gets every record.
                $last_indexed = (new $model_class())->getLastIndexedTimestamp();

                if ($last_indexed !== null) {
                    $model->where('updated_at', '>', $last_indexed);
                }
            }

            $total = $model->count();

            if ($total === 0) {
                $this->info('No documents need to be synced.');

                return 0;
            }

            $this->info(sprintf('Found %s documents to sync.', $total));
            $total_chunks = ceil($total / 100);
            $chunk = 0;
            $model->chunk(100, function (Collection $records) use ($total_chunks, &$chunk): void {
                $chunk++;
                $progress = new Progress(
                    'Syncing documents chunk ' . $chunk . ' of ' . $total_chunks,
                    $records->map(static fn (Model $record): string => 'Record ' . ModelKey::of($record))->toArray(),
                );

                foreach ($records as $record) {
                    // As Scout's observer does on delete: a trashed record leaves the index unless the index keeps
                    // trashed documents flagged.
                    if (method_exists($record, 'trashed') && $record->trashed() && ! config('scout.soft_delete', false)) {
                        $record->unsearchable();
                    } else {
                        $record->searchable();
                    }
                    $progress->advance();
                }

                $progress->finish();
                $this->newLine();

                // Force garbage collection after each chunk to free memory
                unset($records);
                gc_collect_cycles();
            });
            $this->info('Documents have been queued for indexing.');

            return 0;
        } catch (Exception $exception) {
            Log::error('Error in model:sync command', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->error('An error occurred: ' . $exception->getMessage());

            return 1;
        }
    }
}
