<?php

declare(strict_types=1);

/**
 * ElasticsearchService tests.
 *
 * Do not assert ReflectionClass::isFinal(): tests/Pest.php enables DG\BypassFinals,
 * which reports final classes as non-final so Mockery can replace methods.
 */
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Response\Elasticsearch;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Search\Exceptions\ElasticsearchException;
use Modules\Core\Services\ElasticsearchService;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

beforeEach(function (): void {
    resetElasticsearchSingleton();
});

it('has proper class structure', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('getInstance'))->toBeTrue();
    expect($reflection->hasMethod('createIndex'))->toBeTrue();
    expect($reflection->hasMethod('search'))->toBeTrue();
});

it('has getInstance method as static', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);
    $method = $reflection->getMethod('getInstance');

    expect($method->isStatic())->toBeTrue();
    expect($method->isPublic())->toBeTrue();
});

it('has private constructor for singleton', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);
    $constructor = $reflection->getConstructor();

    expect($constructor->isPrivate())->toBeTrue();
});

it('uses the Elasticsearch client of the container, the single place that builds it', function (): void {
    resetElasticsearchSingleton();
    app()->forgetInstance(Client::class);
    config()->set('elastic.client.default', 'default');
    config()->set('elastic.client.connections.default', ['hosts' => ['http://127.0.0.1:9200']]);

    expect(ElasticsearchService::getInstance()->client)->toBe(app(Client::class));
});

it('builds singleton instance using configured client', function (): void {
    resetElasticsearchSingleton();
    app()->forgetInstance(Client::class);
    config()->set('elastic.client.default', 'default');
    config()->set('elastic.client.connections.default', [
        'hosts' => ['http://127.0.0.1:9200'],
    ]);

    $first = ElasticsearchService::getInstance();
    $second = ElasticsearchService::getInstance();

    expect($first)->toBeInstanceOf(ElasticsearchService::class);
    expect($first)->toBe($second);
});

it('has createIndex method with correct signature', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);
    $method = $reflection->getMethod('createIndex');

    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
});

it('has search method with correct signature', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);
    $method = $reflection->getMethod('search');

    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
});

it('has proper method visibility', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');
    $getInstanceMethod = $reflection->getMethod('getInstance');

    expect($createIndexMethod->isPublic())->toBeTrue();
    expect($searchMethod->isPublic())->toBeTrue();
    expect($getInstanceMethod->isPublic())->toBeTrue();
});

it('has consistent method signatures', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');

    expect($createIndexMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
    expect($searchMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
});

it('has proper class hierarchy', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->getName())->toBe(ElasticsearchService::class);
});

it('has required public methods', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    $methodNames = array_map(static fn ($method) => $method->getName(), $publicMethods);

    expect($methodNames)->toContain('createIndex');
    expect($methodNames)->toContain('search');
    expect($methodNames)->toContain('getInstance');
});

it('has proper namespace', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->getName())->toBe('Modules\Core\Services\ElasticsearchService');
});

it('has proper method accessibility', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');
    $getInstanceMethod = $reflection->getMethod('getInstance');

    expect($createIndexMethod->isPublic())->toBeTrue();
    expect($searchMethod->isPublic())->toBeTrue();
    expect($getInstanceMethod->isPublic())->toBeTrue();
});

it('has proper class structure for elasticsearch', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('createIndex'))->toBeTrue();
    expect($reflection->hasMethod('search'))->toBeTrue();
});

it('has proper method parameter types', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');

    expect($createIndexMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
    expect($searchMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
});

it('has proper class structure for singleton pattern', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('getInstance'))->toBeTrue();

    $constructor = $reflection->getConstructor();
    expect($constructor->isPrivate())->toBeTrue();
});

it('has proper method return types', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');

    expect($createIndexMethod->getReturnType())->not->toBeNull();
    expect($searchMethod->getReturnType())->not->toBeNull();
});

it('has proper class structure for service pattern', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('createIndex'))->toBeTrue();
    expect($reflection->hasMethod('search'))->toBeTrue();
});

it('has proper method signatures for elasticsearch', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');

    expect($createIndexMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
    expect($searchMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
});

it('has proper class structure for API integration', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('createIndex'))->toBeTrue();
    expect($reflection->hasMethod('search'))->toBeTrue();
});

it('has proper method structure for elasticsearch operations', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');

    expect($createIndexMethod->isPublic())->toBeTrue();
    expect($searchMethod->isPublic())->toBeTrue();
});

it('has proper class structure for singleton service', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('getInstance'))->toBeTrue();

    $constructor = $reflection->getConstructor();
    expect($constructor->isPrivate())->toBeTrue();
});

