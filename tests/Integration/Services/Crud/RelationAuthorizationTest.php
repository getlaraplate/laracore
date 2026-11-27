<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User as CoreUser;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Services\Crud\RelationAuthorizer;
use Modules\Core\Support\CrudApiExposure;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Crud\RelationElsewhereStub;
use Modules\Core\Tests\Stubs\Crud\RelationMisdeclaredPartStub;
use Modules\Core\Tests\Stubs\Crud\RelationOwnerStub;
use Modules\Core\Tests\Stubs\Crud\RelationPartStub;
use Modules\Core\Tests\Stubs\Search\AclRehydrationUserStub;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;

/*
 * Related records obey their own permission and ACL (spec 8.1): a relation the caller asks for is loaded only
 * when the caller may select the related entity, on the request's guard, and its query receives that entity's
 * ACL, exactly as a root query does. Every relation name taken from a request is checked before anything calls it.
 */

beforeEach(function (): void {
    Cache::flush();
    CrudApiExposure::enable();

    // As `permission:refresh` does: the select permissions exist on both guards, granted to nobody. An entity
    // with no permission registered at all is outside the permission scheme and is not checked.
    foreach ([CoreUser::class, Role::class] as $model_class) {
        foreach (['web', 'api'] as $guard) {
            Permission::query()->firstOrCreate(['name' => PermissionName::forClass($model_class, 'select'), 'guard_name' => $guard]);
        }
    }
});

/**
 * A user whose `api` role holds the select permission of each given model, narrowed by its ACL when one is given.
 *
 * @param  array<class-string<Model>, FiltersGroup|null>  $grants
 */
function relauth_reader(array $grants): User
{
    $role = Role::factory()->create(['name' => 'relauth_reader_' . uniqid(), 'guard_name' => 'api']);

    foreach ($grants as $model_class => $filters) {
        $name = PermissionName::forClass($model_class, 'select');
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        $role->givePermissionTo($permission);

        if ($filters instanceof FiltersGroup) {
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
        }
    }

    $user = User::query()->findOrFail(CoreUser::factory()->create()->getKey());
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Gives the reader's role one more permission on the model, on the api guard.
 *
 * @param  class-string<Illuminate\Database\Eloquent\Model>  $model_class
 */
function relauth_grant(User $reader, string $model_class, string $verb): void
{
    $name = PermissionName::forClass($model_class, $verb);
    Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    $reader->roles->firstOrFail()->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']));
}

/**
 * The roles ACL that lets a reader see only the role named `relauth_visible`.
 */
function relauth_visible_roles(): FiltersGroup
{
    return new FiltersGroup([new Filter('name', ['relauth_visible'], FilterOperator::In)], WhereClause::And);
}

/**
 * A user holding one role the reader may see and one it may not. The roles pivot is keyed by the morph class,
 * so the user is assigned through the class the reading endpoint hydrates: the Core user for the CRUD routes,
 * the application user for the search stub.
 *
 * @param  class-string<CoreUser>  $class
 */
function relauth_user_with_two_roles(string $class = CoreUser::class): CoreUser
{
    $visible = Role::factory()->create(['name' => 'relauth_visible', 'guard_name' => 'web']);
    $hidden = Role::factory()->create(['name' => 'relauth_hidden', 'guard_name' => 'web']);

    $user = $class::query()->findOrFail(CoreUser::factory()->create()->getKey());
    $user->assignRole($visible, $hidden);

    return $user;
}

/**
 * @param  array<string, mixed>  $query
 */
function relauth_url(string $action, array $query): string
{
    return sprintf('/api/v1/%s/core/users?%s', $action, http_build_query($query));
}

/**
 * The role names loaded on the response row of the given user.
 *
 * @param  array<int, array<string, mixed>>|array<string, mixed>  $data
 * @return list<string>
 */
function relauth_role_names(array $data, int|string $user_id): array
{
    $rows = array_is_list($data) ? $data : [$data];
    $row = collect($rows)->firstWhere('id', $user_id);

    return collect($row['roles'] ?? [])->pluck('name')->sort()->values()->all();
}

describe('request relation names', function (): void {
    it('answers a client error and leaves the table intact for a name that is not a relation', function (string $name, string $place): void {
        $reader = relauth_reader([CoreUser::class => null]);
        relauth_user_with_two_roles();
        $before = CoreUser::query()->count();
        $roles_before = Role::query()->count();

        $query = match ($place) {
            'filter' => ['filters' => [['property' => $name . '.id', 'operator' => 'eq', 'value' => 1]]],
            'column' => ['columns' => [$name . '.id']],
            'relation' => ['relations' => [$name]],
            'sort' => ['sort' => [['property' => $name . '.id', 'direction' => 'asc']]],
            'nested relation' => ['relations' => ['roles.' . $name]],
        };

        $response = $this->actingAs($reader)->getJson(relauth_url('select', $query));

        expect(CoreUser::query()->count())->toBe($before)
            ->and(Role::query()->count())->toBe($roles_before)
            ->and($response->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
    })->with(['truncate', 'delete'])->with(['filter', 'column', 'relation', 'sort', 'nested relation']);
});

describe('a relation whose entity the caller may not select', function (): void {
    it('is refused with 403', function (array $query, bool $detail_reads_it): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null]);

        $this->actingAs($reader)->getJson(relauth_url('select', $query))->assertForbidden();

        if ($detail_reads_it) {
            $this->actingAs($reader)->getJson(relauth_url('detail', ['id' => $target->getKey()] + $query))->assertForbidden();
        }
    })->with([
        'by relations' => [['relations' => ['roles']], true],
        'by a dotted column' => [['columns' => ['roles.name']], true],
        'by an aggregate' => [['columns' => [['name' => 'roles', 'type' => 'count']]], true],
        // A detail reads neither filters nor sorts.
        'by a relation filter' => [['filters' => [['property' => 'roles.name', 'operator' => 'eq', 'value' => 'relauth_visible']]], false],
        'by a relation-count filter' => [['filters' => [['property' => 'users.roles', 'operator' => 'ge', 'value' => 1]]], false],
        'by a sort' => [['sort' => [['property' => 'roles.name', 'direction' => 'asc']]], false],
    ]);

    it('is loaded once the caller holds the related select permission', function (): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null, Role::class => null]);

        $response = $this->actingAs($reader)->getJson(relauth_url('select', ['relations' => ['roles']]));

        $response->assertOk();
        expect(relauth_role_names($response->json('data'), $target->getKey()))->toBe(['relauth_hidden', 'relauth_visible']);
    });
});

