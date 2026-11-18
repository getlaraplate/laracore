<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Authorization\RetrievedSelectGuard;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\HistoryRequestData;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\TreeRequestData;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Http\Requests\DetailRequest;
use Modules\Core\Http\Requests\HistoryRequest;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Http\Requests\TreeRequest;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Services\Crud\Cache\CrudCacheDependencyResolver;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Cache\CacheDependencyStubChild;
use Modules\Core\Tests\Stubs\Cache\CacheDependencyStubModel;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Tests\Support\Cache\AssertsCacheDependencies;
use PHPUnit\Framework\ExpectationFailedException;

uses(AssertsCacheDependencies::class);

/**
 * @param  array<string,mixed>  $validated
 */
function cache_dependency_list_request(array $validated, string $entity = 'ACL'): ListRequestData
{
    return new ListRequestData(ListRequest::create('/app/crud/select/core/' . $entity, 'GET'), $entity, $validated, 'id', 'core');
}

/**
 * @param  array<string,mixed>  $validated
 */
function cache_dependency_detail_request(int $id, array $validated): DetailRequestData
{
    return new DetailRequestData(DetailRequest::create('/app/crud/detail/core/ACL', 'GET', ['id' => $id]), 'ACL', $validated, 'id', 'core');
}

function cache_dependency_resolver(): CrudCacheDependencyResolver
{
    return app(CrudCacheDependencyResolver::class);
}

/**
 * @return array{user: User, role: Role}
 */
function cache_dependency_caller(): array
{
    $role = Role::factory()->create(['name' => 'cache_dependency_' . uniqid()]);
    $user = User::factory()->create();
    $user->assignRole($role);

    return ['user' => $user, 'role' => $role];
}

function cache_dependency_acl(Role $role, ?int $id = null): ACL
{
    $permission = Permission::query()->firstOrCreate(['name' => PermissionName::forClass(ACL::class, 'select'), 'guard_name' => 'web']);
    $acl = new ACL;
    $acl->setSkipValidation(true);
    $acl->forceFill(array_filter([
        'id' => $id,
        'permission_id' => $permission->id,
        'role_id' => $role->id,
        'unrestricted' => true,
        'priority' => 0,
        'is_active' => true,
    ], static fn (mixed $value): bool => $value !== null));
    $acl->save();

    return $acl;
}

it('labels a list with its main table, the crud label and the caller scope, and embeds a belongsTo by row', function (): void {
    ['user' => $user, 'role' => $role] = cache_dependency_caller();

    $dependencies = cache_dependency_resolver()->forRequest(new ACL, cache_dependency_list_request(['relations' => ['role']]), $user, false);

    expect($dependencies->labels)->toContain(
        CacheLabel::table(CoreTables::Acls->value),
        CacheLabel::all(),
        CacheLabel::user($user->id),
        CacheLabel::role($role->id),
    )
        ->and($dependencies->labels)->not->toContain(CacheLabel::table(CoreTables::Roles->value))
        ->and($dependencies->row_tables)->toBe([CoreTables::Roles->value])
        ->and($dependencies->complete)->toBeTrue();
});

it('labels a to-many relation with its related and pivot tables', function (): void {
    $role = new Role;

    $dependencies = cache_dependency_resolver()->forRequest($role, cache_dependency_list_request(['relations' => ['permissions']], 'roles'), null, false);

    expect($dependencies->labels)->toContain(
        CacheLabel::table(CoreTables::Roles->value),
        CacheLabel::table(CoreTables::Permissions->value),
        CacheLabel::table($role->permissions()->getTable()),
    )
        ->and($dependencies->row_tables)->toBe([]);
});

it('labels a relation a filter names as a set, never as a row table', function (): void {
    $dependencies = cache_dependency_resolver()->forRequest(
        new ACL,
        cache_dependency_list_request(['filters' => [['property' => 'role.name', 'value' => 'admin', 'operator' => '=']]]),
        null,
        false,
    );

    expect($dependencies->labels)->toContain(CacheLabel::table(CoreTables::Roles->value))
        ->and($dependencies->row_tables)->not->toContain(CoreTables::Roles->value);
});

it('carries no scope labels for a call without a user', function (): void {
    $dependencies = cache_dependency_resolver()->forRequest(new ACL, cache_dependency_list_request([]), null, false);

    expect($dependencies->labels)->toBe([CacheLabel::all(), CacheLabel::table(CoreTables::Acls->value)]);
});

