<?php

declare(strict_types=1);

namespace Modules\Core\Seeding;

use Modules\Core\Cache\Labels\CacheLabelInvalidator;
use Modules\Core\Models\Setting;

final class SettingsCleaner
{
    public function __construct(private readonly ModuleStateResolver $resolver) {}

    /**
     * Remove derived settings whose owning module is disabled or gone.
     *
     * The whereNotNull pair is the safety mechanism, not a convenience: a row
     * without a module or without a baseline was never written by a seeder, so
     * it is never a candidate for deletion. Keep the filter in the query, not
     * in the loop body, so a later refactor of the loop cannot bypass it.
     */
    public function clean(): CleanupReport
    {
        $hard_deleted = [];
        $soft_deleted = [];
        $preserved = [];

        $candidates = Setting::query()
            ->withoutGlobalScopes()
            ->whereNotNull('module')
            ->whereNotNull('seeded_value')
            ->get();

        foreach ($candidates as $setting) {
            $state = $this->resolver->for($setting->getAttribute('module'));

            if ($state === ModuleState::Enabled) {
                $preserved[] = (string) $setting->getAttribute('name');

                continue;
            }

            $drifted = ! ValueComparator::equal(
                $setting->getAttribute('value'),
                $setting->getAttribute('seeded_value'),
            );

            if ($state === ModuleState::Absent || ! $drifted) {
                $setting->forceDelete();
                $hard_deleted[] = (string) $setting->getAttribute('name');

                continue;
            }

            // Bypass the model path: Setting::delete() routes through
            // Modules\Core\SoftDeletes\SoftDeletes::performDeleteOnModel(),
            // which downgrades to forceDelete() when the operator-editable
            // soft_deletes_core_settings row is false. That row lives in the
            // very table being cleaned, so the one branch meant to preserve
            // operator customizations would destroy them depending on data in
            // this table. Writing deleted_at directly through the
            // soft-deleting builder macro is unconditional.
            Setting::query()->withoutGlobalScopes()->whereKey($setting->getKey())->delete();
            $soft_deleted[] = (string) $setting->getAttribute('name');
        }

        if ($soft_deleted !== []) {
            // The soft deletes above are mass writes, which fire no model event.
            $setting = new Setting;
            app(CacheLabelInvalidator::class)->flushTable($setting->getTable(), $setting);
        }

        return new CleanupReport($hard_deleted, $soft_deleted, $preserved);
    }
}
