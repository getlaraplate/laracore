<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose default eager loads name something that is not a relation, so the cache dependency resolver cannot
 * tell what a read reaching it depends on. It has no table: the resolver never queries.
 */
final class CacheDependencyUnknownEagerStubModel extends Model
{
    public const string TABLE = 'cache_dependency_unknown_eager_stub_models';

    protected $table = self::TABLE;

    /**
     * @var list<string>
     */
    protected $with = ['missing'];
}
