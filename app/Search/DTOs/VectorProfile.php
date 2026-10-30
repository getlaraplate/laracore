<?php

declare(strict_types=1);

namespace Modules\Core\Search\DTOs;

/**
 * The embedding setup vector retrieval runs against: the `provider:model` key stamped in
 * `core_model_embeddings.model_key`, the vector size and the similarity function.
 */
final readonly class VectorProfile
{
    public function __construct(
        public string $modelKey,
        public int $dimensions,
        public string $similarity,
    ) {}
}
