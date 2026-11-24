<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Cache\HasCache;

/**
 * A cacheable record that points at any other record through a `morphTo`: the response cache labels what it displays
 * only after the query, once the classes of the loaded targets are known. The test creates its table.
 */
final class CacheMorphStubModel extends Model
{
    use HasCache;

    public const string TABLE = 'core_test_cache_morph_stubs';

    protected $table = self::TABLE;

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
