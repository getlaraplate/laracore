<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\VectorAvailability;
use Override;

/**
 * Guard stand-in answering `suspended`, the reason a decorating module (AI, during an embedding model
 * switch) adds on top of Core's own `disabled` and `dimension_mismatch`.
 */
final class SuspendedVectorSearchAvailabilityStub implements IVectorSearchAvailability
{
    #[Override]
    public function check(Model $model): VectorAvailability
    {
        return VectorAvailability::no('suspended');
    }
}
