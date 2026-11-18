<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Models\Role;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;

/**
 * A model with one relation of each kind the cache dependency resolver tells apart. It has no table: the resolver
 * only builds relation instances, it never queries.
 */
final class CacheDependencyStubModel extends Model
{
    public const string TABLE = 'cache_dependency_stub_models';

    protected $table = self::TABLE;

    /**
     * @return BelongsTo<TranslatedDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(TranslatedDocument::class);
    }

    /**
     * @return BelongsTo<CacheDependencyEagerStubModel, $this>
     */
    public function eager(): BelongsTo
    {
        return $this->belongsTo(CacheDependencyEagerStubModel::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasOne<CacheDependencyStubChild, $this>
     */
    public function detail(): HasOne
    {
        return $this->hasOne(CacheDependencyStubChild::class, 'parent_id');
    }

    /**
     * @return HasMany<CacheDependencyStubChild, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CacheDependencyStubChild::class, 'parent_id');
    }

    /**
     * @return HasManyThrough<Role, CacheDependencyStubChild, $this>
     */
    public function childRoles(): HasManyThrough
    {
        return $this->hasManyThrough(Role::class, CacheDependencyStubChild::class, 'parent_id', 'id', 'id', 'role_id');
    }
}
