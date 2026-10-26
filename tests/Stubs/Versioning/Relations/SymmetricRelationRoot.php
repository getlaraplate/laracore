<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Enums\RelationOwnership;
use Modules\Core\Models\Concerns\HasVersionedRelations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Versioning\Data\RelationDescriptor;

/**
 * A root that registers a relation to records of its own class, like `Content::related()`: a change of it touches
 * two roots, which milestone 1 does not version (D24).
 */
final class SymmetricRelationRoot extends Model
{
    use HasVersionedRelations;
    use HasVersions;

    protected $table = 'core_test_symmetric_relation_roots';

    /**
     * @return BelongsToMany<SymmetricRelationRoot, $this>
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'core_test_symmetric_relation_links', 'left_id', 'right_id');
    }

    /**
     * @return list<RelationDescriptor>
     */
    protected function versionedRelations(): array
    {
        return [new RelationDescriptor('related', RelationOwnership::Reference)];
    }
}
