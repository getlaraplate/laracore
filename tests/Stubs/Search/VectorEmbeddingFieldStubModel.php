<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Override;

/**
 * Searchable model whose document carries a single `embedding` vector field, for asserting the schema
 * field Searchable::getSearchMapping() derives from it.
 */
final class VectorEmbeddingFieldStubModel extends VectorEmbeddingsArrayStubModel
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toSearchableArray(): array
    {
        return ['id' => 1, 'embedding' => [0.1, 0.2]];
    }
}
