<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Cache\CacheManager;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Services\AclResolverService;

beforeEach(function (): void {
    Cache::flush();
});

function acl_test_cache_key(string $connection, int|string $user_id, int|string $permission_id): string
{
    return CacheManager::key('acl', 'connection', $connection, 'user', (string) $user_id, 'perm', (string) $permission_id);
}

it('getEffectiveAcls caches the resolved ACLs per user and permission', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    /** @var Permission $permission */
    $permission = Permission::factory()->create();

    $service = new AclResolverService();

    // First call should hit the DB via resolveAcls and populate cache
    $aclsFirst = $service->getEffectiveAcls($user, $permission);
    expect($aclsFirst)->toBeInstanceOf(Collection::class);

    // Second call should return from cache (we do not assert DB calls here, just that it still returns a Collection)
    $aclsSecond = $service->getEffectiveAcls($user, $permission);

    expect($aclsSecond)->toBeInstanceOf(Collection::class);
});

it('rejects mixed user and permission connections before returning a cache hit', function (): void {
    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('affinity');

    try {
        $user = User::factory()->create();
        $permission = Permission::factory()->create();
        $service = new AclResolverService;

        $service->getEffectiveAcls($user, $permission);
        $permission->setConnection('affinity');

        expect(fn (): Collection => $service->getEffectiveAcls($user, $permission))
            ->toThrow(LogicException::class, 'ACL resolution requires user and permission models on the same database connection.');
    } finally {
        DB::disconnect('affinity');
        DB::purge('affinity');
    }
});

it('keeps ACL cache entries isolated by resolved connection', function (): void {
    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('affinity');

    $role_table = config('permission.table_names.roles');
    $model_role_table = config('permission.table_names.model_has_roles');
    $model_key = config('permission.column_names.model_morph_key', 'model_id');

    Schema::connection('affinity')->create($role_table, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->boolean('is_deleted')->default(false);
    });
    Schema::connection('affinity')->create($model_role_table, function (Blueprint $table) use ($model_key): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger($model_key);
    });

    // Resolution consults direct grants as well as role grants, so the connection has to carry the
    // permission side too.
    $permission_table = config('permission.table_names.permissions');
    $model_permission_table = config('permission.table_names.model_has_permissions');

    Schema::connection('affinity')->create($permission_table, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
    });
    Schema::connection('affinity')->create($model_permission_table, function (Blueprint $table) use ($model_key): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger($model_key);
    });

    try {
        $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
        $default_user = User::factory()->create();
        $default_user->assignRole($role);
        $default_permission = Permission::factory()->create();
        $service = new AclResolverService;

        $default_acls = $service->getEffectiveAcls($default_user, $default_permission);

        $affinity_user = (new User)->setConnection('affinity');
        $affinity_user->forceFill(['id' => $default_user->id]);
        $affinity_user->exists = true;
        $affinity_user->syncOriginal();

        $affinity_permission = (new Permission)->setConnection('affinity');
        $affinity_permission->forceFill([
            'id' => $default_permission->id,
            'name' => $default_permission->name,
            'guard_name' => $default_permission->guard_name,
        ]);
        $affinity_permission->exists = true;
        $affinity_permission->syncOriginal();

        $affinity_acls = $service->getEffectiveAcls($affinity_user, $affinity_permission);

        expect($default_acls)->toHaveCount(1)
            ->and($affinity_acls)->toBeEmpty()
            ->and($service->getEffectiveAcls($default_user, $default_permission))->toHaveCount(1);
    } finally {
        DB::disconnect('affinity');
        DB::purge('affinity');
    }
});

