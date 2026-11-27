<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;

/**
 * A `morphTo` target keyed by a column other than `id`, so two targets of one relation can carry different key names.
 * It has no table: the resolver only reads its class-level data.
 */
final class CacheMorphCodeKeyedStubModel extends Model
{
    public const string TABLE = 'cache_morph_code_keyed_stubs';

    public $incrementing = false;

    protected $table = self::TABLE;

    protected $primaryKey = 'code';

    protected $keyType = 'string';
}