it('depends on the record of a detail by row, and gives the row labels of the loaded record and its belongsTo', function (): void {
    ['role' => $role] = cache_dependency_caller();
    cache_dependency_acl($role, 7);

    $resolver = cache_dependency_resolver();
    $dependencies = $resolver->forRequest(new ACL, cache_dependency_detail_request(7, ['relations' => ['role']]), null, true);

    expect($dependencies->labels)->not->toContain(CacheLabel::table(CoreTables::Acls->value))
        ->and($dependencies->row_tables)->toContain(CoreTables::Acls->value, CoreTables::Roles->value);

    $loaded = ACL::query()->with('role')->findOrFail(7);

    expect($resolver->rowLabels(new ACL, $dependencies, $loaded))
        ->toEqualCanonicalizing([CacheLabel::row(CoreTables::Acls->value, 7), CacheLabel::row(CoreTables::Roles->value, $role->id)]);
});

it('gives the row labels of the belongsTo relations of a list, not of its rows', function (): void {
    ['role' => $role] = cache_dependency_caller();
    $other_role = Role::factory()->create(['name' => 'cache_dependency_other_' . uniqid()]);
    cache_dependency_acl($role);
    cache_dependency_acl($other_role);

    $resolver = cache_dependency_resolver();
    $dependencies = $resolver->forRequest(new ACL, cache_dependency_list_request(['relations' => ['role']]), null, false);
    $rows = ACL::query()->with('role')->get();
    $expected = [CacheLabel::row(CoreTables::Roles->value, $role->id), CacheLabel::row(CoreTables::Roles->value, $other_role->id)];

    expect($resolver->rowLabels(new ACL, $dependencies, $rows))->toEqualCanonicalizing($expected)
        ->and($resolver->rowLabels(new ACL, $dependencies, new LengthAwarePaginator($rows, $rows->count(), 25)))->toEqualCanonicalizing($expected)
        ->and($resolver->rowLabels(new ACL, $dependencies, $rows->groupBy('role_id')))->toEqualCanonicalizing($expected);
});

it('gives the row label of a belongsTo the relation answered null for, from the foreign key', function (): void {
    ['role' => $role] = cache_dependency_caller();
    $acl = cache_dependency_acl($role);
    $acl->setRelation('role', null);

    $resolver = cache_dependency_resolver();
    $dependencies = $resolver->forRequest(new ACL, cache_dependency_list_request(['relations' => ['role']]), null, false);

    expect($resolver->rowLabels(new ACL, $dependencies, collect([$acl])))->toBe([CacheLabel::row(CoreTables::Roles->value, $role->id)]);
});

it('gives the row labels of the to-one relations loaded under a to-many relation', function (): void {
    ['role' => $role] = cache_dependency_caller();
    $child = new CacheDependencyStubChild;
    $child->forceFill(['id' => 3, 'role_id' => $role->id]);
    $child->setRelation('role', $role);
    $parent = new CacheDependencyStubModel;
    $parent->forceFill(['id' => 1]);
    $parent->setRelation('items', collect([$child]));

    $resolver = cache_dependency_resolver();
    $dependencies = $resolver->forRequest(new CacheDependencyStubModel, cache_dependency_list_request(['relations' => ['items.role']]), null, false);

    expect($resolver->rowLabels(new CacheDependencyStubModel, $dependencies, $parent))->toBe([CacheLabel::row(CoreTables::Roles->value, $role->id)]);
});

it('labels the translations table of a translated model reached by a path', function (): void {
    $dependencies = cache_dependency_resolver()->forRequest(new CacheDependencyStubModel, cache_dependency_list_request(['relations' => ['document']]), null, false);

    expect($dependencies->labels)->toContain(CacheLabel::table(TranslatedDocumentTranslation::TABLE));
});

it('labels the translations table of a translated main model', function (): void {
    $dependencies = cache_dependency_resolver()->forRequest(new TranslatedDocument, cache_dependency_list_request([]), null, false);

    expect($dependencies->labels)->toContain(CacheLabel::table(TranslatedDocument::TABLE), CacheLabel::table(TranslatedDocumentTranslation::TABLE));
});

