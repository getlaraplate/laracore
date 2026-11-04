<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Crud;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The orders of the aggregate tests as a tree: an order belongs to a parent order of the same table, through a
 * `parent_id` column the test that uses this model adds to `aggregate_orders`.
 *
 * @property int $id
 * @property int|null $parent_id
 */
final class AggregateTreeOrderStubModel extends Model
{
    public $timestamps = false;

    protected $table = 'aggregate_orders';

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