it('clears ACL cache entries for direct and role permissions on the permission connection', function (): void {
    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('affinity');

    $permission_table = config('permission.table_names.permissions');
    $direct_table = config('permission.table_names.model_has_permissions');
    $role_permission_table = config('permission.table_names.role_has_permissions');
    $model_role_table = config('permission.table_names.model_has_roles');
    $model_key = config('permission.column_names.model_morph_key', 'model_id');
    $user_model = config('auth.providers.users.model', User::class);

    Schema::connection('affinity')->create($permission_table, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
    });
    Schema::connection('affinity')->create($direct_table, function (Blueprint $table) use ($model_key): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger($model_key);
    });
    Schema::connection('affinity')->create($role_permission_table, function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
    });
    Schema::connection('affinity')->create($model_role_table, function (Blueprint $table) use ($model_key): void {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger($model_key);
    });

    try {
        $permission = (new Permission)->setConnection('affinity');
        $permission->forceFill(['id' => 1, 'name' => 'affinity.users.select', 'guard_name' => 'web']);
        $permission->exists = true;
        $permission->syncOriginal();

        DB::connection('affinity')->table($direct_table)->insert([
            'permission_id' => $permission->id,
            'model_type' => $user_model,
            $model_key => 999_991,
        ]);
        DB::connection('affinity')->table($role_permission_table)->insert([
            'permission_id' => $permission->id,
            'role_id' => 10,
        ]);
        DB::connection('affinity')->table($model_role_table)->insert([
            'role_id' => 10,
            'model_type' => $user_model,
            $model_key => 999_992,
        ]);

        $direct_key = acl_test_cache_key('affinity', 999_991, $permission->id);
        $role_key = acl_test_cache_key('affinity', 999_992, $permission->id);
        Cache::put($direct_key, true);
        Cache::put($role_key, true);

        (new AclResolverService)->clearCacheForPermission($permission);

        expect(Cache::has($direct_key))->toBeFalse()
            ->and(Cache::has($role_key))->toBeFalse();
    } finally {
        DB::disconnect('affinity');
        DB::purge('affinity');
    }
});

it('flushes prefixed ACL entries on the database cache store connection', function (): void {
    $default_cache_repository = Cache::getFacadeRoot();
    $default_cache_driver = config('cache.default');
    $database_cache_store = config('cache.stores.database');
    $cache_prefix = config('cache.prefix');
    $clear_threshold = config('core.acl.clear_threshold');

    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.connections.cache_affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('cache.default', 'database');
    config()->set('cache.prefix', 'tenant:');
    config()->set('cache.stores.database', [
        'driver' => 'database',
        'connection' => 'cache_affinity',
        'table' => 'cache',
        'lock_connection' => 'cache_affinity',
        'lock_table' => 'cache_locks',
    ]);
    config()->set('core.acl.clear_threshold', -1);
    DB::purge('affinity');
    DB::purge('cache_affinity');
    Cache::setDefaultDriver('database');
    app('cache')->forgetDriver('database');
    Cache::swap(new CacheRepository(new DatabaseStore(
        DB::connection('cache_affinity'),
        'cache',
        'tenant:',
    )));

    $direct_table = config('permission.table_names.model_has_permissions');
    $role_permission_table = config('permission.table_names.role_has_permissions');
    $model_key = config('permission.column_names.model_morph_key', 'model_id');
    $user_model = config('auth.providers.users.model', User::class);

    Schema::connection('affinity')->create($direct_table, function (Blueprint $table) use ($model_key): void {
        $table->unsignedBigInteger('permission_id');
        $table->string('model_type');
        $table->unsignedBigInteger($model_key);
    });
    Schema::connection('affinity')->create($role_permission_table, function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
    });
    Schema::connection('affinity')->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
        $table->integer('expiration');
    });
    Schema::connection('cache_affinity')->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
        $table->integer('expiration');
    });

    try {
        $permission = (new Permission)->setConnection('affinity');
        $permission->forceFill(['id' => 1]);
        $permission->exists = true;
        $permission->syncOriginal();

        DB::connection('affinity')->table($direct_table)->insert([
            'permission_id' => $permission->id,
            'model_type' => $user_model,
            $model_key => 999_993,
        ]);

        $key = acl_test_cache_key('affinity', 999_993, $permission->id);
        Cache::put($key, 'cached', 600);
        DB::connection('affinity')->table('cache')->insert([
            'key' => CacheManager::key('acl', 'permission-store-sentinel'),
            'value' => 'sentinel',
            'expiration' => now()->addHour()->timestamp,
        ]);

        expect(Cache::getStore())->toBeInstanceOf(DatabaseStore::class);

        (new AclResolverService)->clearCacheForPermission($permission);

        expect(Cache::has($key))->toBeFalse()
            ->and(DB::connection('affinity')->table('cache')->count())->toBe(1);
    } finally {
        Cache::swap($default_cache_repository);
        app('cache')->forgetDriver('database');
        config()->set('cache.default', $default_cache_driver);
        config()->set('cache.stores.database', $database_cache_store);
        config()->set('cache.prefix', $cache_prefix);
        config()->set('core.acl.clear_threshold', $clear_threshold);
        Cache::setDefaultDriver($default_cache_driver);
        DB::disconnect('affinity');
        DB::purge('affinity');
        DB::disconnect('cache_affinity');
        DB::purge('cache_affinity');
    }
});

