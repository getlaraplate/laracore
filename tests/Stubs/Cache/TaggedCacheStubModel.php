<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Cache\HasCache;

/**
 * A cacheable model whose responses the Core cache repository stores under its table tag.
 */
final class TaggedCacheStubModel extends Model
{
    use HasCache;

    protected $table = 'tagged_cache_stub_models';
}
