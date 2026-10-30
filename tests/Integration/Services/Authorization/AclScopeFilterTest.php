<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\RelationFilter;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\License;
use Modules\Core\Models\Media;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\AclResolverService;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\ConditionalScopeFilterStubModel;
use Modules\Core\Tests\Stubs\ImpureScopeFilterStubModel;
use Modules\Core\Tests\Stubs\ScopeFilterStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Cache::flush();
    Carbon::setTestNow('2026-08-19 12:00:00');
    config()->set('permission.roles.superadmin', 'superadmin');
    FakeVectorProfile::none();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A user whose role on the `api` guard holds the `select` permission named for the model class, narrowed by an ACL
 * saved without validation (as a stored ACL that later stopped resolving would be).
 *
 * @param  class-string<Model>  $model_class
 * @return array{0: App\Models\User, 1: string, 2: ACL}
 */
function aclscope_reader(FiltersGroup $filters, string $model_class = License::class, ?string $permission_name = null): array
{
    $permission_name ??= PermissionName::forClass($model_class, 'select');
    Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'web']);
    $permission = Permission::query()->firstOrCreate(['name' => $permission_name, 'guard_name' => 'api']);

    $role = Role::factory()->create(['name' => 'aclscope_reader_' . uniqid(), 'guard_name' => 'api']);
    $role->givePermissionTo($permission);

    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill([
        'permission_id' => $permission->id,
        'role_id' => $role->id,
        'filters' => $filters,
        'unrestricted' => false,
        'priority' => 10,
        'is_active' => true,
    ]);
    $acl->save();

    $user = App\Models\User::query()->findOrFail(User::factory()->create(['name' => 'aclscope-reader'])->getKey());
    $user->assignRole($role);

    return [$user->fresh(), $permission_name, $acl];
}

/**
 * Acts as the reader on the `api` guard, as the API middleware does, for the ACL-checked reads called directly.
 */
function aclscope_act_as(App\Models\User $reader): void
{
    Auth::shouldUse('api');
    Auth::guard('api')->setUser($reader);
}

/**
 * Three licenses: valid now, not yet valid, expired.
 *
 * @return array{valid: License, scheduled: License, expired: License}
 */
function aclscope_licenses(): array
{
    return [
        'valid' => License::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]),
        'scheduled' => License::factory()->create(['valid_from' => now()->addWeek(), 'valid_to' => null]),
        'expired' => License::factory()->create(['valid_from' => now()->subWeeks(2), 'valid_to' => now()->subDay()]),
    ];
}

function aclscope_media(Model $owner): Media
{
    $media = new Media;
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'photo',
        'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 123,
        'model_type' => $owner->getMorphClass(),
        'model_id' => $owner->getKey(),
        'custom_properties' => [],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    return $media;
}

function aclscope_stub_table(): void
{
    Schema::create('scope_filter_stub', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->decimal('price')->default(0);
        $table->boolean('is_flagged')->default(false);
        $table->timestamp('released_at')->nullable();
        $table->timestamps();
    });
}

/**
 * @param  list<mixed>  $ids
 * @return list<int>
 */
function aclscope_ids(array $ids): array
{
    $ids = array_map(static fn (mixed $id): int => (int) $id, $ids);
    sort($ids);

    return $ids;
}

it('limits a CRUD list to the rows a scope ACL allows', function (): void {
    CrudApiExposure::enable();
    $licenses = aclscope_licenses();
    [$reader] = aclscope_reader(new FiltersGroup([new ScopeFilter('valid')]));

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/licenses');

    $response->assertOk();
    expect(aclscope_ids(collect($response->json('data'))->pluck('id')->all()))->toBe([$licenses['valid']->id]);
});

it('limits a CRUD detail to the rows a scope ACL allows', function (): void {
    CrudApiExposure::enable();
    $licenses = aclscope_licenses();
    [$reader] = aclscope_reader(new FiltersGroup([new ScopeFilter('valid')]));

    $this->actingAs($reader)->getJson('/api/v1/detail/core/licenses?id=' . $licenses['valid']->id)
        ->assertOk()
        ->assertJsonPath('data.id', $licenses['valid']->id);
    $this->actingAs($reader)->getJson('/api/v1/detail/core/licenses?id=' . $licenses['expired']->id)
        ->assertNotFound();
});