it('labels the tables a model declares', function (): void {
    $dependencies = cache_dependency_resolver()->forRequest(new CacheDependencyStubModel, cache_dependency_list_request(['relations' => ['items']]), null, false);

    expect($dependencies->labels)->toContain(
        CacheLabel::table(CacheDependencyStubChild::TABLE),
        CacheLabel::table(CacheDependencyStubChild::DECLARED_TABLE),
    );
});

it('cannot know the dependencies of a morphTo before the query', function (): void {
    $dependencies = cache_dependency_resolver()->forRequest(new CacheDependencyStubModel, cache_dependency_list_request(['relations' => ['owner']]), null, false);

    expect($dependencies->complete)->toBeFalse();
});

it('labels hasOne and hasMany as sets and keeps the kind of a segment after a to-many one', function (string $path): void {
    $dependencies = cache_dependency_resolver()->forRequest(new CacheDependencyStubModel, cache_dependency_list_request(['relations' => [$path . '.role']]), null, false);

    expect($dependencies->labels)->toContain(CacheLabel::table(CacheDependencyStubChild::TABLE))
        ->and($dependencies->row_tables)->toBe([CoreTables::Roles->value])
        ->and($dependencies->complete)->toBeTrue();
})->with(['hasOne' => 'detail', 'hasMany' => 'items']);

it('labels the versions a history read loads on its own', function (): void {
    $role = new Role;
    $request_data = new HistoryRequestData(HistoryRequest::create('/app/crud/history/core/roles', 'GET', ['id' => 1]), 'roles', [], 'id', 'core');

    $dependencies = cache_dependency_resolver()->forRequest($role, $request_data, null, true);

    expect($dependencies->labels)->toContain(CacheLabel::table($role->history()->getRelated()->getTable()))
        ->and($dependencies->row_tables)->toBe([CoreTables::Roles->value]);
});

it('labels the main table of a tree read as a set, its nodes being rows of it', function (): void {
    $request_data = new TreeRequestData(TreeRequest::create('/app/crud/tree/core/roles', 'GET', ['id' => 1]), 'roles', ['parents' => false, 'children' => true], 'id', 'core');

    $dependencies = cache_dependency_resolver()->forRequest(new Role, $request_data, null, true);

    expect($dependencies->labels)->toContain(CacheLabel::table(CoreTables::Roles->value));
});

it('labels a through relation with its related and through tables', function (): void {
    $dependencies = cache_dependency_resolver()->forRequest(new CacheDependencyStubModel, cache_dependency_list_request(['relations' => ['childRoles']]), null, false);

    expect($dependencies->labels)->toContain(CacheLabel::table(CoreTables::Roles->value), CacheLabel::table(CacheDependencyStubChild::TABLE));
});

/*
 * The reads below run as system reads: the per-row select check of a hydrated model queries the permissions, which
 * is authorization, run again on every cache hit, not data the response depends on.
 */
it('covers the tables an ACL list with its roles reads', function (): void {
    ['role' => $role] = cache_dependency_caller();
    cache_dependency_acl($role);

    $dependencies = cache_dependency_resolver()->forRequest(new ACL, cache_dependency_list_request(['relations' => ['role']]), null, false);

    $this->assertCacheDependenciesCover(static fn (): mixed => RetrievedSelectGuard::forSystemRead(static fn (): mixed => ACL::query()->with('role')->get()), $dependencies);
});

it('covers the tables a role list with its permissions reads', function (): void {
    ['role' => $role] = cache_dependency_caller();
    $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => PermissionName::forClass(Role::class, 'select'), 'guard_name' => 'web']));

    $dependencies = cache_dependency_resolver()->forRequest(new Role, cache_dependency_list_request(['relations' => ['permissions']], 'roles'), null, false);

    $this->assertCacheDependenciesCover(static fn (): mixed => RetrievedSelectGuard::forSystemRead(static fn (): mixed => Role::query()->with('permissions')->get()), $dependencies);
});

it('fails naming a table the call reads without a label', function (): void {
    ['role' => $role] = cache_dependency_caller();
    cache_dependency_acl($role);

    $dependencies = new CacheDependencies([CacheLabel::all(), CacheLabel::table(CoreTables::Acls->value)]);

    expect(fn () => $this->assertCacheDependenciesCover(static fn (): mixed => RetrievedSelectGuard::forSystemRead(static fn (): mixed => ACL::query()->with('role')->get()), $dependencies))
        ->toThrow(ExpectationFailedException::class, CoreTables::Roles->value);
});
