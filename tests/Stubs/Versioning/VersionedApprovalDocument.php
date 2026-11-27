<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Approvals\Operation;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * A versioned record whose writes need approval: a revert of it becomes a request (D62).
 */
final class VersionedApprovalDocument extends Model implements IVersionedModel
{
    use HasApprovals;
    use HasVersions;

    public const string TABLE = 'core_test_versioned_approval_documents';

    /**
     * @var list<Operation>|null
     */
    public static ?array $operations = null;

    protected $table = self::TABLE;

    protected $fillable = ['title'];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    public function approvalOperations(): array
    {
        return self::$operations ?? Operation::cases();
    }
}
