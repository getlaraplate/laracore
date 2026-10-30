<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Search\Traits\Searchable;

/**
 * Throwaway searchable model for asserting that ElasticsearchEngine::createIndex
 * applies an explicit `dense_vector` embedding mapping to a dedicated index
 * (never the real application indices).
 */
class VectorMappingStubModel extends Model implements ISearchableModel
{
    use Searchable;

    public const string INDEX = 'core_test_vector_mapping';

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
                    'embedding' => [
                        'type' => 'dense_vector',
                        'dims' => VectorProfileContext::get()?->dimensions,
                        'index' => true,
                        'similarity' => 'cosine',
                    ],
                ],
            ],
        ];
    }
}
