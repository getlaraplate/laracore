<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Modules\Core\Contracts\ISoftDeletableModel;
use Modules\Core\Models\Concerns\HasPrefixedTableName;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Concerns\ReturnsVersionedRelations;
use Modules\Core\SoftDeletes\SoftDeletes;

/**
 * @mixin \Eloquent
 */
abstract class Model extends BaseModel implements ISoftDeletableModel
{
    use HasFactory;
    use HasPrefixedTableName;
    use HasValidations;
    use HasVersions;
    use ReturnsVersionedRelations;
    use SoftDeletes {
        HasVersions::delete insteadof SoftDeletes;
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::delete as protected deleteWithTheBin;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }

    // /**
    //  * Attribute payload used by {@see HasValidations::validateWithRules}. Overridden when
    //  * some assignable fields are delegated to relations until after validation (e.g. {@see HasPlace}).
    //  *
    //  * @return array<string, mixed>
    //  */
    // public function getAttributesForValidation(): array
    // {
    //     return $this->getAttributes();
    // }

    /**
     * Deletes through the bin inside the revision scope opened by {@see HasVersions::delete()}.
     */
    protected function deleteRecord(): ?bool
    {
        return $this->deleteWithTheBin();
    }

    /**
     * Force-deletes through the bin inside the revision scope opened by {@see HasVersions::forceDelete()}.
     */
    protected function forceDeleteRecord(): ?bool
    {
        return $this->forceDeleteWithTheBin();
    }
}
