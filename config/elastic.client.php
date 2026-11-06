<?php

declare(strict_types=1);

return [
    'default' => env('ELASTIC_CONNECTION', 'default'),
    'connections' => [
        'default' => array_filter([
            'hosts' => [
                env('ELASTIC_HOST', 'localhost:9200'),
            ],
            'retries' => (int) env('ELASTIC_RETRIES', 3),
            'sslVerification' => env('ELASTIC_SSL_VERIFICATION', true),
            'httpClientOptions' => [
                'timeout' => (int) env('ELASTIC_TIMEOUT', 60),
            ],
            // Include basicAuthentication only if username is set
            'basicAuthentication' => (env('ELASTIC_USERNAME') !== null && env('ELASTIC_USERNAME') !== '')
                ? [env('ELASTIC_USERNAME'), env('ELASTIC_PASSWORD', '')]
                : null,
            // Include apiKey only if api_key is set
            'apiKey' => (env('ELASTIC_API_KEY') !== null && env('ELASTIC_API_KEY') !== '')
                ? [env('ELASTIC_API_KEY'), env('ELASTIC_API_KEY_ID', '')]
                : null,
        ], static fn ($value) => $value !== null),
    ],
    'retry_on_conflict' => env('ELASTIC_RETRY_ON_CONFLICT', 3),
    'bulk_size' => env('ELASTIC_BULK_SIZE', 500),

    // HACK, TESTING ONLY, NOT SUPPORTED. The client of this stack (elasticsearch/elasticsearch 9) announces
    // `compatible-with=9` on every request, and a server of major 8 refuses it with a 400. Set this to a
    // major number (8) to rewrite that header on the way out, so the client can be tried against a
    // cluster of the previous major. Only the header changes: the requests are still those of the version 9
    // client, and nothing guarantees that indexing and searching work, now or after a client update.
    // Never set it in production: use a server and a client of the same major. Empty leaves the header alone.
    'compatible_with' => env('ELASTIC_COMPATIBLE_WITH'),

    // Supported Elasticsearch server major-version range. A cluster outside it is
    // rejected early (ElasticsearchService::assertSupportedVersion) instead of
    // surfacing as a late mapping/query error. The stack targets Elasticsearch 8;
    // widen the range consciously when moving to a new major.
    'supported_major' => [
        'min' => (int) env('ELASTIC_SUPPORTED_MAJOR_MIN', 8),
        'max' => (int) env('ELASTIC_SUPPORTED_MAJOR_MAX', 8),
    ],
];