describe('the related ACL', function (): void {
    it('keeps only the related rows it allows in a list, without a partial relations meta', function (): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null, Role::class => relauth_visible_roles()]);

        $response = $this->actingAs($reader)->getJson(relauth_url('select', ['relations' => ['roles']]));

        $response->assertOk();
        expect(relauth_role_names($response->json('data'), $target->getKey()))->toBe(['relauth_visible'])
            ->and($response->json('meta.relations'))->toBeNull();
    });

    it('keeps only the related rows it allows in a detail and declares how many it left out', function (): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null, Role::class => relauth_visible_roles()]);

        $response = $this->actingAs($reader)->getJson(relauth_url('detail', ['id' => $target->getKey(), 'relations' => ['roles']]));

        $response->assertOk();
        expect(relauth_role_names($response->json('data'), $target->getKey()))->toBe(['relauth_visible'])
            ->and($response->json('meta.relations.roles.hidden'))->toBe(1);
    });

    it('counts only the related rows it allows in an aggregate', function (): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null, Role::class => relauth_visible_roles()]);

        $response = $this->actingAs($reader)->getJson(relauth_url('select', ['columns' => [['name' => 'roles', 'type' => 'count']]]));

        $response->assertOk();
        expect(collect($response->json('data'))->firstWhere('id', $target->getKey())['roles_count'])->toBe(1);
    });

    it('narrows the existence check of a relation filter', function (): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null, Role::class => relauth_visible_roles()]);

        $response = $this->actingAs($reader)->getJson(relauth_url('select', [
            'filters' => [['property' => 'roles.name', 'operator' => 'eq', 'value' => 'relauth_hidden']],
        ]));

        $response->assertOk();
        expect(collect($response->json('data'))->pluck('id')->all())->not->toContain($target->getKey());
    });
});

/**
 * @param  list<int|string>  $ids
 */
