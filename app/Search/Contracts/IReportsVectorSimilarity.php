<?php

declare(strict_types=1);

namespace Modules\Core\Search\Contracts;

use Modules\Core\Search\DTOs\VectorProfile;

/**
 * An engine that can tell the similarity behind the score of a pure vector hit, on the scale of the
 * profile's similarity function (the scale of {@see VectorProfile::$minSimilarity}). Each engine turns
 * the similarity into its own score; only the engine knows how to turn it back. An engine without this
 * capability gives no similarity, and a floor cannot be measured on it.
 */
interface IReportsVectorSimilarity
{
    /**
     * The similarity a vector hit scored `$score` had, or null when the scale is not known for the
     * profile's similarity function.
     */
    public function vectorSimilarity(float $score, VectorProfile $profile): ?float;
}
