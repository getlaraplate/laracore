<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Elastic\Client\ClientBuilder;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder as BaseClientBuilder;
use ErrorException;
use Override;

/**
 * The builder of the Elasticsearch clients of the Scout driver (`babenkoivan/elastic-client`), which the adapter
 * uses to create, update and delete indexes and the engine to search: the same connections, with
 * {@see ElasticsearchCompatibilityHeader} applied as on the client the container builds. Without it the driver built
 * its own clients, still announcing the client's major version, so with `ELASTIC_COMPATIBLE_WITH=8` a version 8
 * cluster accepted the service's requests and refused the driver's (`scout:rebuild` failed creating every index).
 */
final class ElasticClientBuilder extends ClientBuilder
{
    #[Override]
    public function connection(string $name): Client
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $config = config('elastic.client.connections.' . $name);

        if (! is_array($config)) {
            throw new ErrorException(sprintf('Configuration for connection %s is invalid or missing.', $name));
        }

        return $this->cache[$name] = BaseClientBuilder::fromConfig(ElasticsearchCompatibilityHeader::forConnection($config));
    }
}
