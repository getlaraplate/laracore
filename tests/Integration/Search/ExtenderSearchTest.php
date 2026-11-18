<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\Sort;
use Modules\Core\Casts\SortDirection;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Search\Extensions\ExtenderFilterTranslator;
use Modules\Core\Search\Support\DefaultSearchFilters;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Tests\Stubs\Extensions\ExtensionHostStub;
use Modules\Core\Tests\Stubs\Extensions\FirstExtenderStub;
use Modules\Core\Tests\Stubs\Extensions\SecondExtenderStub;
use Modules\Core\Tests\Stubs\Search\DefaultFilteredSearchStub;
use Modules\Core\Tests\Support\DefaultFilteredSearchFixture;
use Modules\Core\Tests\Support\ExtensionFixture;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * An extender is searched through the index of its host, never through one of its own, and read with its own
 * permission and ACL (spec 2026-11-17-extended-entity-search-design.md, XS1, XS2, XS10). The engine keeps the rows of
 * the extender's alias in place of the host's exclusion of extended rows; the hits reload as extenders by the host
 * key; filters and ACL are translated to the host index by one rule.
 *
 * The database engine lists the newest rows first. A page smaller than the rows the engine holds shows which
 * condition the engine applied: a condition left to the reload would let other rows take the page and be dropped.
 * The database engine cannot filter on `extension.*` (it reads the host table), so the translation of an indexed own
 * column is checked on the translator.
 */

beforeEach(function (): void {
    FakeVectorProfile::none();
    config()->set('permission.roles.superadmin', 'superadmin');
    ExtensionFixture::setUp();
});

afterEach(function (): void {
    ExtensionFixture::tearDown();
});

/**
 * A user who may select the rows of the first extender, under the given ACL, and the host rows unless told otherwise.
 */