function relauth_search_returning(array $ids): AdvancedSearchService
{
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturnUsing(static fn (Model $model, string $query, array $plan, ?array $vector, int $page, int $perPage): AdvancedSearchResult => new AdvancedSearchResult(
        hits: array_map(static fn (int|string $id): array => ['id' => (string) $id, 'score' => 1.0, 'source' => []], $ids),
        total: count($ids),
        page: $page,
        perPage: $perPage,
        totalPages: 1,
        meta: [],
    ));

    return new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new FallbackSearchPlanner, intent_parser: new SimpleQueryIntentParser),
        $ensemble,
        app(),
    );
}

/**
 * @param  list<string>  $relations
 */
function relauth_search_data(User $user, array $relations): SearchRequestData
{
    $model = new AclRehydrationUserStub;
    $request = new class(['qs' => 'needle']) extends Request
    {
        public function validated(?string $key = null, mixed $default = null): mixed
        {
            return $key === null ? $this->all() : ($this->all()[$key] ?? $default);
        }
    };
    $request->setUserResolver(static fn (): User => $user);

    $data = (new ReflectionClass(SearchRequestData::class))->newInstanceWithoutConstructor();

    foreach ([
        'request' => $request,
        'mainEntity' => $model->getTable(),
        'primaryKey' => $model->getKeyName(),
        'model' => $model,
        'connection' => $model->getConnectionName(),
        'columns' => [],
        'relations' => $relations,
        'sort' => [],
        'filters' => null,
        'group_by' => [],
        'qs' => 'needle',
        'mode' => SearchMode::Orchestrated,
        'page' => null,
        'limit' => 5,
        'pagination' => 5,
        'from' => null,
        'to' => null,
        'count' => false,
    ] as $property => $value) {
        (new ReflectionProperty($data, $property))->setValue($data, $value);
    }

    return $data;
}

/**
 * @param  list<int|string>  $hit_ids
 * @param  list<string>  $relations
 */
function relauth_search(array $hit_ids, User $user, array $relations): Modules\Core\Services\Crud\DTOs\CrudResult
{
    config()->set('scout.driver', 'elasticsearch');

    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();
    AclRehydrationUserStub::$engine = $engine;

    Auth::shouldUse('api');
    Auth::guard('api')->setUser($user);

    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class), relauth_search_returning($hit_ids));

    return $service->search(relauth_search_data($user, $relations));
}

describe('search', function (): void {
    it('refuses a relation whose entity the caller may not select', function (): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null]);

        expect(fn (): mixed => relauth_search([$target->getKey()], $reader, ['roles']))
            ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    });

    it('applies the related ACL to the relations of the hits', function (): void {
        $target = relauth_user_with_two_roles(User::class);
        $reader = relauth_reader([CoreUser::class => null, Role::class => relauth_visible_roles()]);

        $result = relauth_search([$target->getKey()], $reader, ['roles']);
        $row = $result->data->firstWhere('id', $target->getKey());

        expect($row->roles->pluck('name')->all())->toBe(['relauth_visible']);
    });

    it('drops a relation of the black list', function (): void {
        $target = relauth_user_with_two_roles();
        $reader = relauth_reader([CoreUser::class => null]);

        $result = relauth_search([$target->getKey()], $reader, ['history']);
        $row = $result->data->firstWhere('id', $target->getKey());

        expect($row->relationLoaded('history'))->toBeFalse();
    });
});

