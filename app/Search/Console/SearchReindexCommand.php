<?php

declare(strict_types=1);

namespace Modules\Core\Search\Console;

use function in_array;

use Exception;
use Illuminate\Support\Facades\Log;
use Modules\Core\Search\Jobs\ReindexSearchJob;
use Modules\Core\Search\Traits\SearchableCommandUtils;
use Override;
use Symfony\Component\Console\Command\Command;

final class SearchReindexCommand extends \Modules\Core\Overrides\Command
{
    use SearchableCommandUtils;

    #[Override]
    protected $signature = 'scout:reindex {model? : The model to reindex}';

    #[Override]
    protected $description = 'Reindex documents in Search Engine <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(): int
    {
        try {
            $model = $this->getModelClass();

            if (in_array($model, ['', '0', false], true)) {
                return Command::INVALID;
            }

            $this->input->setArgument('model', $model);
            $this->info('Dispatching reindex job for model ' . $model);
            dispatch(new ReindexSearchJob($model));
            $this->info('Reindexing has been queued for model ' . $model);

            return Command::SUCCESS;
        } catch (Exception $exception) {
            Log::error('Error in scout:reindex command', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->error('An error occurred: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }
}
