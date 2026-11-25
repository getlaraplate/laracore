<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Crud;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Stub owning two many-to-many relations: one black-listed by name ("descendants") and one
 * ordinary ("members"), used to prove the facet path refuses the first and keeps the second.
 */
class BlackListedManyRelationStubModel extends Model
{
    public $timestamps = false;

    protected $table = 'black_listed_many_stub';

    protected $guarded = [];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'black_listed_many_stub_user', 'stub_id', 'user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'black_listed_many_stub_user', 'stub_id', 'user_id');
    }
}
