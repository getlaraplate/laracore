<?php

declare(strict_types=1);

namespace Modules\Core\Search\Console;

use function in_array;

use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Modules\Core\Console\Concerns\HasBenchmark;
use Modules\Core\Search\Traits\SearchableCommandUtils;
use Override;
use Symfony\Component\Console\Command\Command;

final class IndexCommand extends \Laravel\Scout\Console\IndexCommand
{
    use HasBenchmark;
    use SearchableCommandUtils;

    #[Override]
    protected $signature = 'scout:index {model? : The model to create an index for}';

    #[Override]
    protected $description = 'Create an index <fg=green>(⚡ Modules\Core)</fg=green>';

    #[Override]
    public function handle(EngineManager $manager): int
    {
        try {
            $model = $this->getModelClass();

            if (in_array($model, ['', '0', false], true)) {
                return Command::INVALID;
            }

            $this->addArgument('name');
            $this->input->setArgument('name', $model);
            $this->addOption('key');

            parent::handle($manager);

            return Command::SUCCESS;
        } catch (Exception $exception) {
            Log::error('Error in scout:index command', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->error('An error occurred: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }

    #[Override]
    protected function createIndex(Engine $engine, mixed $name, mixed $options): void
    {
        $model = $this->argument('model');
        $options = new $model()->getSearchMapping();
        parent::createIndex($engine, $name, $options);
    }
}
