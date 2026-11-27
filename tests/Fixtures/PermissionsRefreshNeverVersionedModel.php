<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\INeverVersionedModel;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * A model that carries the versioning trait but is never versioned, like comments and ratings: it keeps no history to
 * read or revert.
 */
final class PermissionsRefreshNeverVersionedModel extends Model implements INeverVersionedModel
{
    use HasVersions;

    protected $table = 'perm_refresh_never_versioned';
}
