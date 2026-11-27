<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Models\Role;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\FacetQuery;
use Modules\Core\Tests\Stubs\Crud\BlackListedManyRelationStubModel;

function facet_blacklist_run(User $actor, FacetQuery $facet): mixed
{
    $request = new class extends Request
    {
        public function validated(?string $key = null, mixed $default = null): mixed
        {
            return $key === null ? [] : $default;
        }
    };
    $request->setUserResolver(fn (): User => $actor);

    $model = new BlackListedManyRelationStubModel;
    $data = new ReflectionClass(ListRequestData::class)->newInstanceWithoutConstructor();

    foreach ([
        'request' => $request, 'mainEntity' => $model->getTable(), 'primaryKey' => $model->getKeyName(),
        'connection' => $model->getConnectionName(), 'model' => $model, 'columns' => [], 'relations' => [],
        'sort' => [], 'filters' => null, 'group_by' => [], 'pagination' => 25, 'page' => null,
        'skip' => null, 'take' => null, 'from' => null, 'to' => null, 'limit' => null, 'count' => false,
    ] as $prop => $value) {
        new ReflectionProperty($data, $prop)->setValue($data, $value);
    }

    return app(CrudService::class)->facetValues($data, $facet);
}

it('refuses a facet on a black-listed relation', function (): void {
    Schema::create('black_listed_many_stub', function ($table): void {
        $table->id();
    });
    Schema::create('black_listed_many_stub_user', function ($table): void {
        $table->unsignedBigInteger('stub_id');
        $table->unsignedBigInteger('user_id');
    });

    $role = Role::factory()->create(['name' => config('permission.roles.superadmin'), 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole($role);
    auth()->login($admin);

    $stub = BlackListedManyRelationStubModel::query()->create([]);
    $stub->members()->attach($admin->getKey());

    expect(fn () => facet_blacklist_run($admin, new FacetQuery(groupBy: 'descendants', relation: 'descendants', fields: ['name'])))
        ->toThrow(InvalidArgumentException::class, 'is not a BelongsToMany/MorphToMany relation');

    $page = facet_blacklist_run($admin, new FacetQuery(groupBy: 'members', relation: 'members', fields: ['name']));

    expect($page->values)->toHaveCount(1);
});
