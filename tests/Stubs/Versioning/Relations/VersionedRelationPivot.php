<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning\Relations;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Core\Models\Concerns\RecordsMembershipOnRoot;

/**
 * The custom pivot of the relation stubs: its events record the membership on the root (D42).
 */
final class VersionedRelationPivot extends Pivot
{
    use RecordsMembershipOnRoot;
}
