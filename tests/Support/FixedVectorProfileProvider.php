<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Provider that always answers the profile it was built with.
 */
final readonly class FixedVectorProfileProvider implements IVectorProfileProvider
{
    public function __construct(private ?VectorProfile $profile) {}

    public function current(): ?VectorProfile
    {
        return $this->profile;
    }
}