describe('parts of a parent', function (): void {
    beforeEach(function (): void {
        foreach (['web', 'api'] as $guard) {
            Permission::query()->firstOrCreate(['name' => PermissionName::forClass(RelationOwnerStub::class, 'select'), 'guard_name' => $guard]);
        }

        // The readers hold `api` roles: the checks run on the guard of an API request.
        Auth::shouldUse('api');
    });

    it('inherit the visibility of the parent they are loaded from', function (): void {
        $reader = relauth_reader([CoreUser::class => null]);
        $request = Request::create('/api/v1/select/core/users');
        $request->setUserResolver(static fn (): User => $reader);

        expect(app(RelationAuthorizer::class)->isReadable($request, new RelationOwnerStub, new RelationPartStub))->toBeTrue();
    });

    it('are checked against their parent when another model reaches them', function (): void {
        $authorizer = app(RelationAuthorizer::class);
        $request = Request::create('/api/v1/select/core/users');

        $without = relauth_reader([CoreUser::class => null]);
        $request->setUserResolver(static fn (): User => $without);
        expect($authorizer->isReadable($request, new RelationElsewhereStub, new RelationPartStub))->toBeFalse();

        $with = relauth_reader([RelationOwnerStub::class => null]);
        $request->setUserResolver(static fn (): User => $with);
        expect($authorizer->isReadable($request, new RelationElsewhereStub, new RelationPartStub))->toBeTrue();
    });

    it('receive the ACL of their parent through the parent relation when another model reaches them', function (): void {
        $reader = relauth_reader([RelationOwnerStub::class => new FiltersGroup([new Filter('name', 'visible', FilterOperator::Equals)], WhereClause::And)]);
        Auth::guard('api')->setUser($reader);

        $query = RelationPartStub::query();
        app(RelationAuthorizer::class)->constrain($query, new RelationElsewhereStub);

        expect(mb_strtolower($query->toSql()))->toContain('relauth_owners')->toContain('"name" = ?');

        $own = RelationPartStub::query();
        app(RelationAuthorizer::class)->constrain($own, new RelationOwnerStub);

        expect(mb_strtolower($own->toSql()))->not->toContain('relauth_owners');
    });

    it('refuse a declared parent relation that is not a relation', function (): void {
        $request = Request::create('/api/v1/select/core/users');

        expect(fn (): bool => app(RelationAuthorizer::class)->isReadable($request, new RelationElsewhereStub, new RelationMisdeclaredPartStub))
            ->toThrow(LogicException::class);
    });
});

describe('relations a query loads on its own', function (): void {
    it('drops a default eager load whose name does not resolve to a relation, instead of loading it unguarded', function (string $name): void {
        $reader = relauth_reader([CoreUser::class => null]);
        $request = Request::create('/api/v1/select/core/users');
        $request->setUserResolver(static fn (): User => $reader);
        $builder = CoreUser::query()->with($name);

        app(QueryBuilder::class)->guardEngineQuery($builder, app(RelationAuthorizer::class), $request);

        expect(array_keys($builder->getEagerLoads()))->not->toContain($name);
    })->with([
        'a name that is not a relation' => 'relauth_not_a_relation',
        'a name following a relation that is not one' => 'roles.relauth_not_a_relation',
    ]);
});

describe('entities outside the permission scheme', function (): void {
    it('never loads the access tokens of a user', function (): void {
        // The token belongs to the class the users endpoint hydrates, so a loaded relation would find it.
        CoreUser::factory()->create()->createToken('relauth-secret', ['default.users.select']);
        $reader = relauth_reader([CoreUser::class => null]);

        $response = $this->actingAs($reader)->getJson(relauth_url('select', ['relations' => ['tokens']]));

        expect($response->getContent())->not->toContain('relauth-secret')
            ->and(collect($response->json('data') ?? [])->pluck('tokens')->filter()->all())->toBe([])
            ->and(app(RelationAuthorizer::class)->isReadable(Request::create('/'), new CoreUser, new Laravel\Sanctum\PersonalAccessToken))->toBeFalse();
    });

    it('refuses to a non-superadmin the entities left out of the scheme that are neither parts nor public', function (Model $related): void {
        $request = Request::create('/');

        expect(app(RelationAuthorizer::class)->isReadable($request, new CoreUser, $related))->toBeFalse();
    })->with([
        'model embeddings (vectors, model keys, content hashes)' => fn (): Model => new Modules\Core\Models\ModelEmbedding,
        'versions' => fn (): Model => new Modules\Core\Models\Version,
        'licenses' => fn (): Model => new Modules\Core\Models\License,
        'dynamic entities' => fn (): Model => new Modules\Core\Models\DynamicEntity,
    ]);

    it('reads them as a relation for a superadmin only, never the embeddings', function (): void {
        $superadmin = CoreUser::factory()->create();
        $superadmin->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));
        $request = Request::create('/');
        $request->setUserResolver(static fn (): CoreUser => $superadmin);
        $authorizer = app(RelationAuthorizer::class);

        expect($authorizer->isReadable($request, new CoreUser, new Modules\Core\Models\License))->toBeTrue()
            ->and($authorizer->isReadable($request, new CoreUser, new Modules\Core\Models\Version))->toBeTrue()
            ->and($authorizer->isReadable($request, new CoreUser, new Modules\Core\Models\ModelEmbedding))->toBeFalse();
    });

    it('drops the embeddings relation from what a read loads', function (): void {
        expect(QueryBuilder::isBlackListedPath('embeddings'))->toBeTrue();
    });

    it('still loads a pivot record reached through a plain relation, which is a part of its parent', function (): void {
        expect(app(RelationAuthorizer::class)->isReadable(Request::create('/'), new CoreUser, new Modules\Core\Models\Pivot\Fieldable))->toBeTrue();
    });

    it('refuses a related entity with no select permission that is not deliberately left out of the scheme', function (): void {
        $reader = relauth_reader([CoreUser::class => null]);

        $this->actingAs($reader)->getJson(relauth_url('select', ['relations' => ['notifications']]))->assertForbidden();
    });

    it('answers 401 to the anonymous caller asking for a relation it may not read', function (): void {
        config()->set('permission.users.guest', 'anonymous');
        $anonymous = User::query()->where('name', 'anonymous')->first() ?? User::query()->findOrFail(CoreUser::factory()->create(['name' => 'anonymous', 'username' => 'anonymous'])->getKey());
        $role = Role::factory()->create(['name' => 'relauth_guest_' . uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(Permission::query()->where(['name' => PermissionName::forClass(CoreUser::class, 'select'), 'guard_name' => 'api'])->firstOrFail());
        $anonymous->assignRole($role);

        $this->getJson(relauth_url('select', []))->assertOk();
        $this->getJson(relauth_url('select', ['relations' => ['roles']]))->assertUnauthorized();
    });
});

