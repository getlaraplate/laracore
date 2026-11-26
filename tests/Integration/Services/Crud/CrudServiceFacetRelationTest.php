<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Models\Role;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\FacetPage;
use Modules\Core\Services\Crud\DTOs\FacetQuery;
use Modules\Core\Services\Crud\DTOs\FacetSort;

function facet_rel_request(): Request
{
    return new class extends Request
    {
        public function validated(?string $key = null, mixed $default = null): mixed
        {
            return $key === null ? [] : $default;
        }
    };
}

function facet_rel_list_data(Model $model, Request $request, ?FiltersGroup $filters = null): Modules\Core\Casts\ListRequestData
{
    $data = new ReflectionClass(Modules\Core\Casts\ListRequestData::class)->newInstanceWithoutConstructor();

    $set = static function (object $obj, string $prop, mixed $value): void {
        new ReflectionProperty($obj, $prop)->setValue($obj, $value);
    };

    foreach ([
        'request' => $request, 'mainEntity' => $model->getTable(), 'primaryKey' => $model->getKeyName(),
        'connection' => $model->getConnectionName(), 'model' => $model, 'columns' => [], 'relations' => [],
        'sort' => [], 'filters' => $filters, 'group_by' => [], 'pagination' => 25, 'page' => null,
        'skip' => null, 'take' => null, 'from' => null, 'to' => null, 'limit' => null, 'count' => false,
    ] as $prop => $value) {
        $set($data, $prop, $value);
    }

    return $data;
}

function facet_rel_superadmin(): User
{
    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $user = User::factory()->create(['name' => 'zzz-superadmin']);
    $user->assignRole($role);
    auth()->login($user);

    return $user;
}

function facet_rel_service(User $actor, ?FiltersGroup $filters, FacetQuery $facet): FacetPage
{
    $request = facet_rel_request();
    $request->setUserResolver(fn (): User => $actor);
    $base = facet_rel_list_data(new User, $request, $filters);

    return app(CrudService::class)->facetValues($base, $facet);
}

function facet_rel_by_label(FacetPage $page): Illuminate\Support\Collection
{
    return collect($page->values)->keyBy(fn (array $value): mixed => $value['attributes']['name'] ?? null);
}

it('facets over a many-to-many relation with the double counter and related labels', function (): void {
    $admin = facet_rel_superadmin();
    $editor = Role::factory()->create(['name' => 'editor', 'guard_name' => 'web']);
    $viewer = Role::factory()->create(['name' => 'viewer', 'guard_name' => 'web']);
    User::factory()->create()->assignRole($editor);
    User::factory()->create()->assignRole($editor);
    User::factory()->create()->assignRole($viewer);

    $page = facet_rel_service($admin, null, new FacetQuery(
        groupBy: 'roles',
        relation: 'roles',
        fields: ['name'],
        labelField: 'name',
        sort: FacetSort::CountDesc,
    ));

    $byLabel = facet_rel_by_label($page);

    expect($byLabel['editor']['count'])->toBe(2)
        ->and($byLabel['editor']['total'])->toBe(2)
        ->and($byLabel['editor']['key'])->toBe($editor->id)
        ->and($byLabel['viewer']['count'])->toBe(1)
        ->and($byLabel['viewer']['attributes'])->toBe(['name' => 'viewer']);
});

it('keeps a relation total unfiltered while count reflects a base filter', function (): void {
    $admin = facet_rel_superadmin();
    $editor = Role::factory()->create(['name' => 'editor', 'guard_name' => 'web']);

    User::factory()->create(['email' => 'a-keep@example.test'])->assignRole($editor);
    User::factory()->create(['email' => 'b-drop@example.test'])->assignRole($editor);

    $filters = new FiltersGroup([new Filter('email', '%keep%', FilterOperator::Like)]);

    $page = facet_rel_service($admin, $filters, new FacetQuery(
        groupBy: 'roles',
        relation: 'roles',
        fields: ['name'],
        sort: FacetSort::CountDesc,
    ));

    $byLabel = facet_rel_by_label($page);

    expect($byLabel['editor']['total'])->toBe(2)
        ->and($byLabel['editor']['count'])->toBe(1);
});

