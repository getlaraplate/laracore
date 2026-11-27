<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Crud;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasClosureTable;

/**
 * A hierarchy the tree endpoint walks: a `name` column and a parent, on its own closure table.
 */
class TreeStubModel extends Model
{
    use HasClosureTable;

    protected $table = 'crud_cov_tree';

    protected $guarded = [];
}