describe('tree and history', function (): void {
    it('leave out of a tree the nodes the ACL hides', function (): void {
        $root = Role::factory()->create(['name' => 'relauth_root', 'guard_name' => 'web']);
        $visible = Role::factory()->create(['name' => 'relauth_visible', 'guard_name' => 'web', 'parent_id' => $root->id]);
        $hidden = Role::factory()->create(['name' => 'relauth_hidden', 'guard_name' => 'web', 'parent_id' => $root->id]);
        $reader = relauth_reader([Role::class => new FiltersGroup([new Filter('name', ['relauth_root', 'relauth_visible'], FilterOperator::In)], WhereClause::And)]);

        // A JSON body keeps `children` a boolean (TreeRequestData types it).
        $response = $this->actingAs($reader)->json('GET', '/api/v1/tree/core/roles', [
            'children' => true,
            'filters' => [['property' => 'id', 'operator' => 'eq', 'value' => $root->id]],
        ]);

        $response->assertOk();
        $names = [];
        $data = (array) $response->json('data');
        array_walk_recursive($data, static function (mixed $value, string|int $key) use (&$names): void {
            if ($key === 'name') {
                $names[] = $value;
            }
        });

        expect($names)->toContain('relauth_visible')->not->toContain('relauth_hidden')
            ->and($visible->id)->not->toBe($hidden->id);
    });

    it('refuse the history to a reader who may only select, whether the record is hidden by the ACL or missing', function (): void {
        $hidden = CoreUser::factory()->create(['name' => 'relauth_hidden_user']);
        $reader = relauth_reader([CoreUser::class => new FiltersGroup([new Filter('name', ['nobody'], FilterOperator::In)], WhereClause::And)]);

        $hiddenResponse = $this->actingAs($reader)->getJson('/api/v1/history/core/users?' . http_build_query(['id' => $hidden->id]));
        $missingResponse = $this->actingAs($reader)->getJson('/api/v1/history/core/users?' . http_build_query(['id' => 987654]));

        expect($hiddenResponse->status())->toBe(403)
            ->and($missingResponse->status())->toBe(403);
    });

    it('give no history of a record the ACL hides to a reader holding history', function (): void {
        $hidden = CoreUser::factory()->create(['name' => 'relauth_hidden_user']);
        $reader = relauth_reader([CoreUser::class => new FiltersGroup([new Filter('name', ['nobody'], FilterOperator::In)], WhereClause::And)]);
        relauth_grant($reader, CoreUser::class, 'history');

        $response = $this->actingAs($reader->fresh())->getJson('/api/v1/history/core/users?' . http_build_query(['id' => $hidden->id]));

        expect($response->status())->toBe(404)
            ->and($response->json('data.history'))->toBeNull();
    });

    it('give the history with history or revert, never with update alone (D27)', function (string $verb, int $status): void {
        $record = CoreUser::factory()->create(['name' => 'relauth_history_user']);
        $reader = relauth_reader([CoreUser::class => null]);
        relauth_grant($reader, CoreUser::class, $verb);

        $response = $this->actingAs($reader->fresh())->getJson('/api/v1/history/core/users?' . http_build_query(['id' => $record->id]));

        expect($response->status())->toBe($status);
    })->with([
        'history' => ['history', 200],
        'revert, which implies history' => ['revert', 200],
        'update alone' => ['update', 403],
        'approve alone' => ['approve', 403],
    ]);

    it('refuse the history without select, whatever else is held', function (): void {
        $record = CoreUser::factory()->create(['name' => 'relauth_history_user']);
        $reader = relauth_reader([]);
        relauth_grant($reader, CoreUser::class, 'history');
        relauth_grant($reader, CoreUser::class, 'revert');

        $response = $this->actingAs($reader->fresh())->getJson('/api/v1/history/core/users?' . http_build_query(['id' => $record->id]));

        expect($response->status())->toBe(403);
    });
});

