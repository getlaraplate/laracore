<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * A second versioned class on the table of {@see EpochArticle}: the epoch belongs to the root class, not to the table.
 */
final class EpochArticleTwin extends Model
{
    use HasVersions;

    protected $table = EpochArticle::TABLE;

    protected $guarded = [];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;
}