it('limits an ACL-checked read to the rows a scope ACL allows', function (): void {
    $licenses = aclscope_licenses();
    [$reader, $permission_name] = aclscope_reader(new FiltersGroup([new ScopeFilter('valid')]));
    aclscope_act_as($reader);

    $query = License::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name);

    expect(aclscope_ids($query->pluck('id')->all()))->toBe([$licenses['valid']->id]);
});

it('keeps the scope conditions in their own group beside a column filter of an OR group', function (): void {
    $licenses = aclscope_licenses();
    $filters = new FiltersGroup([
        new Filter('uuid', $licenses['scheduled']->uuid, FilterOperator::Equals),
        new ScopeFilter('valid'),
    ], WhereClause::Or);
    [$reader, $permission_name] = aclscope_reader($filters);
    aclscope_act_as($reader);

    $acl_query = License::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($acl_query, $permission_name);
    $crud_query = License::query();
    new QueryBuilder()->applyFilters($crud_query, $filters);

    $expected = aclscope_ids([$licenses['valid']->id, $licenses['scheduled']->id]);

    expect(aclscope_ids($acl_query->pluck('id')->all()))->toBe($expected)
        ->and(aclscope_ids($crud_query->pluck('id')->all()))->toBe($expected)
        ->and($crud_query->toSql())->toContain('"uuid" = ? or ("core_licenses"."valid_from" <= ? and ("core_licenses"."valid_to" >= ? or "core_licenses"."valid_to" is null))')
        ->and($acl_query->toSql())->toContain('or ("core_licenses"."valid_from" <= ? and ("core_licenses"."valid_to" >= ? or "core_licenses"."valid_to" is null))');
});

it('keeps a scope that ORs its own conditions from widening the AND group it sits in', function (): void {
    aclscope_stub_table();
    ScopeFilterStubModel::query()->insert([
        ['name' => 'alpha', 'price' => 10],
        ['name' => 'beta', 'price' => 1],
        ['name' => 'gamma', 'price' => 10],
    ]);
    $filters = new FiltersGroup([
        new Filter('price', 5, FilterOperator::GreatEquals),
        new ScopeFilter('eitherName', ['alpha', 'beta']),
    ]);
    [$reader, $permission_name] = aclscope_reader($filters, permission_name: 'default.scope_filter_stub.select');
    aclscope_act_as($reader);

    $acl_query = ScopeFilterStubModel::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($acl_query, $permission_name);
    $crud_query = ScopeFilterStubModel::query();
    new QueryBuilder()->applyFilters($crud_query, $filters);

    expect($acl_query->pluck('name')->all())->toBe(['alpha'])
        ->and($crud_query->pluck('name')->all())->toBe(['alpha']);
});

it('narrows the related records of a relation filter with a scope of the related model', function (): void {
    CrudApiExposure::enable();
    $valid_owner = User::factory()->perpetual()->create();
    $expired_owner = User::factory()->expired()->create();
    $kept = License::factory()->create();
    $dropped = License::factory()->create();
    $valid_owner->forceFill(['license_id' => $kept->id])->saveQuietly();
    $expired_owner->forceFill(['license_id' => $dropped->id])->saveQuietly();
    License::factory()->create();

    $filters = new FiltersGroup([new RelationFilter('user', new FiltersGroup([new ScopeFilter('valid')]))]);
    [$reader, $permission_name] = aclscope_reader($filters);

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/licenses');

    $response->assertOk();
    expect(aclscope_ids(collect($response->json('data'))->pluck('id')->all()))->toBe([$kept->id]);

    aclscope_act_as($reader);
    $query = License::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name);

    expect(aclscope_ids($query->pluck('id')->all()))->toBe([$kept->id]);
});

it('narrows the related records of a morph relation filter with a scope of the morph type', function (): void {
    CrudApiExposure::enable();
    $kept = aclscope_media(User::factory()->perpetual()->create());
    aclscope_media(User::factory()->expired()->create());
    aclscope_media(User::factory()->scheduled()->create());

    $filters = new FiltersGroup([
        new RelationFilter('model', new FiltersGroup([new ScopeFilter('valid')]), [User::class]),
    ]);
    [$reader, $permission_name] = aclscope_reader($filters, Media::class);

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/media');

    $response->assertOk();
    expect(aclscope_ids(collect($response->json('data'))->pluck('id')->all()))->toBe([$kept->id]);

    aclscope_act_as($reader);
    $query = Media::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name);

    expect(aclscope_ids($query->pluck('id')->all()))->toBe([$kept->id]);
});

