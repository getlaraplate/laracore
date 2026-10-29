<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders;

use Illuminate\Support\Facades\Artisan;
use Modules\Core\Overrides\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Graph node wrapping `permission:refresh`.
 *
 * Split out of {@see CoreDatabaseSeeder::defaultPermissions()} so any seeder that needs a
 * freshly-inspected permission set (e.g. `ERPDatabaseSeeder::ensureDomainPermissions()`) can
 * declare a dependency on it instead of relying on undeclared run order. `CoreDatabaseSeeder`
 * itself depends on this node because `defaultRoles()` assigns permissions that only exist once
 * `permission:refresh` has run.
 */
final class PermissionRefreshSeeder extends Seeder
{
    public function run(): void
    {
        /** @var class-string<Permission> $permission_class */
        $permission_class = config('permission.models.permission');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->logOperation($permission_class);

        $before = self::permissionKeys($permission_class);
        Artisan::call('permission:refresh');
        $after = self::permissionKeys($permission_class);

        $kept = count(array_intersect_key($before, $after));

        // A permission the refresh deleted realigns the table to the models, like a created one extends it.
        $this->logOutcome(count($after) - $kept, count($before) - $kept, $kept);
    }

    /**
     * @param  class-string<Permission>  $permission_class
     * @return array<string, true>
     */
    private static function permissionKeys(string $permission_class): array
    {
        return $permission_class::query()
            ->get(['name', 'guard_name'])
            ->mapWithKeys(static fn (Permission $permission): array => ["{$permission->name}|{$permission->guard_name}" => true])
            ->all();
    }
}
