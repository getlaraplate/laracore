<?php

declare(strict_types=1);

use Elastic\Client\ClientBuilderInterface;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use GuzzleHttp\Client as GuzzleClient;
use Modules\Core\Search\Support\ElasticClientBuilder;

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
 * The description of the Guzzle handler stack of a client: it names the middleware pushed on it.
 */
function elastic_client_handlers(ElasticsearchClient $client): string
{
    $http = $client->getTransport()->getClient();

    expect($http)->toBeInstanceOf(GuzzleClient::class);

    return (string) $http->getConfig('handler');
}

it('builds the clients of the scout driver and of the container with one builder', function (): void {
    expect(app(ClientBuilderInterface::class))->toBeInstanceOf(ElasticClientBuilder::class)
        ->and(app(ElasticsearchClient::class))->toBe(app(ClientBuilderInterface::class)->default());
});

it('rewrites the announced version on the scout driver client too when the option is set', function (): void {
    config(['elastic.client.compatible_with' => '8']);

    expect(elastic_client_handlers(app(ClientBuilderInterface::class)->default()))->toContain('elasticsearch-compatibility-header');
});

it('leaves the scout driver client alone when the option is not set', function (): void {
    config(['elastic.client.compatible_with' => null]);

    expect(elastic_client_handlers(app(ClientBuilderInterface::class)->default()))->not->toContain('elasticsearch-compatibility-header');
});
