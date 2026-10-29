<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;

final class SoftDeletedVersionedArticle extends Model
{
    use HasVersions;
    use SoftDeletes {
        HasVersions::forceDelete insteadof SoftDeletes;
        SoftDeletes::forceDelete as protected forceDeleteWithTheBin;
    }

    protected $table = VersionedArticle::TABLE;

    protected $guarded = [];

    protected array $versionable = ['title'];

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
