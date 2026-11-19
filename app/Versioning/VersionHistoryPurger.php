<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;

/**
 * Removes the edit history of a table whose versioning is switched off (spec
 * `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md`, decision D22): every version set
 * whose root lives in the table, of every root class stored there, with its rows by cascade. The snapshot set of a
 * record deleted for good stays, so the record can still be recovered and its snapshot purged on its own schedule
 * (D17, D35): it is the set holding the root's `deleted` row, the one with no relation path.
 *
 * A revert set whose target is removed keeps a null link, set by the database (D58).
 */
final readonly class VersionHistoryPurger
{
    public const int CHUNK = 500;

    /**
     * What a purge of the table would remove now.
     *
     * @return array{sets: int, rows: int}
     */
    public function countFor(string $table): array
    {
        $set = new VersionSet;

        return [
            'sets' => $this->editSets($table)->count(),
            'rows' => Version::query()->whereIn('version_set_id', $this->editSets($table)->select($set->getQualifiedKeyName()))->count(),
        ];
    }

    /**
     * Removes the edit history of the table, in chunks of {@see self::CHUNK} sets.
     *
     * @return int The sets removed
     */
    public function purge(string $table): int
    {
        $removed = 0;
        $set = new VersionSet;

        $this->editSets($table)->select($set->getQualifiedKeyName())->chunkById(
            self::CHUNK,
            static function (Collection $sets) use (&$removed): void {
                $removed += VersionSet::query()->whereKey($sets->modelKeys())->delete();
            },
            $set->getQualifiedKeyName(),
            $set->getKeyName(),
        );

        return $removed;
    }

    /**
     * The sets of the table's roots but the snapshot sets of records deleted for good.
     *
     * @return Builder<VersionSet>
     */
    private function editSets(string $table): Builder
    {
        $set = new VersionSet;
        $version = new Version;

        return VersionSet::query()
            ->where($set->qualifyColumn('root_table_ref'), $table)
            ->whereNotExists(static function (QueryBuilder $snapshot) use ($set, $version): void {
                $snapshot->selectRaw('1')
                    ->from($version->getTable())
                    ->whereColumn($version->qualifyColumn('version_set_id'), $set->getQualifiedKeyName())
                    ->where($version->qualifyColumn('change_type'), VersionChangeType::Deleted->value)
                    ->whereNull($version->qualifyColumn('relation_path'));
            });
    }
}
