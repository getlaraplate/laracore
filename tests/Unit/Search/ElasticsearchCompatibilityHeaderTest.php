<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\Core\Search\Support\ElasticsearchCompatibilityHeader;

const ES_JSON_9 = 'application/vnd.elasticsearch+json; compatible-with=9';

it('reads the major version of an option, ignoring anything that is not a positive integer', function (mixed $value, ?int $expected): void {
    expect(ElasticsearchCompatibilityHeader::majorOf($value))->toBe($expected);
})->with([
    'a number' => [8, 8],
    'a string of digits, as an environment value' => ['8', 8],
    'null' => [null, null],
    'an empty string' => ['', null],
    'zero' => [0, null],
    'a negative number' => [-8, null],
    'a version string' => ['8.19', null],
    'text' => ['yes', null],
    'a float' => [8.5, null],
]);

it('rewrites the version of Accept and Content-Type and leaves the other headers alone', function (): void {
    $request = new Request('POST', 'https://es.test/_bulk', [
        'Accept' => ES_JSON_9,
        'Content-Type' => 'application/vnd.elasticsearch+x-ndjson; compatible-with=9',
        'Authorization' => 'ApiKey secret',
        'X-Elastic-Client-Meta' => 'es=9.5.0',
    ]);

    $rewritten = ElasticsearchCompatibilityHeader::rewrite($request, 8);

    expect($rewritten->getHeaderLine('Accept'))->toBe('application/vnd.elasticsearch+json; compatible-with=8')
        ->and($rewritten->getHeaderLine('Content-Type'))->toBe('application/vnd.elasticsearch+x-ndjson; compatible-with=8')
        ->and($rewritten->getHeaderLine('Authorization'))->toBe('ApiKey secret')
        ->and($rewritten->getHeaderLine('X-Elastic-Client-Meta'))->toBe('es=9.5.0')
        ->and($request->getHeaderLine('Accept'))->toBe(ES_JSON_9);
});

it('leaves a request without a version in its headers as it is', function (): void {
    $request = new Request('GET', 'https://es.test/', ['Accept' => 'application/json']);

    expect(ElasticsearchCompatibilityHeader::rewrite($request, 8)->getHeaders())->toBe($request->getHeaders());
});

it('rewrites the header of the requests that go through the Guzzle options it builds', function (): void {
    $mock = new MockHandler([new Response(200)]);
    $client = new Client(ElasticsearchCompatibilityHeader::applyTo(['timeout' => 7], 8, $mock));

    $client->send(new Request('GET', 'https://es.test/', ['Accept' => ES_JSON_9]));

    expect($mock->getLastRequest()?->getHeaderLine('Accept'))->toBe('application/vnd.elasticsearch+json; compatible-with=8')
        ->and($client->getConfig('timeout'))->toBe(7);
});

it('changes no option when no version is given', function (): void {
    $options = ['timeout' => 7];

    expect(ElasticsearchCompatibilityHeader::applyTo($options, null))->toBe($options);
});
