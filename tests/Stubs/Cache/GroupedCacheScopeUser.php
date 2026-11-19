<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Modules\Core\Models\User;

/**
 * A user declaring the group relations {@see \Modules\Core\Cache\Labels\CacheScope} reads when they are loaded. The
 * tests set their records with setRelation(); the methods only have to exist.
 */
final class GroupedCacheScopeUser extends User
{
    public function groups(): null
    {
        return null;
    }

    public function user_groups(): null
    {
        return null;
    }

    public function user_roles(): null
    {
        return null;
    }
}
