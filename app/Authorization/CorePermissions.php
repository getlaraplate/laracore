<?php

declare(strict_types=1);

namespace Modules\Core\Authorization;

use Modules\Core\Authorization\Contracts\DeclaresPermissions;
use Modules\Core\Models\Approval;
use Modules\Core\Models\Disapproval;
use Modules\Core\Models\ImportRowError;
use Modules\Core\Models\MediaDraft;
use Modules\Core\Models\Notification;
use Modules\Core\Models\OutboxEvent;
use Modules\Core\Models\RecordOrigin;
use Modules\Core\Models\SeedRun;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionEpoch;
use Modules\Core\Models\VersionSet;
use Override;

/**
 * Core declares no domain operations of its own: its verbs are the generic ones
 * `permission:refresh` already generates. What it does declare is the
 * bookkeeping nobody addresses as an entity.
 */
final class CorePermissions implements DeclaresPermissions
{
    #[Override]
    public static function operations(): array
    {
        return [];
    }

    /**
     * Rows written as a side effect of an action authorized somewhere else.
     *
     * Approving a record is gated by `approve` on the record, so the vote row
     * needs no permission of its own; the same holds for the version set behind
     * a versioned write, the outbox row behind a published event, the origin row
     * behind an imported record, the draft bucket behind an upload, and the
     * notification a user reads through its own user-scoped endpoint rather than
     * through the CRUD engine. {@see Version} and
     * {@see \Modules\Core\Models\Modification} are already excluded by the
     * command's own blacklist; these complete the same subsystems, which were
     * excluded only halfway.
     */
    #[Override]
    public static function excludedModels(): array
    {
        return [
            Approval::class,
            Disapproval::class,
            ImportRowError::class,
            MediaDraft::class,
            Notification::class,
            OutboxEvent::class,
            RecordOrigin::class,
            SeedRun::class,
            // The history is read only through its root, with `history` and the root's ACL: a permission on the
            // table itself would list the past of records the reader is not allowed to see.
            Version::class,
            VersionEpoch::class,
            VersionSet::class,
        ];
    }
}
