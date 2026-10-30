<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Elastic\Elasticsearch\Response\Elasticsearch;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client answering every Elasticsearch request with the same `_count` body,
 * recording the requested URIs.
 */
final class CountingElasticsearchHttpClient implements ClientInterface
{
    /**
     * @var list<string>
     */
    public array $uris = [];

    public function __construct(private readonly int $count) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->uris[] = (string) $request->getUri();

        return new Response(
            200,
            [Elasticsearch::HEADER_CHECK => Elasticsearch::PRODUCT_NAME, 'Content-Type' => 'application/json'],
            (string) json_encode(['count' => $this->count]),
        );
    }
}
