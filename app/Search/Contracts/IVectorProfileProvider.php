<?php

declare(strict_types=1);

namespace Modules\Core\Search\Contracts;

use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Supplies the active vector profile. Core binds a provider that answers null (no vector
 * search); the module that owns the embedding setup replaces the binding.
 */
interface IVectorProfileProvider
{
    public function current(): ?VectorProfile;
}
