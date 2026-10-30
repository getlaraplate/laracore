<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;

/**
 * Model with a collection name but no mapping of its own, so the engines fall back to their default mapping.
 */
final class DefaultMappingStubModel extends Model
{
    public function searchableAs(): string
    {
        return 'default_mapping_stub';
    }
}
