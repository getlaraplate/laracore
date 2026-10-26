<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class VersionedRelationSubject extends Model
{
    public const string TABLE = 'core_test_versioned_relation_subjects';

    protected $table = self::TABLE;

    protected $guarded = [];

    /**
     * The inverse side of {@see VersionedRelationRoot::categories()}.
     *
     * @return BelongsToMany<VersionedRelationRoot, $this>
     */
    public function roots(): BelongsToMany
    {
        return $this->belongsToMany(
            VersionedRelationRoot::class,
            VersionedRelationRoot::PIVOT_TABLE,
            'subject_id',
            'root_id',
        )->using(VersionedRelationPivot::class)->withPivot('position');
    }
}
