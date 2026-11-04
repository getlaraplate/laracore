<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * Says whether the next atomic set of a root must anchor its whole aggregate (D45 rule 1, D52): when the root has no
 * baseline captured atomically, or a set captured less than atomically is newer than it: a baseline that was itself
 * captured less than atomically is no complete anchor (D47). The root is its class, key and table, as everywhere the
 * sets of one root are read.
 */
final readonly class BaselinePolicy
{
    public function __construct(private VersionEpochs $epochs) {}

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
                'max(case when kind = ? and capture = ? then id end) as latest_baseline, max(case when capture <> ? then id end) as latest_partial',
                [VersionSetKind::Baseline->value, VersionCaptureQuality::Atomic->value, VersionCaptureQuality::Atomic->value],
            )
            ->first();

        $latest_baseline = is_numeric($latest?->latest_baseline) ? (int) $latest->latest_baseline : null;
        $latest_partial = is_numeric($latest?->latest_partial) ? (int) $latest->latest_partial : null;

        if ($latest_baseline === null || ($latest_partial !== null && $latest_partial > $latest_baseline)) {
            return true;
        }

        return $this->epochChanged($root, $latest_baseline);
    }

    /**
     * Whether the root's latest complete anchor was written in another schema epoch than the current one of its class
     * (D33). The anchor, not the latest row: a revert or a write through another root's scope stamps the new epoch
     * without carrying a baseline, and must leave it due (D60). A class that has known one shape only has nothing to
     * compare, and is asked nothing more.
     */
    private function epochChanged(VersionSetRoot $root, int $baselineSetId): bool
    {
        $current = $this->epochs->currentFor($root->model());

        if (! $this->epochs->hasSeveral($root->model())) {
            return false;
        }

        $version = new Version;
        $version->setConnection($root->connectionName());

        $anchor_epoch = $version->newQuery()
            ->toBase()
            ->where('version_set_id', $baselineSetId)
            ->orderByDesc('sequence')
            ->value('epoch_id');

        return ! is_numeric($anchor_epoch) || (int) $anchor_epoch !== $current->getKey();
    }
}
