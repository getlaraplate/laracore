<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\SoftDeletes;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\SoftDeletes\SoftDeletes as SoftDeletesTrait;

/**
 * A model that pins soft deletes on in code: its setting has no runtime effect, so switching it off must not empty
 * the bin.
 */
final class PinnedSoftDeletesStubModel extends Model
{
    use SoftDeletesTrait;

    public const string TABLE = 'pinned_soft_deletes_stub';

    public $timestamps = false;

    protected $table = self::TABLE;

    private bool $softDeletesEnabled = true;
}
