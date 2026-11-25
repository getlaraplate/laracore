<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Locking;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IOptimisticLockableModel;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Locking\Traits\HasOptimisticLocking;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * Carries both history and optimistic locking, like CMS Content does.
 *
 * @property int $id
 * @property string|null $name
 * @property int|null $lock_version
 */
final class VersionedLockModel extends Model implements IOptimisticLockableModel, IVersionedModel
{
    use HasOptimisticLocking;
    use HasVersions;

    public $timestamps = false;

    protected $table = 'versioned_lock_models';

    /**
     * @var list<string>
     */
    protected $fillable = ['name'];
}
