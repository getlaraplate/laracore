<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Cache\HasCache;

/**
 * A second cacheable model, so a test can prove that clearing one table leaves another cached.
 */
final class OtherTaggedCacheStubModel extends Model
{
    use HasCache;

    protected $table = 'other_tagged_cache_stub_models';
}
