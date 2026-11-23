<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Services\AclResolverService;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Support\PermissionName;

/**
 * The guest of `/app` holds nothing and the guest of `/api` holds the public list. Core owns the roles and the
 * public grant of its own entity (the settings flagged public); the module grants of the list are checked beside
 * the module seeders.
 */
beforeEach(function (): void {
    Artisan::call('permission:refresh', ['--quiet' => true]);
    Artisan::call('db:seed', ['--class' => CoreDatabaseSeeder::class, '--no-interaction' => true]);
});

/**
 * @return list<string>
 */
function guest_role_permission_names(string $guard): array
{
    return Role::query()
        ->where(['name' => config('permission.roles.guest'), 'guard_name' => $guard])
        ->firstOrFail()
        ->permissions()
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

it('gives the guest of the web guard no permission at all', function (): void {
    expect(guest_role_permission_names('web'))->toBe([]);
});

it('gives the guest of the api guard the select of the public settings and nothing else, in Core', function (): void {
    $setting_select = PermissionName::forClass(Setting::class, 'select');

    expect(guest_role_permission_names('api'))->toBe([$setting_select]);

    $permission = Permission::query()->where(['name' => $setting_select, 'guard_name' => 'api'])->firstOrFail();
    $guest = Role::query()->where(['name' => config('permission.roles.guest'), 'guard_name' => 'api'])->firstOrFail();
    $acl = ACL::query()->where(['permission_id' => $permission->id, 'role_id' => $guest->id])->sole();

    expect($acl->unrestricted)->toBeFalse()
        ->and($acl->filters)->toBeInstanceOf(FiltersGroup::class)
        ->and($acl->filters->filters)->toHaveCount(1)
        ->and($acl->filters->filters[0]->property)->toBe('core_settings.is_public')
        ->and($acl->filters->filters[0]->operator)->toBe(FilterOperator::Equals)
        ->and($acl->filters->filters[0]->value)->toBeTrue();
});

it('keeps one guest role per guard and gives the anonymous user both', function (): void {
    $guest_name = (string) config('permission.roles.guest');

    expect(Role::query()->where('name', $guest_name)->orderBy('guard_name')->pluck('guard_name')->all())->toBe(['api', 'web']);

    $anonymous = user_class()::query()->withoutGlobalScopes()->where('username', config('permission.users.guest'))->firstOrFail();

    expect($anonymous->roles()->where('name', $guest_name)->orderBy('guard_name')->pluck('guard_name')->all())->toBe(['api', 'web']);
});

it('gives the anonymous user the api guest role also when it was seeded before the api role existed', function (): void {
    $guest_name = (string) config('permission.roles.guest');
    $anonymous = user_class()::query()->withoutGlobalScopes()->where('username', config('permission.users.guest'))->firstOrFail();
    $api_guest = Role::query()->where(['name' => $guest_name, 'guard_name' => 'api'])->firstOrFail();

    $anonymous->roles()->detach($api_guest->id);

    expect($anonymous->roles()->where('guard_name', 'api')->count())->toBe(0);

    Artisan::call('db:seed', ['--class' => CoreDatabaseSeeder::class, '--no-interaction' => true]);

    expect($anonymous->roles()->where('name', $guest_name)->orderBy('guard_name')->pluck('guard_name')->all())->toBe(['api', 'web']);
});

it('is idempotent: a second run adds no role, grant or ACL', function (): void {
    $before = [Role::query()->count(), ACL::query()->count(), Role::query()->withCount('permissions')->get()->sum('permissions_count')];

    Artisan::call('db:seed', ['--class' => CoreDatabaseSeeder::class, '--no-interaction' => true]);

    expect([Role::query()->count(), ACL::query()->count(), Role::query()->withCount('permissions')->get()->sum('permissions_count')])->toBe($before);
});

it('gives no api permission to the staff roles', function (string $role_key): void {
    $name = CoreDatabaseSeeder::getDefaultUserRoles()[$role_key];
    $roles = Role::query()->where('name', $name)->get();

    expect($roles->pluck('guard_name')->all())->toBe(['web']);

    foreach ($roles as $role) {
        expect($role->permissions()->where('guard_name', 'api')->count())->toBe(0);
    }
})->with(['superadmin', 'admin', 'system']);

it('lets an api role share the name of a web role but not the name of a role of its own guard', function (): void {
    $web = Role::query()->create(['name' => 'shared_name', 'guard_name' => 'web']);
    $api = Role::query()->create(['name' => 'shared_name', 'guard_name' => 'api']);

    expect($web->guard_name)->toBe('web')
        ->and($api->guard_name)->toBe('api')
        ->and(fn () => Role::query()->create(['name' => 'shared_name', 'guard_name' => 'api']))
        ->toThrow(ValidationException::class);
});

it('validates the update of a role name against the roles of its own guard only', function (): void {
    Role::query()->create(['name' => 'taken_on_web', 'guard_name' => 'web']);
    $api = Role::query()->create(['name' => 'another_api_name', 'guard_name' => 'api']);

    $api->update(['name' => 'taken_on_web']);

    expect($api->fresh()->name)->toBe('taken_on_web');
});

it('resolves the anonymous account as the configured user class, so it holds the guest roles it was seeded with', function (): void {
    resolve(Modules\Core\Cache\Labels\CacheLabelInvalidator::class)->flushTable((new (user_class())())->getTable());
    Auth::shouldUse('api');

    $anonymous = app(AuthorizationService::class)->resolveAnonymousUser(Request::create('/api/v1/select/core/settings'));

    expect($anonymous)->toBeInstanceOf(user_class())
        ->and($anonymous->roles()->orderBy('guard_name')->pluck('guard_name')->all())->toBe(['api', 'web'])
        ->and($anonymous->hasPermission(PermissionName::forClass(Setting::class, 'select'), 'api'))->toBeTrue()
        ->and($anonymous->hasPermission(PermissionName::forClass(Setting::class, 'select'), 'web'))->toBeFalse();
});

it('resolves the ACL of the api guest for the anonymous user although the guest may not read ACL rows', function (): void {
    resolve(Modules\Core\Cache\Labels\CacheLabelInvalidator::class)->flushTable((new (user_class())())->getTable());
    Auth::shouldUse('api');

    $anonymous = app(AuthorizationService::class)->resolveAnonymousUser(Request::create('/api/v1/select/core/settings'));
    $permission = Permission::query()->where(['name' => PermissionName::forClass(Setting::class, 'select'), 'guard_name' => 'api'])->firstOrFail();

    $acls = app(AclResolverService::class)->getEffectiveAcls($anonymous, $permission);

    expect($acls)->toHaveCount(1)
        ->and($acls->first()->filters->filters[0]->property)->toBe('core_settings.is_public');
});
