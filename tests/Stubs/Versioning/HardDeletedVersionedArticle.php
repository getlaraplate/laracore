<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\SoftDeletes\SoftDeletes;

/**
 * A versioned model carrying Core's soft deletes and switching them off in its own code, so that
 * a plain delete removes the row.
 */
final class HardDeletedVersionedArticle extends Model
{
    use HasVersions;
    use SoftDeletes {
        HasVersions::delete insteadof SoftDeletes;
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::delete as protected deleteWithTheBin;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }

    protected bool $softDeletesEnabled = false;

    protected $table = VersionedArticle::TABLE;

    protected $guarded = [];

    protected array $versionable = ['title'];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

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
