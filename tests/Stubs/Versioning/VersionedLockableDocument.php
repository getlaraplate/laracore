<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * A lockable versioned record with no `$versionable` list, so its lock columns would reach the history unless
 * they are excluded (D21).
 */
final class VersionedLockableDocument extends Model implements ILockableModel, IVersionedModel
{
    use HasLocks;
    use HasVersions;

    public const string TABLE = 'core_test_versioned_lockable_documents';

    protected $table = self::TABLE;

    protected $guarded = [];

    protected VersionStrategy $versionStrategy = VersionStrategy::SNAPSHOT;
}
