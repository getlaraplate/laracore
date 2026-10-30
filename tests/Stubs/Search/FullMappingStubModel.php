<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Search\Traits\Searchable;

/**
 * Throwaway searchable model for asserting that ElasticsearchEngine::createIndex
 * pushes the FULL translated mapping (not just the top-level `embedding` field)
 * at index creation: a `title` locale-object with per-language analyzers, an
 * `embeddings` nested `dense_vector` sub-field, and a `tags` nested relation
 * field carrying `meta`/`index` — shaped exactly like
 * ElasticsearchTranslator::translateField() would produce them.
 *
 * The `tags` field reproduces the cutover blocker: ES rejects `meta`/`index` on
 * `nested`/`object` types, so createIndex must strip them before applying the
 * mapping, or the whole request fails with a 400 mapper_parsing_exception.
 */
class FullMappingStubModel extends Model implements ISearchableModel
{
    use Searchable;

    public const string INDEX = 'core_test_full_mapping';

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
                    'title' => [
                        'type' => 'object',
                        'properties' => [
                            'it' => ['type' => 'text', 'analyzer' => 'italian'],
                            'en' => ['type' => 'text', 'analyzer' => 'english'],
                        ],
                    ],
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
                    'tags' => [
                        'type' => 'nested',
                        'properties' => [
                            'name' => ['type' => 'keyword'],
                            'id' => ['type' => 'integer', 'index' => true, 'meta' => ['filterable' => true]],
                        ],
                        'index' => true,
                        'meta' => ['relation' => 'tags', 'filterable' => true],
                    ],
                ],
            ],
        ];
    }
}