it('has proper method structure for API calls', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    $createIndexMethod = $reflection->getMethod('createIndex');
    $searchMethod = $reflection->getMethod('search');

    expect($createIndexMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
    expect($searchMethod->getNumberOfParameters())->toBeGreaterThanOrEqual(1);
});

it('has all required CRUD methods', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('createIndex'))->toBeTrue();
    expect($reflection->hasMethod('deleteIndex'))->toBeTrue();
    expect($reflection->hasMethod('getDocument'))->toBeTrue();
    expect($reflection->hasMethod('deleteDocument'))->toBeTrue();
});

it('has search and utility methods', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('search'))->toBeTrue();
});

it('has bulk operation methods', function (): void {
    $reflection = new ReflectionClass(ElasticsearchService::class);

    expect($reflection->hasMethod('bulkIndex'))->toBeTrue();
});

it('creates index when missing', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['acknowledged' => true])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->createIndex('my-index'))->toBeTrue();
});

it('creates index with settings and mappings when missing', function (): void {
    $mock_client = makeClientWithResponses([
        serverInfoResponse('8.15.0'),
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['acknowledged' => true])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->createIndex(
        'my-index',
        ['number_of_shards' => 1],
        ['properties' => ['foo' => ['type' => 'keyword']]],
    ))->toBeTrue();
});

it('updates mappings and settings when index exists', function (): void {
    $mock_client = makeClientWithResponses([
        serverInfoResponse('8.15.0'),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['acknowledged' => true])),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['acknowledged' => true])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->createIndex(
        'my-index',
        ['number_of_shards' => 1],
        ['properties' => ['foo' => ['type' => 'keyword']]],
    ))->toBeTrue();
});

it('returns true when index exists and no updates are provided', function (): void {
    $mock_client = makeClientWithResponses([
        serverInfoResponse('8.15.0'),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->createIndex('my-index'))->toBeTrue();
});

it('throws ElasticsearchException when createIndex fails', function (): void {
    $mock_client = makeClientWithResponses([
        serverInfoResponse('8.15.0'),
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(500, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'boom'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->createIndex('my-index');
})->throws(ElasticsearchException::class);

it('refuses to create an index on an unsupported Elasticsearch major', function (): void {
    $mock_client = makeClientWithResponses([
        serverInfoResponse('7.17.0'),
    ]);

    setElasticsearchInstance($mock_client);

    ElasticsearchService::getInstance()->createIndex('my-index');
})->throws(ElasticsearchException::class, 'Unsupported Elasticsearch server version 7.17.0');

it('fails open and creates the index when the server version cannot be read', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(500, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'boom'])),
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['acknowledged' => true])),
    ]);

    setElasticsearchInstance($mock_client);

    expect(ElasticsearchService::getInstance()->createIndex('my-index'))->toBeTrue();
});

it('deletes index gracefully if missing', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->deleteIndex('missing-index'))->toBeTrue();
});

it('deletes index when present', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['acknowledged' => true])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->deleteIndex('existing-index'))->toBeTrue();
});

it('throws ElasticsearchException when deleteIndex fails', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(500, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'boom'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->deleteIndex('existing-index');
})->throws(ElasticsearchException::class);

it('bulk indexes documents and counts successes/failures', function (): void {
    $bulk_response = [
        'items' => [
            ['index' => ['status' => 201]],
            ['index' => ['status' => 500, 'error' => 'boom']],
        ],
    ];

    $mock_client = makeClientWithResponses([
        new Response(200, [
            Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
            'Content-Type' => 'application/json',
        ], json_encode($bulk_response)),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $result = $service->bulkIndex('idx', [
        1 => ['foo' => 'bar'],
        2 => ['foo' => 'baz'],
    ]);

    expect($result)->toMatchArray([
        'indexed' => 1,
        'failed' => 1,
        'errors' => ['boom'],
    ]);
});

it('bulkIndex returns zero counters when items are missing in response', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(200, [
            Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
            'Content-Type' => 'application/json',
        ], json_encode(['took' => 1])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->bulkIndex('idx', [1 => ['foo' => 'bar']]))->toMatchArray([
        'indexed' => 0,
        'failed' => 0,
        'errors' => [],
    ]);
});

it('bulkIndex returns zeros for empty documents', function (): void {
    $mock_client = makeClientWithResponses([]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->bulkIndex('idx', []))->toMatchArray([
        'indexed' => 0,
        'failed' => 0,
        'errors' => [],
    ]);
});

it('throws ElasticsearchException when bulkIndex fails', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(500, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'boom'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->bulkIndex('idx', [1 => ['foo' => 'bar']]);
})->throws(ElasticsearchException::class);

it('searches and returns array payload', function (): void {
    $body = ['hits' => ['hits' => [['foo' => 'bar']]]];
    $mock_client = makeClientWithResponses([
        new Response(200, [
            Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
            'Content-Type' => 'application/json',
        ], json_encode($body)),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->search('idx', ['match_all' => (object) []]))->toBe($body);
});

it('throws ElasticsearchException when search fails', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(500, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'boom'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->search('idx', ['match_all' => (object) []]);
})->throws(ElasticsearchException::class);

it('returns null when document is not found', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->getDocument('idx', 'missing'))->toBeNull();
});

