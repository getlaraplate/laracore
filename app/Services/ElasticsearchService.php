<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Illuminate\Support\Facades\Log;
use Modules\Core\Search\Exceptions\ElasticsearchException;
use RuntimeException;
use Throwable;

final class ElasticsearchService
{
    /**
     * Elasticsearch client instance.
     */
    public Client $client {
        get {
            return $this->client;
        }
    }

    /**
     * Singleton instance of the service.
     */
    private static ?self $instance = null;

    /**
     * Whether the server version was already asserted this process.
     */
    private static bool $version_asserted = false;

    /**
     * Create a new elasticsearch service instance.
     */
    private function __construct()
    {
        $this->client = resolve(Client::class);
    }

    /**
     * Get service instance (singleton pattern).
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Whether an Elasticsearch major version falls within the supported range.
     */
    public static function majorIsSupported(int $major, int $min, int $max): bool
    {
        return $major >= $min && $major <= $max;
    }

    /**
     * The Elasticsearch server version string (e.g. "8.19.21"), or null when it
     * cannot be read. Reading failures never throw, so a transient error does
     * not block callers.
     */
    public function serverVersion(): ?string
    {
        try {
            $version = $this->client->info()->asArray()['version']['number'] ?? null;

            return is_string($version) && $version !== '' ? $version : null;
        } catch (Throwable $e) {
            Log::error('Elasticsearch version read error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Reject, once per process, a cluster whose major version is outside the
     * supported range, with a message naming the actual and expected versions.
     * An unreadable version fails open rather than blocking on a transient error.
     *
     * @throws ElasticsearchException
     */
    public function assertSupportedVersion(): void
    {
        if (self::$version_asserted) {
            return;
        }

        $version = $this->serverVersion();

        if ($version === null) {
            return;
        }

        $major = (int) explode('.', $version)[0];
        $min = (int) config('elastic.client.supported_major.min', 8);
        $max = (int) config('elastic.client.supported_major.max', 8);

        if (! self::majorIsSupported($major, $min, $max)) {
            throw new ElasticsearchException(sprintf(
                'Unsupported Elasticsearch server version %s (major %d); this application supports major %s. Point ELASTIC_HOST at a supported cluster or adjust elastic.client.supported_major.',
                $version,
                $major,
                $min === $max ? (string) $min : sprintf('%d-%d', $min, $max),
            ));
        }

        self::$version_asserted = true;
    }

    /**
     * Create or update index.
     *
     * @param  string  $index  Index name
     * @param  array<string,mixed>  $settings  Index settings
     * @param  array<string,mixed>  $mappings  Index mappings
     *
     * @throws ElasticsearchException|MissingParameterException
     */
    public function createIndex(string $index, array $settings = [], array $mappings = []): bool
    {
        try {
            // Reject an unsupported cluster here, at the edge where a version
            // mismatch would otherwise surface as a cryptic mapping error.
            $this->assertSupportedVersion();

            // Check if the index already exists
            $exists = $this->client->indices()->exists(['index' => $index])->asBool();

            if ($exists) {
                // Update mappings and settings of existing index
                if ($mappings !== []) {
                    $this->client->indices()->putMapping([
                        'index' => $index,
                        'body' => $mappings,
                    ]);
                }

                if ($settings !== []) {
                    $this->client->indices()->putSettings([
                        'index' => $index,
                        'body' => ['settings' => $settings],
                    ]);
                }

                return true;
            }

            // Create a new index
            $params = ['index' => $index, 'body' => []];

            if ($settings !== []) {
                $params['body']['settings'] = $settings;
            }

            if ($mappings !== []) {
                $params['body']['mappings'] = $mappings;
            }

            $response = $this->client->indices()->create($params);

            return $response->asBool();
        } catch (ClientResponseException|ServerResponseException $e) {
            Log::error('Elasticsearch create index error', [
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Error creating index: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Delete index if exists.
     *
     * @param  string  $index  Index name
     *
     * @throws ElasticsearchException|MissingParameterException
     */
    public function deleteIndex(string $index): bool
    {
        try {
            // Check if the index exists
            $exists = $this->client->indices()->exists(['index' => $index])->asBool();

            if (! $exists) {
                return true;
            }

            // Delete the index
            $response = $this->client->indices()->delete(['index' => $index]);

            return $response->asBool();
        } catch (ClientResponseException|ServerResponseException $e) {
            Log::error('Elasticsearch delete index error', [
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Error deleting index: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Read the live field mapping (`properties`) of an index. Returns an empty
     * array when the index is missing or unreadable, so callers can treat an
     * empty result as "cannot validate" rather than "structure is wrong".
     *
     * @return array<string, mixed>
     */
    public function getMapping(string $index): array
    {
        try {
            $response = $this->client->indices()->getMapping(['index' => $index])->asArray();
            $properties = $response[$index]['mappings']['properties'] ?? null;

            return is_array($properties) ? $properties : [];
        } catch (Throwable $e) {
            Log::error('Elasticsearch get mapping error', [
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Bulk index documents.
     *
     * @param  string  $index  Index name
     * @param  array<string|int,array<string,mixed>>  $documents  Documents to index
     *
     * @throws ElasticsearchException
     *
     * @return array Response with success/error counts
     */
    public function bulkIndex(string $index, array $documents): array
    {
        if ($documents === []) {
            return ['indexed' => 0, 'failed' => 0, 'errors' => []];
        }

        $params = ['body' => []];
        $errors = [];

        // Prepare documents for bulk indexing
        foreach ($documents as $id => $document) {
            $params['body'][] = [
                'index' => [
                    '_index' => $index,
                    '_id' => $id,
                ],
            ];

            $params['body'][] = $document;
        }

        try {
            $response = $this->client->bulk($params);
            $result = $response->asArray();

            // Analyze results
            $indexed = 0;
            $failed = 0;

            if (isset($result['items'])) {
                foreach ($result['items'] as $item) {
                    if (isset($item['index']['status']) && $item['index']['status'] >= 200 && $item['index']['status'] < 300) {
                        $indexed++;
                    } else {
                        $failed++;
                        $errors[] = $item['index']['error'] ?? 'Unknown error';
                    }
                }
            }

            return [
                'indexed' => $indexed,
                'failed' => $failed,
                'errors' => $errors,
            ];
        } catch (ClientResponseException|ServerResponseException $e) {
            Log::error('Elasticsearch bulk index error', [
                'index' => $index,
                'documents_count' => count($documents),
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Error in bulk indexing: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Search documents.
     *
     * @param  string  $index  Index name
     * @param  array<string,mixed>  $query  Elasticsearch query
     *
     * @throws ElasticsearchException
     *
     * @return array Search results
     */
    public function search(string $index, array $query): array
    {
        try {
            $params = [
                'index' => $index,
                'body' => $query,
            ];

            $response = $this->client->search($params);

            return $response->asArray();
        } catch (ClientResponseException|ServerResponseException $e) {
            Log::error('Elasticsearch search error', [
                'index' => $index,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Search error: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Get document by ID.
     *
     * @param  string  $index  Index name
     * @param  string  $id  Document ID
     *
     * @throws ElasticsearchException
     *
     * @return array<string,mixed>|null Document data or null if not found
     */
    public function getDocument(string $index, string $id): ?array
    {
        try {
            $params = [
                'index' => $index,
                'id' => $id,
            ];

            $response = $this->client->get($params);

            // The client returns Elasticsearch|Promise because it also speaks async.
            // This service is synchronous throughout; a Promise here would mean the
            // client was built in a mode nothing in this application asks for.
            throw_unless($response instanceof Elasticsearch, RuntimeException::class,
                'Elasticsearch returned an asynchronous response, which this service does not support.');

            return $response->asArray();
        } catch (ClientResponseException $e) {
            // 404 is normal case, return null
            if ($e->getCode() === 404) {
                return null;
            }

            Log::error('Elasticsearch get document error', [
                'index' => $index,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Error retrieving document: ' . $e->getMessage(), $e->getCode(), $e);
        } catch (ServerResponseException $e) {
            Log::error('Elasticsearch get document error', [
                'index' => $index,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Error retrieving document: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Delete document by ID.
     *
     * @param  string  $index  Index name
     * @param  string|int  $id  Document ID
     * @param  bool  $refresh  Whether to refresh the index immediately
     *
     * @throws ElasticsearchException
     *
     * @return bool Success or failure
     */
    public function deleteDocument(string $index, string|int $id, bool $refresh = false): bool
    {
        try {
            // Check if the document exists
            $exists = $this->client->exists([
                'index' => $index,
                'id' => $id,
            ])->asBool();

            if (! $exists) {
                return true;
            }

            // Delete the document
            $params = [
                'index' => $index,
                'id' => $id,
            ];

            if ($refresh) {
                $params['refresh'] = 'true';
            }

            $response = $this->client->delete($params);

            return $response->asBool();
        } catch (ClientResponseException $e) {
            // 404 is not an error in this context
            if ($e->getCode() === 404) {
                return false;
            }

            Log::error('Elasticsearch delete document error', [
                'index' => $index,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Error deleting document: ' . $e->getMessage(), $e->getCode(), $e);
        } catch (ServerResponseException $e) {
            Log::error('Elasticsearch delete document error', [
                'index' => $index,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw new ElasticsearchException('Error deleting document: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