it('resolves an @now parameter of a scope ACL on a CRUD list and on an ACL-checked read', function (): void {
    CrudApiExposure::enable();
    $licenses = aclscope_licenses();
    [$reader, $permission_name] = aclscope_reader(new FiltersGroup([new ScopeFilter('validAt', ['@now'])]));

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/licenses');

    $response->assertOk();
    expect(aclscope_ids(collect($response->json('data'))->pluck('id')->all()))->toBe([$licenses['valid']->id]);

    aclscope_act_as($reader);
    $query = License::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name);

    expect(aclscope_ids($query->pluck('id')->all()))->toBe([$licenses['valid']->id]);
});

it('resolves an @user.<attribute> parameter of a scope ACL', function (): void {
    aclscope_stub_table();
    ScopeFilterStubModel::query()->insert([['name' => 'aclscope-reader'], ['name' => 'someone-else']]);
    [$reader, $permission_name] = aclscope_reader(
        new FiltersGroup([new ScopeFilter('named', ['@user.name'])]),
        permission_name: 'default.scope_filter_stub.select',
    );
    aclscope_act_as($reader);

    $query = ScopeFilterStubModel::query();
    app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name);

    expect($query->pluck('name')->all())->toBe(['aclscope-reader']);
});

it('denies a CRUD list when a scope of the ACL no longer resolves, logging the ACL id and never the parameter values', function (ScopeFilter $scope): void {
    CrudApiExposure::enable();
    aclscope_licenses();
    [$reader, , $acl] = aclscope_reader(new FiltersGroup([$scope]));
    Log::spy();

    $response = $this->actingAs($reader)->getJson('/api/v1/select/core/licenses');

    $response->assertForbidden();
    expect($response->json('data'))->toBeEmpty();

    Log::shouldHaveReceived('warning')->once()->withArgs(static function (string $message, array $context) use ($acl): bool {
        $logged = $message . json_encode($context);

        return ($context['acl_id'] ?? null) === $acl->id && ! str_contains($logged, 'secret-value');
    });
})->with([
    'undeclared scope' => [new ScopeFilter('validityOrdered')],
    'unknown scope' => [new ScopeFilter('truncate')],
    'bad parameter' => [new ScopeFilter('validAt', ['secret-value'])],
    'extra parameter' => [new ScopeFilter('valid', ['secret-value'])],
]);

it('denies an ACL-checked read and the resolved ACL filters when a scope inside a relation filter no longer resolves', function (string $model_class, RelationFilter $relation_filter): void {
    [$reader, $permission_name, $acl] = aclscope_reader(new FiltersGroup([$relation_filter]), $model_class);
    aclscope_act_as($reader);
    Log::spy();

    expect(fn () => app(AuthorizationService::class)->applyAclFiltersToQuery($model_class::query(), $permission_name))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(AuthorizationService::class)->resolvedAclFilters($permission_name, new $model_class))
        ->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')->twice()->withArgs(static fn (string $message, array $context): bool => ($context['acl_id'] ?? null) === $acl->id
        && ($context['model'] ?? null) === User::class
        && ! str_contains($message . json_encode($context), 'secret-value'));
})->with([
    'relation' => [License::class, new RelationFilter('user', new FiltersGroup([new ScopeFilter('validAt', ['secret-value'])]))],
    'morph relation' => [Media::class, new RelationFilter('model', new FiltersGroup([new ScopeFilter('validAt', ['secret-value'])]), [User::class])],
]);

it('denies an ACL-checked read when a scope of the ACL does more than add conditions or fails when it runs', function (string $model_class, ScopeFilter $scope): void {
    aclscope_stub_table();
    [$reader, $permission_name, $acl] = aclscope_reader(new FiltersGroup([$scope]), permission_name: 'default.scope_filter_stub.select');
    aclscope_act_as($reader);
    Log::spy();

    expect(fn () => app(AuthorizationService::class)->applyAclFiltersToQuery($model_class::query(), $permission_name))
        ->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => ($context['acl_id'] ?? null) === $acl->id);
})->with([
    'joins' => [ImpureScopeFilterStubModel::class, new ScopeFilter('joined')],
    'orders' => [ImpureScopeFilterStubModel::class, new ScopeFilter('ordered')],
    'throws its own exception' => [ScopeFilterStubModel::class, new ScopeFilter('releasedWithin', [1])],
]);

