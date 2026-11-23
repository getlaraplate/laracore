<?php

declare(strict_types=1);

namespace Modules\Core\Search\Console;

use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Console\QueueImportCommand as BaseQueueImportCommand;
use Modules\Core\Search\Traits\SearchableCommandUtils;
use Override;
use Symfony\Component\Console\Command\Command;

final class QueueImportCommand extends BaseQueueImportCommand
{
    use SearchableCommandUtils;

    #[Override()]
    protected $signature = 'scout:queue-import 
            {model? : The model to reindex}
            {--min= : The minimum ID to start queuing from}
            {--max= : The maximum ID to queue up to}
            {--c|chunk= : The number of records to queue in a single job (Defaults to configuration value: `scout.chunk.searchable`)}
            {--queue= : The queue that should be used (Defaults to configuration value: `scout.queue.queue`)}';

    #[Override]
    protected $description = 'Import the given model into the search index via chunked, queued jobs <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(): int
    {
        try {
            $model = $this->getModelClass();

            if (in_array($model, ['', '0', false], true)) {
                return Command::INVALID;
            }

            $this->input->setArgument('model', $model);

            parent::handle();

            return Command::SUCCESS;
        } catch (Exception $exception) {
            Log::error('Error in scout:queue-import command', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->error('An error occurred: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }
}
