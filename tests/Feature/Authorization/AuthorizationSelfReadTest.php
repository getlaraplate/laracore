<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\CronJob;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;

uses(RefreshDatabase::class);

/**
 * Registers the table-level select permission so the guard actually runs. Without a registered
 * permission the check reads "not registered" and allows the operation, which would prove nothing.
 */
function registerSelectPermission(Model $model): string
{
    $name = PermissionName::forModel($model, 'select');

    /** @var class-string<Model> $permission_class */
    $permission_class = config('permission.models.permission');

    $permission_class::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);

    return $name;
}

it('lets a superadmin read a role when their own roles have not been loaded yet', function (): void {
    // The reported symptom: a 403 on the first request after signing in, when nothing has warmed
    // the acting user's roles. Deciding whether they may read a role meant reading their roles.
    registerSelectPermission(new Role());

    $role = Role::query()->firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    Auth::login(User::query()->findOrFail($user->getKey()));

    expect(Auth::user()->relationLoaded('roles'))->toBeFalse()
        ->and(Role::query()->first()?->name)->toBe('superadmin');
});

it('resolves a plain user own roles instead of handing back a null relation', function (): void {
    // Eloquent does not reload a relation that is already loading, so the circle did not merely
    // recurse: `$user->roles` came back null and Spatie fell over inside `hasRole()`.
    registerSelectPermission(new Role());

    $role = Role::query()->firstOrCreate(['name' => 'publisher', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    Auth::login(User::query()->findOrFail($user->getKey()));

    expect(Auth::user()->roles->pluck('name')->all())->toBe(['publisher'])
        ->and(Auth::user()->isSuperAdmin())->toBeFalse();
});

it('still refuses an ordinary model to a user without the permission', function (): void {
    // The exemption is meant to be narrow. Anything that is not a role or a permission keeps the
    // per-row check, so this must stay red for a user who was granted nothing.
    $model = new class extends Model
    {
        use HasValidations;

        protected $table = 'core_taxonomies';
    };

    registerSelectPermission($model);

    Auth::login(User::factory()->create());

    $check = (new ReflectionMethod($model::class, 'checkUserCanDo'))->invoke(null, $model, 'select');

    expect($check)->toBeFalse();
});

it('resolves the acting user from the session without recursing into its own select check', function (): void {
    // Loading the session user hydrates a User, which fires the per-row select check, which asks
    // for the acting user, which is not resolved yet: the lookup re-entered itself until the stack
    // ran out. Only happens once `users.select` is a registered permission.
    registerSelectPermission(new User());

    $user = User::factory()->create();

    Auth::forgetGuards();
    Auth::guard('web')->onceUsingId($user->getKey());
    Auth::forgetGuards();
    session()->put(Auth::guard('web')->getName(), $user->getKey());

    expect(Auth::user()?->getKey())->toBe($user->getKey());
});

it('checks a grouped aggregate row that carries no key without reading the key', function (): void {
    // The CMS entity list counts its rows per type with a grouped `count(*)`: the hydrated rows have
    // no `id`, and the self-read exemption read it to compare them with the acting user.
    $permission = registerSelectPermission(new CronJob());

    $role = Role::query()->firstOrCreate(['name' => 'cron-reader', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);
    CronJob::factory()->count(2)->create();

    Auth::login(User::query()->findOrFail($user->getKey()));

    $rows = CronJob::query()->selectRaw('count(*) as aggregate_count')->get();

    expect((int) $rows->first()?->getAttribute('aggregate_count'))->toBe(2);
});

it('checks a keyless aggregate row of the users table against the permission, not as the acting user own row', function (): void {
    // Without its key a row cannot be shown to be the acting user's own, so the exemption does not
    // apply and the row is asked for the permission like any other.
    registerSelectPermission(new User());

    $user = User::factory()->create();

    Auth::login(User::query()->findOrFail($user->getKey()));

    expect(fn () => User::query()->selectRaw('count(*) as aggregate_count')->get())
        ->toThrow(AuthorizationException::class);
});
