<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Search\Traits\Searchable;

/**
 * Throwaway searchable model exposing a nested `embeddings.vector` dense_vector
 * field plus a top-level `locales` keyword array, for asserting that
 * ElasticsearchEngine::performVectorSearch() runs kNN over the nested vector
 * field (ordering by similarity) and applies an optional document-level
 * `locales` filter to the knn query without filtering individual vectors.
 */
class NestedVectorLocaleStubModel extends Model implements ISearchableModel
{
    use Searchable;

    public const string INDEX = 'core_test_nested_vector_locale';

    public function searchableAs(): string
    {
        return self::INDEX;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(): array
    {
        return [
            'mappings' => [
                'properties' => [
                    'embeddings' => [
                        'type' => 'nested',
                        'properties' => [
                            'vector' => [
                                'type' => 'dense_vector',
                                'dims' => VectorProfileContext::get()?->dimensions,
                                'index' => true,
                                'similarity' => 'cosine',
                            ],
                        ],
                    ],
                    'locales' => [
                        'type' => 'keyword',
                    ],
                ],
            ],
        ];
    }
}
