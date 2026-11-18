<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Crud;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasClosureTable;

/**
 * A hierarchy the tree endpoint walks towards the ancestors: a `name` column and a parent, on its own closure table.
 */
class AncestorTreeStubModel extends Model
{
    use HasClosureTable;

    protected $table = 'crud_cov_tree_anc';

    protected $guarded = [];
}