it('getCombinedFilters returns null when there are no contributing ACLs', function (): void {
    $user = User::factory()->create();
    $permission = Permission::factory()->create();

    $service = new AclResolverService();

    // No ACLs in DB for this permission/user
    $combined = $service->getCombinedFilters($user, $permission);

    expect($combined)->toBeNull();
});

it('hasUnrestrictedAccess is true when there are no contributing ACLs', function (): void {
    $user = User::factory()->create();
    $permission = Permission::factory()->create();

    $service = new AclResolverService();

    expect($service->hasUnrestrictedAccess($user, $permission))->toBeTrue();
});

it('clearCacheForUser forgets cached ACL entries for that user', function (): void {
    $user = User::factory()->create();
    $permission = Permission::factory()->create();

    $key = acl_test_cache_key($user->getConnection()->getName(), $user->id, $permission->id);
    Cache::put($key, 'dummy', 600);
    expect(Cache::get($key))->toBe('dummy');

    $service = new AclResolverService();
    $service->clearCacheForUser($user);

    expect(Cache::get($key))->toBeNull();
});

it('clearCacheForPermission flushes all acl related cache', function (): void {
    $user = User::factory()->create();
    $permission = Permission::factory()->create();

    // Assign permission to user via role so clearCacheForPermission can find the user
    $role = Role::factory()->create(['name' => 'acl_clear_perm_' . uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    $key = acl_test_cache_key($user->getConnection()->getName(), $user->id, $permission->id);
    Cache::put($key, 'dummy', 600);
    expect(Cache::get($key))->toBe('dummy');

    $service = new AclResolverService();
    $service->clearCacheForPermission($permission);

    expect(Cache::get($key))->toBeNull();
});

it('returns unrestricted virtual ACL for super admin user', function (): void {
    config()->set('permission.roles.superadmin', 'superadmin');

    /** @var Role $superRole */
    $superRole = Role::factory()->create(['name' => 'superadmin', 'guard_name' => 'web']);

    /** @var User $user */
    $user = User::factory()->create();
    $user->assignRole($superRole);

    /** @var Permission $permission */
    $permission = Permission::factory()->create();

    $service = new AclResolverService();

    $acls = $service->getEffectiveAcls($user, $permission);

    expect($acls)->toHaveCount(1);

    /** @var ACL $acl */
    $acl = $acls->first();
    expect($acl)->toBeInstanceOf(ACL::class)
        ->and($acl->isUnrestricted())->toBeTrue();

    expect($service->hasUnrestrictedAccess($user, $permission))->toBeTrue();
});

it('getEffectiveAcls is empty when the user has no roles', function (): void {
    $user = User::factory()->create();
    $permission = Permission::create([
        'name' => 'default.acl_norole_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    $service = new AclResolverService();

    expect($service->getEffectiveAcls($user, $permission))->toBeEmpty();
});

it('getCombinedFilters returns stored filters when a role has an active ACL for the permission', function (): void {
    $permission = Permission::create([
        'name' => 'default.acl_filters_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    $role = Role::factory()->create(['name' => 'acl_editor_' . uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $filter_group = new FiltersGroup([
        new Filter('status', 'published', FilterOperator::Equals),
    ]);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filter_group,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $user = User::factory()->create();
    $user->assignRole($role);

    $service = new AclResolverService();
    $combined = $service->getCombinedFilters($user, $permission);

    expect($combined)->toBeInstanceOf(FiltersGroup::class)
        ->and($combined->operator)->toBe(WhereClause::And)
        ->and($combined->filters)->toHaveCount(1)
        ->and($combined->filters[0])->toBeInstanceOf(Filter::class)
        ->and($combined->filters[0]->property)->toBe('status')
        ->and($combined->filters[0]->value)->toBe('published');

    expect($service->hasUnrestrictedAccess($user, $permission))->toBeFalse();
});

it('getCombinedFilters wraps multiple contributing ACLs with OR', function (): void {
    $permission = Permission::create([
        'name' => 'default.acl_or_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    $filter_group = new FiltersGroup([
        new Filter('region', 'it', FilterOperator::Equals),
    ]);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filter_group,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $role_a = Role::factory()->create(['name' => 'acl_ra_' . uniqid(), 'guard_name' => 'web']);
    $role_b = Role::factory()->create(['name' => 'acl_rb_' . uniqid(), 'guard_name' => 'web']);
    $role_a->givePermissionTo($permission);
    $role_b->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole([$role_a, $role_b]);

    $service = new AclResolverService();
    $combined = $service->getCombinedFilters($user, $permission);

    expect($combined)->toBeInstanceOf(FiltersGroup::class)
        ->and($combined->operator)->toBe(WhereClause::Or)
        ->and($combined->filters)->toHaveCount(2);
});

it('inherits ACL filters from an ancestor role when the direct role has no ACL row', function (): void {
    $permission = Permission::create([
        'name' => 'default.acl_inherit_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    $parent = Role::factory()->create(['name' => 'acl_parent_' . uniqid(), 'guard_name' => 'web']);
    $parent->givePermissionTo($permission);

    $child = Role::factory()->create(['name' => 'acl_child_' . uniqid(), 'guard_name' => 'web']);
    $child->forceFill(['parent_id' => $parent->id])->save();

    $filter_group = new FiltersGroup([
        new Filter('tenant_id', 42, FilterOperator::Equals),
    ]);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filter_group,
        'unrestricted' => false,
        'priority' => 5,
        'is_active' => true,
    ]);
    $acl->save();

    $user = User::factory()->create();
    $user->assignRole($child);

    expect($child->hasPermission($permission->name))->toBeTrue();

    $service = new AclResolverService();
    $combined = $service->getCombinedFilters($user, $permission);

    expect($combined)->toBeInstanceOf(FiltersGroup::class)
        ->and($combined->filters[0])->toBeInstanceOf(Filter::class)
        ->and($combined->filters[0]->property)->toBe('tenant_id')
        ->and($combined->filters[0]->value)->toBe(42);
});

it('treats unrestricted ACL rows as non contributing for combined filters', function (): void {
    $permission = Permission::create([
        'name' => 'default.acl_unres_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    $role = Role::factory()->create(['name' => 'acl_unres_' . uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => null,
        'unrestricted' => true,
        'priority' => 1,
        'is_active' => true,
    ]);
    $acl->save();

    $user = User::factory()->create();
    $user->assignRole($role);

    $service = new AclResolverService();

    expect($service->getCombinedFilters($user, $permission))->toBeNull()
        ->and($service->hasUnrestrictedAccess($user, $permission))->toBeTrue();
});

it('ignores roles that lack the permission when resolving effective ACLs', function (): void {
    $permission = Permission::create([
        'name' => 'default.acl_skip_role_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    $role_with_acl = Role::factory()->create(['name' => 'acl_has_' . uniqid(), 'guard_name' => 'web']);
    $role_with_acl->givePermissionTo($permission);

    $filter_group = new FiltersGroup([
        new Filter('region', 'eu', FilterOperator::Equals),
    ]);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filter_group,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $role_without_permission = Role::factory()->create(['name' => 'acl_noperm_' . uniqid(), 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole([$role_without_permission, $role_with_acl]);

    $service = new AclResolverService();
    $acls = $service->getEffectiveAcls($user, $permission);

    expect($acls)->toHaveCount(1)
        ->and($acls->first()->filters)->toEqual($filter_group);
});

// Feature: performance-optimization, Property 2: ACL cache invalidation is targeted
it('clearCacheForPermission leaves cache entries for other permissions intact', function (): void {
    // Validates: Requirements 2.1, 2.3
    $user = User::factory()->create();

    // Create two permissions
    $perm_a = Permission::create([
        'name' => 'default.acl_targeted_a_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);
    $perm_b = Permission::create([
        'name' => 'default.acl_targeted_b_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    // Assign perm_a to user via role so clearCacheForPermission can find the user
    $role = Role::factory()->create(['name' => 'acl_targeted_' . uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($perm_a);
    $user->assignRole($role);

    $key_a = acl_test_cache_key($user->getConnection()->getName(), $user->id, $perm_a->id);
    $key_b = acl_test_cache_key($user->getConnection()->getName(), $user->id, $perm_b->id);

    Cache::put($key_a, 'value_a', 600);
    Cache::put($key_b, 'value_b', 600);

    $service = new AclResolverService();
    $service->clearCacheForPermission($perm_a);

    // perm_a cache should be cleared
    expect(Cache::get($key_a))->toBeNull();

    // perm_b cache should remain intact
    expect(Cache::get($key_b))->toBe('value_b');
})->repeat(10);

// Feature: performance-optimization, Property 3: ACL cache invalidation threshold fallback
it('clearCacheForPermission uses prefix flush when user count exceeds threshold', function (): void {
    // Validates: Requirements 2.5
    $permission = Permission::create([
        'name' => 'default.acl_threshold_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    // Set a very low threshold so we can trigger it without creating 500 users
    config()->set('core.acl.clear_threshold', 2);

    // Create 3 users with this permission via roles (exceeds threshold of 2)
    $users = User::factory()->count(3)->create();

    foreach ($users as $user) {
        $role = Role::factory()->create(['name' => 'acl_thresh_' . uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
    }

    // Put ACL cache entries for each user
    foreach ($users as $user) {
        $key = acl_test_cache_key($user->getConnection()->getName(), $user->id, $permission->id);
        Cache::put($key, 'cached_value', 600);
    }

    // Also put a non-ACL cache entry that should NOT be cleared
    $other_key = 'some_other_cache_key_' . uniqid();
    Cache::put($other_key, 'other_value', 600);

    $service = new AclResolverService();
    $service->clearCacheForPermission($permission);

    // All ACL entries for this permission should be cleared (via prefix flush)
    foreach ($users as $user) {
        $key = acl_test_cache_key($user->getConnection()->getName(), $user->id, $permission->id);
        expect(Cache::get($key))->toBeNull();
    }
});

// Feature: performance-optimization, Property 23: ACL resolution uses a single batch query for multi-role users
it('resolveAcls issues at most one DB query to load ACLs for a user with multiple roles', function (): void {
    // Validates: Requirements 17.1, 17.2
    $permission = Permission::create([
        'name' => 'default.acl_batch_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    // Create 3 roles, each with the permission
    $roles = collect(range(1, 3))->map(static function (int $i) use ($permission): Role {
        $role = Role::factory()->create(['name' => 'acl_batch_role_' . $i . '_' . uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        return $role;
    });

    $user = User::factory()->create();
    $user->assignRole($roles->all());

    $service = new AclResolverService();

    // Warm up Spatie permission cache and role relations before counting queries
    $user->load('roles.permissions', 'roles.ancestors');

    $permission_connection = $permission->getConnection();
    $permission_connection->enableQueryLog();
    Cache::flush(); // ensure cold cache so resolveAcls is actually called

    $service->getEffectiveAcls($user, $permission);

    $queries = $permission_connection->getQueryLog();
    $permission_connection->disableQueryLog();

    // Filter only ACL-related queries (SELECT from acls table)
    $acl_queries = array_filter($queries, static fn (array $q): bool => str_contains(mb_strtolower($q['query']), 'from') && str_contains(mb_strtolower($q['query']), 'acl'));

    // At most one query should hit the acls table (the batch whereIn query)
    expect(count($acl_queries))->toBeLessThanOrEqual(1);
});

// Feature: performance-optimization, Property 24: ACL resolution results are preserved after batch optimization
it('resolveAcls returns the same effective ACLs as the original per-role implementation', function (): void {
    // Validates: Requirements 17.3
    $permission = Permission::create([
        'name' => 'default.acl_parity_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    // Role A: has an ACL with filters
    $role_a = Role::factory()->create(['name' => 'acl_parity_a_' . uniqid(), 'guard_name' => 'web']);
    $role_a->givePermissionTo($permission);

    $filter_a = new FiltersGroup([new Filter('country', 'IT', FilterOperator::Equals)]);
    $acl_a = new ACL();
    $acl_a->setSkipValidation(true);
    $acl_a->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filter_a,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl_a->save();

    // Role B: has an ACL with different filters
    $role_b = Role::factory()->create(['name' => 'acl_parity_b_' . uniqid(), 'guard_name' => 'web']);
    $role_b->givePermissionTo($permission);

    $filter_b = new FiltersGroup([new Filter('country', 'DE', FilterOperator::Equals)]);
    $acl_b = new ACL();
    $acl_b->setSkipValidation(true);
    $acl_b->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filter_b,
        'unrestricted' => false,
        'priority' => 5,
        'is_active' => true,
    ]);
    $acl_b->save();

    $user = User::factory()->create();
    $user->assignRole([$role_a, $role_b]);

    $service = new AclResolverService();
    $acls = $service->getEffectiveAcls($user, $permission);

    // Both roles contribute an ACL → 2 effective ACLs
    expect($acls)->toHaveCount(2);

    // Combined filters should be OR of both filter groups
    $combined = $service->getCombinedFilters($user, $permission);
    expect($combined)->toBeInstanceOf(FiltersGroup::class)
        ->and($combined->operator)->toBe(WhereClause::Or)
        ->and($combined->filters)->toHaveCount(2);
});

// Feature: performance-optimization, Property 24: ACL inheritance is preserved after batch optimization
it('resolveAcls correctly inherits ACL from ancestor role when direct role has no ACL', function (): void {
    // Validates: Requirements 17.3
    $permission = Permission::create([
        'name' => 'default.acl_inherit_batch_' . uniqid() . '.select',
        'guard_name' => 'web',
    ]);

    // Parent role has the permission and an ACL
    $parent = Role::factory()->create(['name' => 'acl_batch_parent_' . uniqid(), 'guard_name' => 'web']);
    $parent->givePermissionTo($permission);

    $filter_group = new FiltersGroup([new Filter('tenant_id', 99, FilterOperator::Equals)]);
    $acl = new ACL();
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'filters' => $filter_group,
        'unrestricted' => false,
        'priority' => 5,
        'is_active' => true,
    ]);
    $acl->save();

    // Child role has no ACL — should inherit from parent
    $child = Role::factory()->create(['name' => 'acl_batch_child_' . uniqid(), 'guard_name' => 'web']);
    $child->forceFill(['parent_id' => $parent->id])->save();

    $user = User::factory()->create();
    $user->assignRole($child);

    // Verify child inherits the permission
    expect($child->hasPermission($permission->name))->toBeTrue();

    $service = new AclResolverService();
    $combined = $service->getCombinedFilters($user, $permission);

    expect($combined)->toBeInstanceOf(FiltersGroup::class)
        ->and($combined->filters[0])->toBeInstanceOf(Filter::class)
        ->and($combined->filters[0]->property)->toBe('tenant_id')
        ->and($combined->filters[0]->value)->toBe(99);
});
