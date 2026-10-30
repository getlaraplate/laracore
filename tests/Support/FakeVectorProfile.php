<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Pins the vector profile of a test by binding a fixed provider in the container.
 *
 * The context override is only ever set inside VectorProfileContext::using(), which restores
 * it itself, so binding the provider is all a test needs to start from a known profile.
 */
final class FakeVectorProfile
{
    /**
     * Make the given profile the one the provider answers for the rest of the test.
     */
    public static function use(?VectorProfile $profile = new VectorProfile('a:one', 384, 'cosine')): void
    {
        app()->instance(IVectorProfileProvider::class, new FixedVectorProfileProvider($profile));
    }

    /**
     * Make the provider answer no profile for the rest of the test.
     */
    public static function none(): void
    {
        self::use(null);
    }
}
