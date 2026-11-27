<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Enums\RelationCoverage;
use Modules\Core\Enums\RelationOwnership;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersionedRelations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Concerns\ReturnsVersionedRelations;
use Modules\Core\Versioning\Data\RelationDescriptor;

/**
 * A root that registers an owned relation other than its translations: no revert puts such a relation back, so a complete revert is refused. Stored in the tables of {@see VersionedRelationRoot}.
 */
final class OwnedRelationRoot extends Model implements IVersionedModel
{
    use HasVersionedRelations;
    use HasVersions;
    use ReturnsVersionedRelations;

    protected $table = VersionedRelationRoot::TABLE;

    protected $guarded = [];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    /**
     * @return BelongsToMany<VersionedRelationSubject, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            VersionedRelationSubject::class,
            VersionedRelationRoot::PIVOT_TABLE,
            'root_id',
            'subject_id',
        )->using(VersionedRelationPivot::class)->withPivot('position');
    }

    /**
     * @return list<RelationDescriptor>
     */
    protected function versionedRelations(): array
    {
        return [
            new RelationDescriptor('categories', RelationOwnership::Owned, RelationCoverage::Complete, pivotColumns: ['position']),
        ];
    }
}
