<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * A versioned record with a bin and no `$versionable` list, so every column but the excluded ones is in its
 * history: the shape of most Core models.
 */
final class VersionedDocument extends Model
{
    use HasVersions;
    use SoftDeletes {
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }

    public const string TABLE = 'core_test_versioned_documents';

    protected $table = self::TABLE;

    protected $guarded = [];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    public function useVersionStrategy(VersionStrategy $strategy): void
    {
        $this->versionStrategy = $strategy;
    }

    /**
     * Force-deletes through the bin inside the revision scope opened by {@see HasVersions::forceDelete()}.
     */
    protected function forceDeleteRecord(): ?bool
    {
        return $this->forceDeleteWithTheBin();
    }
}
