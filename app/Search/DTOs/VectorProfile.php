<?php

declare(strict_types=1);

namespace Modules\Core\Search\DTOs;

/**
 * The embedding setup vector retrieval runs against: the `provider:model` key stamped in
 * `core_model_embeddings.model_key`, the vector size, the similarity function and, optionally,
 * the similarity floor.
 *
 * The floor (`minSimilarity`) is the lowest similarity a vector hit needs to count, on the scale of
 * the similarity function itself, which is what an Elasticsearch kNN `similarity` expects: the cosine
 * for `cosine`, the dot product of unit vectors for `dot_product`. It is not the engine `_score`
 * (Elasticsearch reports `(1 + cosine) / 2` for a cosine hit). Null means no floor. It belongs to the
 * profile because score scales change with the model, so a floor left over from another model would
 * cut silently.
 */
final readonly class VectorProfile
{
    /**
     * The similarity functions that grow as vectors get closer, where a minimum is meaningful. `l2_norm`
     * is a distance: a "minimum similarity" there would be a maximum distance, so it takes no floor.
     *
     * @var list<string>
     */
    private const array FLOORED_SIMILARITIES = ['cosine', 'dot_product'];

    public function __construct(
        public string $modelKey,
        public int $dimensions,
        public string $similarity,
        public ?float $minSimilarity = null,
    ) {}

    /**
     * The floor the engines apply: the profile's `minSimilarity` when its similarity function takes
     * one, null otherwise (no floor is safer than a cut on the wrong scale).
     */
    public function similarityFloor(): ?float
    {
        return in_array($this->similarity, self::FLOORED_SIMILARITIES, true) ? $this->minSimilarity : null;
    }
}
