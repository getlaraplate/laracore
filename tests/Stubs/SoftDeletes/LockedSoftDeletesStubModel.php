<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\SoftDeletes;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\SoftDeletes\SoftDeletes as SoftDeletesTrait;

/**
 * A soft deleting, lockable model whose rows in the bin can still carry a lock. The trait is imported under an alias,
 * so `model:soft-deletes-remove` finds no line to strip and leaves this file as it is.
 */
final class LockedSoftDeletesStubModel extends Model
{
    use HasLocks;
    use SoftDeletesTrait;

    public const string TABLE = 'locked_soft_deletes_stub';

    public $timestamps = false;

    protected $table = self::TABLE;

    protected $guarded = [];
}
