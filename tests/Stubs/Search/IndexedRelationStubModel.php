<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A related record for {@see \Modules\Core\Search\Schema\IndexedRelation}: its attributes are set in memory, no
 * table is read.
 */
final class IndexedRelationStubModel extends Model
{
    #[Override]
    protected $guarded = [];
}