it('returns document payload when present', function (): void {
    $body = ['_id' => '1', '_source' => ['foo' => 'bar']];
    $mock_client = makeClientWithResponses([
        new Response(200, [
            Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME,
            'Content-Type' => 'application/json',
        ], json_encode($body)),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->getDocument('idx', '1'))->toBe($body);
});

it('throws ElasticsearchException when getDocument fails with client non-404', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(400, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'bad request'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->getDocument('idx', '1');
})->throws(ElasticsearchException::class);

it('throws ElasticsearchException when getDocument fails with non-404', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(500, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'boom'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->getDocument('idx', '1');
})->throws(ElasticsearchException::class);

it('deletes document when present and returns true', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['deleted' => true])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->deleteDocument('idx', '1'))->toBeTrue();
});

it('deleteDocument returns true when document does not exist', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->deleteDocument('idx', 999))->toBeTrue();
});

it('deleteDocument returns false when delete endpoint replies 404 after exists check', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(404, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    expect($service->deleteDocument('idx', 999))->toBeFalse();
});

it('throws ElasticsearchException when deleteDocument fails with client non-404', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(400, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'bad request'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->deleteDocument('idx', '1');
})->throws(ElasticsearchException::class);

it('throws ElasticsearchException when deleteDocument fails with server error', function (): void {
    $mock_client = makeClientWithResponses([
        new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]),
        new Response(500, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['error' => 'boom'])),
    ]);

    setElasticsearchInstance($mock_client);
    $service = ElasticsearchService::getInstance();

    $service->deleteDocument('idx', '1');
})->throws(ElasticsearchException::class);

it('deleteDocument passes refresh=true when requested', function (): void {
    $capture = new class
    {
        /**
         * @var array<int, array{method: string, uri: string}>
         */
        public array $items = [];
    };

    $http_client = new class($capture) implements ClientInterface
    {
        public function __construct(
            private readonly object $capture,
        ) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->capture->items[] = [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ];

            $path = (string) $request->getUri();

            if (str_contains($path, '/_doc/1') && str_contains($path, '_source')) {
                return new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME]);
            }

            if (str_contains($path, '/_doc/1')) {
                return new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['deleted' => true]));
            }

            return new Response(200, [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME], json_encode(['acknowledged' => true]));
        }
    };

    $client = ClientBuilder::create()
        ->setHttpClient($http_client)
        ->build();

    setElasticsearchInstance($client);
    $service = ElasticsearchService::getInstance();

    expect($service->deleteDocument('idx', 1, refresh: true))->toBeTrue();

    $delete_request = collect($capture->items)->first(fn (array $r): bool => $r['method'] === 'DELETE' && str_contains($r['uri'], '/_doc/1'));
    expect($delete_request)->not->toBeNull();
    expect((string) $delete_request['uri'])->toContain('refresh=true');
});

function makeClientWithResponses(array $responses): Client
{
    /**
     * Simple queue-based PSR-18 client that returns canned responses.
     */
    $http_client = new class implements ClientInterface
    {
        /**
         * @var array<int,ResponseInterface>
         */
        public array $responses;

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            if ($this->responses === []) {
                throw new RuntimeException('No more queued responses for Elasticsearch mock client.');
            }

            return array_shift($this->responses);
        }
    };

    $http_client->responses = $responses;

    return ClientBuilder::create()
        ->setHttpClient($http_client)
        ->build();
}

function setElasticsearchInstance(Client $client): ElasticsearchService
{
    $reflection = new ReflectionClass(ElasticsearchService::class);
    $instance = $reflection->newInstanceWithoutConstructor();

    $client_property = $reflection->getProperty('client');
    $client_property->setValue($instance, $client);

    $instance_property = $reflection->getProperty('instance');
    $instance_property->setValue(null, $instance);

    return $instance;
}

function resetElasticsearchSingleton(): void
{
    $reflection = new ReflectionClass(ElasticsearchService::class);
    $instance_property = $reflection->getProperty('instance');
    $instance_property->setValue(null, null);

    // The supported-version check runs once per process, so every test starts unchecked.
    $reflection->getProperty('version_asserted')->setValue(null, false);
}

/**
 * Response of the cluster info endpoint, which createIndex() reads to check the server major.
 */
function serverInfoResponse(string $version): Response
{
    return new Response(
        200,
        [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME, 'Content-Type' => 'application/json'],
        json_encode(['version' => ['number' => $version]]),
    );
}
