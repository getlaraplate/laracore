<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Crud;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IRestrictsReadableColumns;

/**
 * The orders of the aggregate tests read by a caller who may read their amounts but not their currency: every
 * column of `aggregate_orders` is readable but `currency`.
 *
 * @property int $id
 * @property string|null $total
 */
final class AggregateHiddenCurrencyOrderStubModel extends Model implements IRestrictsReadableColumns
{
    public $timestamps = false;

    protected $table = 'aggregate_orders';

    /**
     * @return list<string>
     */
    public function readableColumnsForCurrentCaller(): array
    {
        return ['id', 'customer_id', 'status', 'total', 'quantity', 'ordered_at', 'is_paid'];
    }
}
