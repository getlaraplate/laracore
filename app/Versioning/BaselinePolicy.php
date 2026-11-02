<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\VersionSet;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * Says whether the next atomic set of a root must anchor its whole aggregate (D45 rule 1, D52): when the root has no
 * baseline set, or a set captured less than atomically is newer than its latest baseline. The root is its class, key
 * and table, as everywhere the sets of one root are read.
 */
final readonly class BaselinePolicy
{
    public function isDue(VersionSetRoot $root): bool
    {
        $version_set = new VersionSet;
        $version_set->setConnection($root->connectionName());

        $latest = $version_set->newQuery()
            ->toBase()
            ->where('root_type', $root->type())
            ->where('root_id', $root->id())
            ->where('root_table_ref', $root->tableName())
            ->selectRaw(
                'max(case when kind = ? then id end) as latest_baseline, max(case when capture <> ? then id end) as latest_partial',
                [VersionSetKind::Baseline->value, VersionCaptureQuality::Atomic->value],
            )
            ->first();

        $latest_baseline = is_numeric($latest?->latest_baseline) ? (int) $latest->latest_baseline : null;
        $latest_partial = is_numeric($latest?->latest_partial) ? (int) $latest->latest_partial : null;

        return $latest_baseline === null || ($latest_partial !== null && $latest_partial > $latest_baseline);
    }
}
