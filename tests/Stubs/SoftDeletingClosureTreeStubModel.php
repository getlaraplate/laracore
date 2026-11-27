<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasClosureTable;
use Modules\Core\SoftDeletes\SoftDeletes;

/**
 * A tree node with Core's soft deletes, switched on in code so no setting is read.
 */
class SoftDeletingClosureTreeStubModel extends Model
{
    use HasClosureTable;
    use SoftDeletes;

    protected $table = 'closure_soft_tree_nodes';

    protected $fillable = ['parent_id'];

    protected bool $softDeletesEnabled = true;

    protected $casts = [
        'parent_id' => 'integer',
    ];
}
