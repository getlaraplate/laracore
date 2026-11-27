<?php

declare(strict_types=1);

use App\Models\User as AppUser;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\Core\Auth\Providers\FortifyCredentialsProvider;
use Modules\Core\Auth\Providers\SocialiteProvider;
use Modules\Core\Models\License;
use Modules\Core\Models\Pivot\ModelHasRole;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

it('handles fortify canHandle/isEnabled/provider name branches', function (): void {
    $provider = new FortifyCredentialsProvider();

    expect($provider->canHandle(request()->duplicate(['email' => 'john@example.test', 'password' => 'secret'])))->toBeTrue()
        ->and($provider->canHandle(request()->duplicate(['username' => 'john', 'password' => 'secret'])))->toBeTrue()
        ->and($provider->canHandle(request()->duplicate(['username' => 'john'])))->toBeFalse();

    config(['auth.providers.users.driver' => 'eloquent']);
    expect($provider->isEnabled())->toBeTrue()
        ->and($provider->getProviderName())->toBe('credentials');

    config(['auth.providers.users.driver' => 'database']);
    expect($provider->isEnabled())->toBeFalse();
});

it('authenticates credentials and covers invalid and license branches', function (): void {
    $provider = new FortifyCredentialsProvider();
    $role = Role::factory()->create(['name' => 'member', 'guard_name' => 'web']);
    $user = new AppUser;
    $user->forceFill([
        'name' => 'Fortify Member',
        'email' => 'fortify@example.test',
        'username' => 'fortify-user',
        'password' => Hash::make('secret'),
        'license_id' => null,
        'email_verified_at' => now(),
    ])->save();
    $user->getConnection()->table((new ModelHasRole)->getTable())->insert([
        'role_id' => $role->id,
        'model_type' => AppUser::class,
        'model_id' => $user->id,
    ]);

    $invalid = $provider->authenticate(request()->duplicate([
        'email' => 'fortify@example.test',
        'password' => 'wrong',
    ]));
    expect($invalid['success'])->toBeFalse()
        ->and($invalid['error'])->toBe('Invalid credentials or user not allowed to login');

    config(['core.auth.licenses.enabled' => false]);
    $success = $provider->authenticate(request()->duplicate([
        'username' => 'fortify-user',
        'password' => 'secret',
    ]));
    expect($success['error'])->toBeNull()
        ->and($success['success'])->toBeTrue()
        ->and($success['user'])->toBeInstanceOf(User::class);

    config(['core.auth.licenses.enabled' => true]);
    License::query()->delete();
    $license_error = $provider->authenticate(request()->duplicate([
        'email' => 'fortify@example.test',
        'password' => 'secret',
    ]));
    expect($license_error['success'])->toBeFalse()
        ->and($license_error['error'])->toBe('No free licenses available');
});

it('lets a super admin in when no license is free', function (): void {
    config(['core.auth.licenses.enabled' => true, 'permission.roles.superadmin' => 'superadmin']);
    License::query()->delete();

    $user = AppUser::factory()->create([
        'email' => 'root@example.test',
        'password' => Hash::make('secret'),
        'license_id' => null,
        'email_verified_at' => now(),
    ]);
    $user->assignRole(Role::findOrCreate('superadmin', 'web'));

    $result = (new FortifyCredentialsProvider())->authenticate(request()->duplicate([
        'email' => 'root@example.test',
        'password' => 'secret',
    ]));

    expect($result['error'])->toBeNull()
        ->and($result['success'])->toBeTrue();
});

it('returns email-not-verified and license-available success branches for fortify', function (): void {
    $provider = new FortifyCredentialsProvider();
    $role = Role::factory()->create(['name' => 'member-verify', 'guard_name' => 'web']);

    $unverified = new AppUser;
    $unverified->forceFill([
        'name' => 'Unverified User',
        'email' => 'unverified@example.test',
        'username' => 'unverified-user',
        'password' => Hash::make('secret'),
        'license_id' => null,
        'email_verified_at' => null,
    ])->save();
    $unverified->getConnection()->table((new ModelHasRole)->getTable())->insert([
        'role_id' => $role->id,
        'model_type' => AppUser::class,
        'model_id' => $unverified->id,
    ]);

    config(['core.auth.licenses.enabled' => false]);
    $email_error = $provider->authenticate(request()->duplicate([
        'email' => 'unverified@example.test',
        'password' => 'secret',
    ]));
    expect($email_error['success'])->toBeFalse()
        ->and($email_error['error'])->toBe('Email not verified');

    $verified = new AppUser;
    $verified->forceFill([
        'name' => 'Licensed User',
        'email' => 'licensed@example.test',
        'username' => 'licensed-user',
        'password' => Hash::make('secret'),
        'license_id' => null,
        'email_verified_at' => now(),
    ])->save();
    $verified->getConnection()->table((new ModelHasRole)->getTable())->insert([
        'role_id' => $role->id,
        'model_type' => AppUser::class,
        'model_id' => $verified->id,
    ]);

    License::factory()->create();
    config(['core.auth.licenses.enabled' => true]);
    $licensed_success = $provider->authenticate(request()->duplicate([
        'email' => 'licensed@example.test',
        'password' => 'secret',
    ]));
    expect($licensed_success['success'])->toBeTrue()
        ->and($licensed_success['error'])->toBeNull();
});

