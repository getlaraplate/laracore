<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * A tree node whose global scope orders by a column of its own, unrelated to the depth, as the sortable
 * hierarchies do: the hierarchy relations must still return their nodes in depth order.
 */
final class OrderedClosureTreeStubModel extends ClosureTreeStubModel
{
    protected $fillable = ['parent_id', 'position'];

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('position', static function (Builder $query): void {
            $query->orderBy($query->getModel()->qualifyColumn('position'));
        });
    }
}
