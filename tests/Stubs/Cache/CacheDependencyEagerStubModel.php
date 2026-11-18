<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Role;

/**
 * A model whose default eager loads name a `belongsTo` and the model itself, a cycle the cache dependency resolver
 * must expand once. It has no table: the resolver never queries.
 */
final class CacheDependencyEagerStubModel extends Model
{
    public const string TABLE = 'cache_dependency_eager_stub_models';

    protected $table = self::TABLE;

    /**
     * @var list<string>
     */
    protected $with = ['role', 'previous'];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_id');
    }
}