it('covers fortify private email verification and license helper methods', function (): void {
    $provider = new FortifyCredentialsProvider();
    $verify_method = new ReflectionMethod(FortifyCredentialsProvider::class, 'shouldVerifyEmail');
    $verify_method->setAccessible(true);

    $check_license_method = new ReflectionMethod(FortifyCredentialsProvider::class, 'checkLicense');
    $check_license_method->setAccessible(true);

    $verify_user = new AppUser;
    $verify_user->forceFill([
        'email_verified_at' => null,
        'license_id' => null,
    ]);
    $verify_user->setRelation('roles', collect());

    config(['core.auth.licenses.enabled' => false]);
    expect($verify_method->invoke($provider, $verify_user))->toBeTrue()
        ->and($check_license_method->invoke($provider, $verify_user))->toBeNull();
});

it('handles socialite canHandle and enabled/provider name branches', function (): void {
    $provider = new SocialiteProvider();

    config(['services.socialite.providers' => ['github', 'google']]);
    expect($provider->canHandle(request()->duplicate(['provider' => 'github'])))->toBeTrue()
        ->and($provider->canHandle(request()->duplicate(['provider' => 'unknown'])))->toBeFalse();

    config(['core.auth.social_login.enabled' => true]);
    expect($provider->isEnabled())->toBeTrue()
        ->and($provider->getProviderName())->toBe('social');

    config(['core.auth.social_login.enabled' => false]);
    expect($provider->isEnabled())->toBeFalse();
});

it('returns social error when socialite throws', function (): void {
    $provider = new SocialiteProvider();
    Socialite::shouldReceive('driver')
        ->once()
        ->with('github')
        ->andThrow(new Exception('boom'));

    $failed = $provider->authenticate(request()->duplicate(['provider' => 'github']));
    expect($failed['success'])->toBeFalse()
        ->and($failed['error'])->toBe('Social authentication failed');
});

it('returns conflict when email exists with another account type', function (): void {
    $provider = new SocialiteProvider();

    $taken_social = new AppUser;
    $taken_social->forceFill([
        'name' => 'Taken Social',
        'email' => 'taken-social@example.test',
        'username' => 'taken-social',
        'password' => Hash::make('secret'),
    ])->save();
    $social_user_conflict = (new SocialiteUser)
        ->map([
            'id' => 'social-1',
            'name' => 'Social User',
            'nickname' => 'social_user',
            'email' => 'taken-social@example.test',
        ])
        ->setToken('token')
        ->setRefreshToken('refresh-token');
    $driver_mock = Mockery::mock();
    $driver_mock->shouldReceive('user')->once()->andReturn($social_user_conflict);
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver_mock);

    $conflict = $provider->authenticate(request()->duplicate(['provider' => 'github']));
    expect($conflict['success'])->toBeFalse()
        ->and($conflict['error'])->toBe('User already registered with another account type');
});

it('authenticates social user successfully when data is valid', function (): void {
    $provider = new SocialiteProvider();
    $social_user_success = (new SocialiteUser)
        ->map([
            'id' => 'social-2',
            'name' => 'New Social',
            'nickname' => 'new_social',
            'email' => 'new-social@example.test',
        ])
        ->setToken('token-success')
        ->setRefreshToken('refresh-success');
    $driver_mock = Mockery::mock();
    $driver_mock->shouldReceive('user')->once()->andReturn($social_user_success);
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver_mock);

    expect((new AppUser)->getConnection()->getSchemaBuilder()->hasColumn((new AppUser)->getTable(), 'social_id'))->toBeTrue();
    config(['core.auth.licenses.enabled' => false, 'core.auth.registration.enabled' => true]);
    $success = $provider->authenticate(request()->duplicate(['provider' => 'github']));
    expect($success['error'])->toBeNull()
        ->and($success['success'])->toBeTrue()
        ->and($success['user'])->toBeInstanceOf(User::class);
});