it('denies an ACL read whose scope adds no condition for the acting user instead of reading every row', function (): void {
    aclscope_stub_table();
    ConditionalScopeFilterStubModel::query()->insert([['name' => 'alpha'], ['name' => 'beta']]);
    [$reader, $permission_name, $acl] = aclscope_reader(
        new FiltersGroup([new ScopeFilter('ofName', ['@user.department_code'])]),
        permission_name: 'default.scope_filter_stub.select',
    );
    aclscope_act_as($reader);
    Log::spy();
    $query = ConditionalScopeFilterStubModel::query();

    expect(fn () => app(AuthorizationService::class)->applyAclFiltersToQuery($query, $permission_name))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(AuthorizationService::class)->resolvedAclFilters($permission_name, new ConditionalScopeFilterStubModel))
        ->toThrow(AuthorizationException::class)
        ->and($query->getQuery()->wheres)->toBe([]);

    Log::shouldHaveReceived('warning')->twice()->withArgs(static fn (string $message, array $context): bool => ($context['acl_id'] ?? null) === $acl->id
        && str_contains((string) ($context['reason'] ?? ''), 'adds no condition'));
});

it('gives the CRUD query builder the ACL filters with their placeholders resolved', function (): void {
    $licenses = aclscope_licenses();
    [$reader, $permission_name] = aclscope_reader(new FiltersGroup([
        new Filter('valid_from', '@now', FilterOperator::LessEquals),
        new ScopeFilter('validAt', ['@today']),
    ]));
    aclscope_act_as($reader);

    $resolved = app(AuthorizationService::class)->resolvedAclFilters($permission_name, new License);

    expect($resolved->filters[0]->value)->toBeInstanceOf(Carbon::class)
        ->and($resolved->filters[1])->toBeInstanceOf(ScopeFilter::class)
        ->and($resolved->filters[1]->parameters[0])->toBeInstanceOf(Carbon::class);

    $query = License::query();
    new QueryBuilder()->applyFilters($query, $resolved);

    expect(aclscope_ids($query->pluck('id')->all()))->toBe([$licenses['valid']->id]);
});

it('denies every ACL read whose filters hold a node of an unknown kind instead of skipping it', function (): void {
    [$reader, $permission_name] = aclscope_reader(new FiltersGroup([new Filter('id', 1, FilterOperator::Equals)]));
    aclscope_act_as($reader);
    $filters = new FiltersGroup([new Filter('id', 1, FilterOperator::Equals), new FiltersGroup([new stdClass])]);
    // A stored ACL cannot hold such a node (the cast refuses it): the resolver hands it over as a future node kind would.
    $acl = Mockery::mock(ACL::class)->makePartial();
    $acl->shouldReceive('getAttribute')->with('filters')->andReturn($filters);
    $acl->shouldReceive('getKey')->andReturn(4242);
    $acl->shouldReceive('isUnrestricted')->andReturnFalse();
    $acl->shouldReceive('hasFilters')->andReturnTrue();
    $resolver = Mockery::mock(AclResolverService::class);
    $resolver->shouldReceive('getEffectiveAcls')->andReturn(collect([$acl]));
    $resolver->shouldReceive('getCombinedFilters')->andReturn($filters);
    $service = new AuthorizationService($resolver);
    $request_data = new ReflectionClass(ListRequestData::class)->newInstanceWithoutConstructor();
    Log::spy();

    expect(fn () => $service->applyAclFiltersToQuery(License::query(), $permission_name))->toThrow(AuthorizationException::class);
    expect(fn () => $service->resolvedAclFilters($permission_name, new License))->toThrow(AuthorizationException::class);
    expect(fn () => $service->injectAclFilters($request_data, $permission_name))->toThrow(AuthorizationException::class);

    Log::shouldHaveReceived('warning')->times(3)->withArgs(static fn (string $message, array $context): bool => ($context['acl_id'] ?? null) === 4242);
});
