<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\Column;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Models\ACL;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Tests\Stubs\Extensions\ExtensionHostStub;
use Modules\Core\Tests\Stubs\Extensions\FirstExtenderStub;
use Modules\Core\Tests\Stubs\Extensions\SecondExtenderStub;
use Modules\Core\Tests\Support\DefaultFilteredSearchFixture;
use Modules\Core\Tests\Support\ExtensionFixture;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * `with_extended` opens a list of a host to the extended rows the user may read (spec
 * 2026-11-17-extended-entity-search-design.md, XS3 to XS6, XS12): the aliases whose table the user may select, each
 * extended row passing the host's ACL and its own entity's, upcast to its extender. The exclusion of extended rows is
 * replaced, not joined. `/select` (SQL) and `/search` (engine, every path) return the same rows.
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
 * A user who may select the host rows and the rows of the given extender tables, each under its ACL when given.
 *
 * @param  array<string, FiltersGroup|null>  $acl_by_table  table => ACL filters, the host table included
 */
function mixed_list_user(array $acl_by_table): User
{
    $role = Role::factory()->create(['name' => 'mixed_list_' . uniqid(), 'guard_name' => 'web']);

    foreach ($acl_by_table as $table => $acl_filters) {
        $permission = Permission::findOrCreate('default.' . $table . '.select', 'web');
        $role->givePermissionTo($permission);

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
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Each row as `<class basename>:<host key>`, sorted: the host key of an extender is its `host_id`.
 *
 * @return list<string>
 */
function mixed_list_rows(CrudResult $result): array
{
    expect($result->error)->toBeNull();

    $rows = $result->data->map(static fn (Model $row): string => class_basename($row) . ':' . ($row instanceof ExtensionHostStub ? $row->getKey() : $row->getAttribute('host_id')))->all();
    sort($rows);

    return array_values($rows);
}

/**
 * @param  array<string, mixed>  $properties
 */
function mixed_list_select(?User $user, bool $with_extended = true, ?Model $model = null, array $properties = []): CrudResult
{
    $data = DefaultFilteredSearchFixture::requestData(ListRequestData::class, $model ?? new ExtensionHostStub, $user, ['with_extended' => $with_extended, ...$properties]);

    return app(CrudService::class)->list($data);
}

/**
 * The rows of `/select` and of `/search` on every path, keyed by where they come from.
 *
 * @return array<string, list<string>>
 */
function mixed_list_everywhere(?User $user, bool $with_extended = true): array
{
    $results = ['select' => mixed_list_rows(mixed_list_select($user, $with_extended))];

    foreach ([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto] as $mode) {
        $results[$mode->value] = mixed_list_rows(DefaultFilteredSearchFixture::run($mode, 100, null, $user, new ExtensionHostStub, [], $with_extended));
    }

    return $results;
}

/**
 * @param  list<string>  $rows
 * @return array<string, list<string>>
 */
function mixed_list_same_everywhere(array $rows): array
{
    return ['select' => $rows, 'basic' => $rows, 'orchestrated' => $rows, 'auto' => $rows];
}

/**
 * @return array{plain: ExtensionHostStub, gold: ExtensionHostStub, silver: ExtensionHostStub, second: ExtensionHostStub}
 */
function mixed_list_fixture_rows(): array
{
    return [
        'plain' => ExtensionFixture::row('needle plain'),
        'gold' => ExtensionFixture::row('needle first gold', FirstExtenderStub::ALIAS, 'gold'),
        'silver' => ExtensionFixture::row('needle first silver', FirstExtenderStub::ALIAS, 'silver'),
        'second' => ExtensionFixture::row('needle second', SecondExtenderStub::ALIAS, 'gold'),
    ];
}

it('adds the extended rows to a host list, each upcast to its extender, the same on every read', function (): void {
    $rows = mixed_list_fixture_rows();

    expect(mixed_list_everywhere(null))->toBe(mixed_list_same_everywhere([
        'ExtensionHostStub:' . $rows['plain']->id,
        'FirstExtenderStub:' . $rows['gold']->id,
        'FirstExtenderStub:' . $rows['silver']->id,
        'SecondExtenderStub:' . $rows['second']->id,
    ]));
});

it('lists only the plain host rows without the flag', function (): void {
    $rows = mixed_list_fixture_rows();

    expect(mixed_list_everywhere(null, false))->toBe(mixed_list_same_everywhere(['ExtensionHostStub:' . $rows['plain']->id]));
});

it('leaves out, silently, the aliases whose table the user may not select', function (): void {
    $rows = mixed_list_fixture_rows();
    $user = mixed_list_user([ExtensionHostStub::TABLE => null, FirstExtenderStub::TABLE => null]);

    expect(mixed_list_everywhere($user))->toBe(mixed_list_same_everywhere([
        'ExtensionHostStub:' . $rows['plain']->id,
        'FirstExtenderStub:' . $rows['gold']->id,
        'FirstExtenderStub:' . $rows['silver']->id,
    ]));
});

it('holds each extended row to the ACL of its own entity', function (string $column): void {
    $rows = mixed_list_fixture_rows();
    $first_acl = match ($column) {
        // Indexed in `extension`: the engine applies it.
        'kind' => new FiltersGroup([new Filter('kind', 'gold')]),
        // Not indexed: left to the reload.
        default => new FiltersGroup([new Filter('host_id', [$rows['gold']->id], FilterOperator::In)]),
    };
    $user = mixed_list_user([ExtensionHostStub::TABLE => null, FirstExtenderStub::TABLE => $first_acl]);

    expect(mixed_list_everywhere($user))->toBe(mixed_list_same_everywhere([
        'ExtensionHostStub:' . $rows['plain']->id,
        'FirstExtenderStub:' . $rows['gold']->id,
    ]));
})->with(['kind', 'host_id']);

it('holds every row, the extended ones too, to the ACL of the host', function (): void {
    $rows = mixed_list_fixture_rows();
    $user = mixed_list_user([
        ExtensionHostStub::TABLE => new FiltersGroup([new Filter('id', [$rows['silver']->id], FilterOperator::NotEquals)]),
        FirstExtenderStub::TABLE => null,
    ]);

    expect(mixed_list_everywhere($user))->toBe(mixed_list_same_everywhere([
        'ExtensionHostStub:' . $rows['plain']->id,
        'FirstExtenderStub:' . $rows['gold']->id,
    ]));
});

it('upcasts the rows of a list that names its columns', function (): void {
    $rows = mixed_list_fixture_rows();

    $result = mixed_list_select(null, true, null, ['columns' => [new Column(ExtensionHostStub::TABLE . '.title')]]);

    expect(mixed_list_rows($result))->toContain('FirstExtenderStub:' . $rows['gold']->id)
        ->and($result->data->first(static fn (Model $row): bool => $row instanceof FirstExtenderStub)?->getRelation('host')->getAttribute('extension_type'))->toBe(FirstExtenderStub::ALIAS);
});

it('ignores the flag on a model that is not a host, an extender included', function (): void {
    $rows = mixed_list_fixture_rows();
    $expected = ['FirstExtenderStub:' . $rows['gold']->id, 'FirstExtenderStub:' . $rows['silver']->id];

    expect(mixed_list_rows(mixed_list_select(null, true, new FirstExtenderStub)))->toBe($expected)
        ->and(mixed_list_rows(DefaultFilteredSearchFixture::run(SearchMode::Basic, 100, null, null, new FirstExtenderStub, [], true)))->toBe($expected);
});
