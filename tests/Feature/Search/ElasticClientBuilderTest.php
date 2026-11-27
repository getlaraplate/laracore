<?php

declare(strict_types=1);

use Elastic\Client\ClientBuilderInterface;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Search\Support\ElasticClientBuilder;
use Psr\Http\Message\RequestInterface;

/*
 * The Scout driver builds its Elasticsearch clients through `ClientBuilderInterface` (index lifecycle, search), the
 * application through the container's client: both come from one builder, so `ELASTIC_COMPATIBLE_WITH` reaches both.
 * With the driver building its own, `scout:rebuild` against a version 8 cluster failed creating every index.
 */

beforeEach(function (): void {
    config([
        'elastic.client.default' => 'default',
        'elastic.client.connections.default' => ['hosts' => ['http://es.test:9200']],
    ]);
    app()->forgetInstance(ClientBuilderInterface::class);
    app()->forgetInstance(ElasticsearchClient::class);
});

/**
 * The major version the installed Elasticsearch client announces in its headers.
 */
function elastic_client_major(): int
{
    return (int) explode('.', ElasticsearchClient::VERSION)[0];
}

/**
 * The request a client sends for `info()`, answered by a mock handler put in place of the network at the bottom of
 * its Guzzle handler stack, so every middleware pushed on the stack still runs.
 */
function elastic_client_sent_request(ElasticsearchClient $client): RequestInterface
{
    $http = $client->getTransport()->getClient();

    expect($http)->toBeInstanceOf(GuzzleClient::class);

    $stack = $http->getConfig('handler');

    expect($stack)->toBeInstanceOf(HandlerStack::class);

    $mock = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json', 'X-Elastic-Product' => 'Elasticsearch'], '{}'),
    ]);
    $stack->setHandler($mock);

    $client->info();

    $request = $mock->getLastRequest();

    expect($request)->toBeInstanceOf(RequestInterface::class);

    return $request;
}

it('builds the clients of the scout driver and of the container with one builder', function (): void {
    expect(app(ClientBuilderInterface::class))->toBeInstanceOf(ElasticClientBuilder::class)
        ->and(app(ElasticsearchClient::class))->toBe(app(ClientBuilderInterface::class)->default());
});

it('rewrites the announced version on the scout driver client too when the option is set', function (): void {
    $announced = elastic_client_major() - 1;
    config(['elastic.client.compatible_with' => (string) $announced]);

    $request = elastic_client_sent_request(app(ClientBuilderInterface::class)->default());

    expect($request->getHeaderLine('Accept'))->toContain('compatible-with=' . $announced)
        ->not->toContain('compatible-with=' . elastic_client_major());
});

it('leaves the scout driver client alone when the option is not set', function (): void {
    config(['elastic.client.compatible_with' => null]);

    $request = elastic_client_sent_request(app(ClientBuilderInterface::class)->default());

    expect($request->getHeaderLine('Accept'))->toContain('compatible-with=' . elastic_client_major());
});
