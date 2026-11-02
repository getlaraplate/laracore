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

    /**
     * @param  int|null  $openSetId  the set being closed, whose own rows already carry the current epoch
     */
    public function isDue(VersionSetRoot $root, ?int $openSetId = null): bool
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

        return $this->epochChanged($root, $openSetId);
    }

    /**
     * Whether the root's latest row before the open set was written in another schema epoch than the current one of
     * its class (D33). A class that has known one shape only has nothing to compare, and is asked nothing more.
     */
    private function epochChanged(VersionSetRoot $root, ?int $openSetId): bool
    {
        $current = $this->epochs->currentFor($root->model());

        if (! $this->epochs->hasSeveral($root->type())) {
            return false;
        }

        $version_set = new VersionSet;
        $version_set->setConnection($root->connectionName());
        $sets = $version_set->getTable();
        $versions = new Version()->getTable();

        $latest_epoch = $version_set->newQuery()
            ->toBase()
            ->join($versions, "{$versions}.version_set_id", '=', "{$sets}.id")
            ->where("{$sets}.root_type", $root->type())
            ->where("{$sets}.root_id", $root->id())
            ->where("{$sets}.root_table_ref", $root->tableName())
            ->when($openSetId !== null, static fn ($query) => $query->where("{$sets}.id", '<>', $openSetId))
            ->orderByDesc("{$sets}.id")
            ->orderByDesc("{$versions}.sequence")
            ->value("{$versions}.epoch_id");

        return ! is_numeric($latest_epoch) || (int) $latest_epoch !== $current->getKey();
    }
}