describe('pending changes', function (): void {
    beforeEach(function (): void {
        Illuminate\Support\Facades\Schema::create('has_approvals_stub', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    });

    afterEach(function (): void {
        Illuminate\Support\Facades\Schema::dropIfExists('has_approvals_stub');
    });

    it('show in a preview only to a caller who may update or approve the record', function (array $operations, string $expected): void {
        $model = Modules\Core\Tests\Stubs\HasApprovalsStubModel::query()->create(['name' => 'stored']);
        Modules\Core\Models\Modification::query()->create([
            'modifiable_type' => Modules\Core\Tests\Stubs\HasApprovalsStubModel::class,
            'modifiable_id' => $model->id,
            'md5' => md5('relauth'),
            'modifications' => ['name' => ['modified' => 'proposed']],
        ]);

        $role = Role::factory()->create(['name' => 'relauth_preview_' . uniqid(), 'guard_name' => 'api']);

        foreach (['select', ...$operations] as $operation) {
            $name = PermissionName::forClass(Modules\Core\Tests\Stubs\HasApprovalsStubModel::class, $operation);
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'api']));
        }

        $reader = User::query()->findOrFail(CoreUser::factory()->create()->getKey());
        $reader->assignRole($role);
        Auth::shouldUse('api');
        Auth::guard('api')->setUser($reader->fresh());
        session(['preview' => true]);

        expect($model->fresh()->toArray()['name'] ?? null)->toBe($expected);
    })->with([
        'select only' => [[], 'stored'],
        'update' => [['update'], 'proposed'],
        'approve' => [['approve'], 'proposed'],
    ]);
});

it('groups on a plain column named like a black-listed relation', function (): void {
    Illuminate\Support\Facades\Schema::create('relauth_group_stub', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('children');
    });
    Modules\Core\Tests\Stubs\Crud\GroupByColumnStub::query()->insert([['children' => 'a'], ['children' => 'a'], ['children' => 'b']]);

    $superadmin = User::query()->findOrFail(CoreUser::factory()->create()->getKey());
    $superadmin->assignRole(Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']));
    auth()->login($superadmin);

    $model = new Modules\Core\Tests\Stubs\Crud\GroupByColumnStub;
    $request = Request::create('/api/v1/select/core/relauth_group_stub');
    $request->setUserResolver(static fn (): User => $superadmin);
    $data = (new ReflectionClass(Modules\Core\Casts\ListRequestData::class))->newInstanceWithoutConstructor();

    foreach ([
        'request' => $request, 'mainEntity' => 'relauth_group_stub', 'primaryKey' => 'id', 'model' => $model,
        'connection' => null, 'columns' => [], 'relations' => [], 'sort' => [], 'filters' => null,
        'group_by' => ['children'], 'page' => null, 'from' => null, 'to' => null, 'limit' => 25, 'pagination' => 25, 'count' => false,
    ] as $property => $value) {
        (new ReflectionProperty($data, $property))->setValue($data, $value);
    }

    $result = (new CrudService(app(AuthorizationService::class), app(QueryBuilder::class)))->list($data);

    expect($result->data->keys()->sort()->values()->all())->toBe(['a', 'b']);
});