function extender_search_user(?FiltersGroup $acl_filters = null, bool $host_select = true): User
{
    $role = Role::factory()->create(['name' => 'extender_search_' . uniqid(), 'guard_name' => 'web']);
    $permission = Permission::findOrCreate('default.' . FirstExtenderStub::TABLE . '.select', 'web');
    $role->givePermissionTo($permission);

    if ($host_select) {
        $role->givePermissionTo(Permission::findOrCreate('default.' . ExtensionHostStub::TABLE . '.select', 'web'));
    }

    if ($acl_filters instanceof FiltersGroup) {
        $acl = new ACL;
        $acl->setSkipValidation(true);
        $acl->forceFill([
            'permission_id' => $permission->id,
            'filters' => $acl_filters,
            'unrestricted' => false,
            'priority' => 10,
            'is_active' => true,
        ]);
        $acl->save();
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * The host keys of the records a search of the first extender returns, in its order; every record is the extender.
 *
 * @return list<int>
 */
function extender_search_hosts(CrudResult $result): array
{
    expect($result->error)->toBeNull()
        ->and($result->data->every(static fn (Model $record): bool => $record instanceof FirstExtenderStub))->toBeTrue();

    return array_values($result->data->map(static fn (Model $record): int => (int) $record->getAttribute('host_id'))->all());
}

/**
 * @param  list<Sort>  $sort
 */
function extender_search(SearchMode $mode, int $limit, ?FiltersGroup $filters = null, ?User $user = null, array $sort = []): CrudResult
{
    return DefaultFilteredSearchFixture::run($mode, $limit, $filters, $user, new FirstExtenderStub, $sort);
}

it('searches an extender through the index of its host and reloads its hits as the extender', function (SearchMode $mode): void {
    $first = ExtensionFixture::row('needle first', FirstExtenderStub::ALIAS, 'gold');
    $second = ExtensionFixture::row('needle second', FirstExtenderStub::ALIAS, 'silver');
    ExtensionFixture::row('needle other', SecondExtenderStub::ALIAS, 'gold');
    ExtensionFixture::row('needle plain one');
    ExtensionFixture::row('needle plain two');

    $hosts = extender_search_hosts(extender_search($mode, 2));
    sort($hosts);

    // The newer rows of the host and of the other extender would take the page without the alias at the engine,
    // and the host exclusion joined to the alias would select nothing.
    expect($hosts)->toBe([$first->id, $second->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

it('keeps the order of the engine, sorted on an attribute mounted from the host', function (SortDirection $direction): void {
    $alpha = ExtensionFixture::row('needle alpha', FirstExtenderStub::ALIAS);
    $beta = ExtensionFixture::row('needle beta', FirstExtenderStub::ALIAS);

    $hosts = extender_search_hosts(extender_search(SearchMode::Basic, 10, null, null, [new Sort('title', $direction)]));

    expect($hosts)->toBe($direction === SortDirection::Asc ? [$alpha->id, $beta->id] : [$beta->id, $alpha->id]);
})->with([SortDirection::Asc, SortDirection::Desc]);

it('filters on an attribute mounted from the host by the host field', function (SearchMode $mode): void {
    ExtensionFixture::row('needle alpha', FirstExtenderStub::ALIAS);
    $beta = ExtensionFixture::row('needle beta', FirstExtenderStub::ALIAS);

    $hosts = extender_search_hosts(extender_search($mode, 10, new FiltersGroup([new Filter('title', 'needle beta')])));

    expect($hosts)->toBe([$beta->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

it('refuses a filter on an own column the host index does not filter on', function (SearchMode $mode): void {
    $row = ExtensionFixture::row('needle alpha', FirstExtenderStub::ALIAS);

    $result = extender_search($mode, 10, new FiltersGroup([new Filter('host_id', [$row->id], FilterOperator::In)]));

    expect($result->statusCode)->toBe(400)
        ->and($result->error)->toContain('host_id')
        ->and($result->error)->toContain('XS10');
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

it('refuses a sort on an own column of the extender', function (): void {
    ExtensionFixture::row('needle alpha', FirstExtenderStub::ALIAS);

    $result = extender_search(SearchMode::Basic, 10, null, null, [new Sort('kind')]);

    expect($result->statusCode)->toBe(400);
});

it('reads an extender with its own permission, not its host\'s', function (): void {
    $row = ExtensionFixture::row('needle alpha', FirstExtenderStub::ALIAS);

    expect(extender_search_hosts(extender_search(SearchMode::Basic, 10, null, extender_search_user(host_select: false))))->toBe([$row->id]);

    $host_role = Role::factory()->create(['name' => 'extender_search_host_' . uniqid(), 'guard_name' => 'web']);
    $host_role->givePermissionTo(Permission::findOrCreate('default.' . ExtensionHostStub::TABLE . '.select', 'web'));
    $host_only = User::factory()->create();
    $host_only->assignRole($host_role);

    expect(fn (): CrudResult => extender_search(SearchMode::Basic, 10, null, $host_only))->toThrow(AuthorizationException::class);
});

it('holds an ACL condition the host index cannot apply when the hits are reloaded', function (SearchMode $mode): void {
    $allowed = ExtensionFixture::row('needle alpha', FirstExtenderStub::ALIAS);
    ExtensionFixture::row('needle beta', FirstExtenderStub::ALIAS);

    // `host_id` is an own column the extender does not index: the engine cannot apply it, the reload does.
    $user = extender_search_user(new FiltersGroup([new Filter('host_id', [$allowed->id], FilterOperator::In)]));

    expect(extender_search_hosts(extender_search($mode, 10, null, $user)))->toBe([$allowed->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

it('applies an ACL condition on a mounted attribute at the engine and at the reload', function (SearchMode $mode): void {
    $allowed = ExtensionFixture::row('needle beta', FirstExtenderStub::ALIAS);
    ExtensionFixture::row('needle alpha', FirstExtenderStub::ALIAS);

    $user = extender_search_user(new FiltersGroup([new Filter('title', 'needle beta')]));

    // A page of one: left to the reload, the newer denied row would take it and the page would come back empty.
    expect(extender_search_hosts(extender_search($mode, 1, null, $user)))->toBe([$allowed->id]);
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);

it('translates an attribute mounted from the host to the host field, and an indexed own column to its extension field', function (): void {
    $translator = app(ExtenderFilterTranslator::class);
    $host = new ExtensionHostStub;
    $first = new FirstExtenderStub;

    expect($translator->field($first, $host, 'title'))->toBe('title')
        ->and($translator->field($first, $host, 'kind'))->toBe('extension.kind')
        ->and($translator->field($first, $host, FirstExtenderStub::TABLE . '.kind'))->toBe('extension.kind')
        ->and($translator->field($first, $host, 'host_id'))->toBeNull()
        ->and($translator->field($first, $host, 'unknown'))->toBeNull();
});

it('leaves out an own column the extender does not index, though another extender indexes a field of that name', function (): void {
    SecondExtenderStub::$mapping = [];

    expect(app(ExtenderFilterTranslator::class)->field(new SecondExtenderStub, new ExtensionHostStub, 'kind'))->toBeNull()
        ->and(app(ExtenderFilterTranslator::class)->field(new FirstExtenderStub, new ExtensionHostStub, 'kind'))->toBe('extension.kind');
});

it('gives the engine the ACL conditions it can apply, dropping an OR group that loses one', function (): void {
    $acl = new FiltersGroup([
        new Filter('kind', 'gold'),
        new Filter('host_id', [1], FilterOperator::In),
        new FiltersGroup([new Filter('title', 'x'), new Filter('host_id', 2)], WhereClause::Or),
        new FiltersGroup([new Filter('title', 'y'), new Filter('kind', 'z')], WhereClause::Or),
        new ScopeFilter('valid'),
    ]);

    expect(app(ExtenderFilterTranslator::class)->aclFilters(new FirstExtenderStub, new ExtensionHostStub, $acl))->toEqual(new FiltersGroup([
        new Filter('extension.kind', 'gold'),
        new FiltersGroup([new Filter('title', 'y'), new Filter('extension.kind', 'z')], WhereClause::Or),
    ]));
});

it('keeps the scope and relation filters of a request for the reload', function (): void {
    $filters = new FiltersGroup([new ScopeFilter('valid'), new Filter('title', 'x')]);

    expect(app(ExtenderFilterTranslator::class)->requestFilters(new FirstExtenderStub, new ExtensionHostStub, $filters))->toEqual($filters);
});

it('replaces the exclusion of extended rows of a host with the given condition, and ignores it elsewhere', function (): void {
    $alias = new Filter('extension_type', FirstExtenderStub::ALIAS);
    $request = new FiltersGroup([new Filter('title', 'x')]);

    expect(DefaultSearchFilters::merge(new ExtensionHostStub, $request, $alias))->toEqual(new FiltersGroup([$alias, $request], WhereClause::And))
        ->and(DefaultSearchFilters::merge(new ExtensionHostStub, $request))->toEqual(new FiltersGroup([new Filter('extension_type', null), $request], WhereClause::And))
        ->and(DefaultSearchFilters::merge(new DefaultFilteredSearchStub, $request, $alias))->toEqual(DefaultSearchFilters::merge(new DefaultFilteredSearchStub, $request));
});