it('covers socialite license error and enabled-license success branches', function (): void {
    $provider = new SocialiteProvider();

    $social_user = (new SocialiteUser)
        ->map([
            'id' => 'social-license-id',
            'name' => 'Licensed Social',
            'nickname' => 'licensed_social',
            'email' => 'licensed-social@example.test',
        ])
        ->setToken('token-social-license')
        ->setRefreshToken('refresh-social-license');

    $driver_mock = Mockery::mock();
    $driver_mock->shouldReceive('user')->twice()->andReturn($social_user);
    Socialite::shouldReceive('driver')->twice()->with('github')->andReturn($driver_mock);

    License::query()->delete();
    config(['core.auth.licenses.enabled' => true, 'core.auth.registration.enabled' => true]);
    $license_error = $provider->authenticate(request()->duplicate(['provider' => 'github']));
    expect($license_error['success'])->toBeFalse()
        ->and($license_error['error'])->toBe('No free licenses available');

    License::factory()->create();
    $licensed_success = $provider->authenticate(request()->duplicate(['provider' => 'github']));
    expect($licensed_success['success'])->toBeTrue()
        ->and($licensed_success['error'])->toBeNull();
});

it('covers socialite private checkLicense helper', function (): void {
    $provider = new SocialiteProvider();
    $method = new ReflectionMethod(SocialiteProvider::class, 'checkLicense');
    $method->setAccessible(true);

    $role = Role::factory()->create(['name' => 'member-social', 'guard_name' => 'web']);

    /** @var AppUser $user */
    $user = new AppUser;
    $user->forceFill([
        'name' => 'Social Member',
        'username' => 'social-member',
        'email' => 'social-member@example.test',
        'password' => Hash::make('secret'),
        'license_id' => null,
    ])->save();
    $user->setRelation('roles', collect([$role]));

    config(['core.auth.licenses.enabled' => true]);
    License::query()->delete();

    expect($method->invoke($provider, $user))->toBe('No free licenses available');
});

it('refuses a new social user while registration is disabled', function (): void {
    $provider = new SocialiteProvider();
    $social_user = (new SocialiteUser)
        ->map([
            'id' => 'social-closed',
            'name' => 'Closed Social',
            'nickname' => 'closed_social',
            'email' => 'closed-social@example.test',
        ])
        ->setToken('token-closed')
        ->setRefreshToken('refresh-closed');
    $driver_mock = Mockery::mock();
    $driver_mock->shouldReceive('user')->once()->andReturn($social_user);
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($driver_mock);

    config(['core.auth.licenses.enabled' => false, 'core.auth.registration.enabled' => false]);
    $result = $provider->authenticate(request()->duplicate(['provider' => 'github']));

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('Registration is disabled')
        ->and(AppUser::query()->where('social_id', 'social-closed')->exists())->toBeFalse();
});

it('keeps logging in a known social user while registration is disabled', function (): void {
    $provider = new SocialiteProvider();
    $social_user = (new SocialiteUser)
        ->map([
            'id' => 'social-known',
            'name' => 'Known Social',
            'nickname' => 'known_social',
            'email' => 'known-social@example.test',
        ])
        ->setToken('token-known')
        ->setRefreshToken('refresh-known');
    $driver_mock = Mockery::mock();
    $driver_mock->shouldReceive('user')->twice()->andReturn($social_user);
    Socialite::shouldReceive('driver')->twice()->with('github')->andReturn($driver_mock);

    config(['core.auth.licenses.enabled' => false, 'core.auth.registration.enabled' => true]);
    expect($provider->authenticate(request()->duplicate(['provider' => 'github']))['success'])->toBeTrue();

    config(['core.auth.registration.enabled' => false]);
    $again = $provider->authenticate(request()->duplicate(['provider' => 'github']));

    expect($again['success'])->toBeTrue()
        ->and($again['error'])->toBeNull();
});

it('declares which login methods already carry a second factor', function (): void {
    expect((new FortifyCredentialsProvider())->satisfiesSecondFactor())->toBeFalse()
        ->and((new SocialiteProvider())->satisfiesSecondFactor())->toBeTrue();
});