it('excludes a relation facet own selection so cross-filtering stays live', function (): void {
    $admin = facet_rel_superadmin();
    $editor = Role::factory()->create(['name' => 'editor', 'guard_name' => 'web']);
    $viewer = Role::factory()->create(['name' => 'viewer', 'guard_name' => 'web']);
    User::factory()->count(2)->create()->each(fn (User $u) => $u->assignRole($editor));
    User::factory()->create()->assignRole($viewer);

    // Selecting editor filters the list, but the roles facet must still show viewer
    // (its own selection is excluded from its own counts).
    $filters = new FiltersGroup([new Filter('roles.id', [$editor->id], FilterOperator::In)]);

    $page = facet_rel_service($admin, $filters, new FacetQuery(
        groupBy: 'roles',
        relation: 'roles',
        fields: ['name'],
        sort: FacetSort::CountDesc,
    ));

    $byLabel = facet_rel_by_label($page);

    expect($byLabel['editor']['count'])->toBe(2)
        ->and($byLabel->has('viewer'))->toBeTrue()
        ->and($byLabel['viewer']['count'])->toBe(1);
});

it('searches and sorts a relation facet by the related label', function (): void {
    $admin = facet_rel_superadmin();
    $editor = Role::factory()->create(['name' => 'editor', 'guard_name' => 'web']);
    $viewer = Role::factory()->create(['name' => 'viewer', 'guard_name' => 'web']);
    User::factory()->count(3)->create()->each(fn (User $u) => $u->assignRole($editor));
    User::factory()->count(2)->create()->each(fn (User $u) => $u->assignRole($viewer));

    $sorted = facet_rel_service($admin, null, new FacetQuery(
        groupBy: 'roles',
        relation: 'roles',
        fields: ['name'],
        labelField: 'name',
        sort: FacetSort::LabelAsc,
    ));

    // editor (3) before viewer (2) — label order, not count order.
    $labels = collect($sorted->values)
        ->map(fn (array $value): mixed => $value['attributes']['name'])
        ->filter(fn (mixed $name): bool => in_array($name, ['editor', 'viewer'], true))
        ->values()
        ->all();
    expect($labels)->toBe(['editor', 'viewer']);

    $searched = facet_rel_service($admin, null, new FacetQuery(
        groupBy: 'roles',
        relation: 'roles',
        fields: ['name'],
        labelField: 'name',
        search: 'edit',
    ));

    expect($searched->distinctValues)->toBe(1)
        ->and(collect($searched->values)->pluck('key')->all())->toBe([$editor->id]);
});

it('quotes the raw facet columns through the grammar, so Db2 quotes a key that is a reserved word', function (): void {
    $admin = facet_rel_superadmin();
    User::factory()->create()->assignRole(Role::factory()->create(['name' => 'editor', 'guard_name' => 'web']));
    $connection = (new User)->getConnection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    facet_rel_service($admin, null, new FacetQuery(groupBy: 'roles', relation: 'roles', fields: ['name'], labelField: 'name'));
    facet_rel_service($admin, null, new FacetQuery(groupBy: 'license.valid_to'));

    $facet_queries = collect($connection->getQueryLog())
        ->pluck('query')
        ->filter(static fn (string $query): bool => str_contains($query, ' as facet_key'))
        ->values();
    $connection->disableQueryLog();
    $users = (new User)->getTable();

    expect($facet_queries)->not->toBeEmpty()
        ->and($facet_queries->every(static fn (string $query): bool => preg_match('/(^|[\s(,])\w+\.\w+ as facet_key/', $query) === 0))->toBeTrue()
        ->and($facet_queries->every(static fn (string $query): bool => preg_match('/count\(distinct \w+\.\w+\)/', $query) === 0))->toBeTrue()
        ->and($facet_queries->contains(static fn (string $query): bool => str_contains($query, 'count(distinct "' . $users . '"."id") as aggregate')))->toBeTrue();
});
