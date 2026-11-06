<?php

declare(strict_types=1);

namespace Modules\Core\Search\Jobs;

use Elastic\Elasticsearch\Client;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Core\Search\Exceptions\ReindexException;
use Throwable;

final class FinalizeReindexJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1; // Non vogliamo retry automatici per questo job

    public int $timeout = 300; // 5 minuti per finalizzare

    public int $maxExceptionsThenWait = 60;

    public function __construct(
        private readonly string $index_name,
        private readonly string $temp_index,
        private readonly string $reindex_key,
    ) {
        $this->onQueue('indexing');
    }

    public function middleware(): array
    {
        return [
            new ThrottlesExceptions(10, 5),
            new RateLimited('indexing'),
        ];
    }

    public function handle(): void
    {
        try {
            $elasticsearch_client = resolve(Client::class);

            // Verifichiamo che l'indice temporaneo esista
            throw_unless($elasticsearch_client->indices()->exists(['index' => $this->temp_index]), ReindexException::class, sprintf('Temporary index %s does not exist', $this->temp_index));

            // Se l'indice originale esiste, lo eliminiamo
            if ($elasticsearch_client->indices()->exists(['index' => $this->index_name])) {
                $elasticsearch_client->indices()->delete(['index' => $this->index_name]);
            }

            // Rinominiamo l'indice temporaneo
            $elasticsearch_client->indices()->putAlias([
                'index' => $this->temp_index,
                'name' => $this->index_name,
            ]);

            // Rimuoviamo il flag di reindicizzazione
            Cache::forget($this->reindex_key);

            Log::info('Elasticsearch reindexing completed', [
                'index' => $this->index_name,
                'temp_index' => $this->temp_index,
            ]);
        } catch (Exception $exception) {
            Log::error('Elasticsearch index finalization failed', [
                'index' => $this->index_name,
                'temp_index' => $this->temp_index,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        // Rimuoviamo il flag di reindicizzazione anche in caso di fallimento
        Cache::forget($this->reindex_key);

        Log::error('FinalizeReindexJob failed', [
            'index' => $this->index_name,
            'temp_index' => $this->temp_index,
            'error' => $exception->getMessage(),
        ]);
    }
}
