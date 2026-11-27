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
 * A root whose one relation is declared complete (D57): it may be reverted completely. Every column of its table is versioned, so a column added to the table opens a new epoch. Stored in the tables of {@see VersionedRelationRoot}.
 */
final class CoveredRelationRoot extends Model implements IVersionedModel
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
            new RelationDescriptor('categories', RelationOwnership::Reference, RelationCoverage::Complete, pivotColumns: ['position']),
        ];
    }
}
