<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Default binding: no embedding setup is known, so there is no vector profile.
 */
final readonly class NullVectorProfileProvider implements IVectorProfileProvider
{
    public function current(): ?VectorProfile
    {
        return null;
    }
}
