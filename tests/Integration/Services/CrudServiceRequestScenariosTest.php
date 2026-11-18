<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Engines\Engine as ScoutEngine;
use Modules\Core\Approvals\Operation;
use Modules\Core\Casts\Column;
use Modules\Core\Casts\ColumnType;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\HistoryRequestData;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Casts\TreeRequestData;
use Modules\Core\Helpers\MigrateUtils;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Engines\DatabaseEngine as CoreDatabaseEngine;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Search\Traits\Searchable as CoreSearchable;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Tests\Stubs\ActivationStubModel;
use Modules\Core\Tests\Stubs\Crud\AncestorTreeStubModel;
use Modules\Core\Tests\Stubs\Crud\TreeStubModel;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;
use Symfony\Component\HttpFoundation\Response;

function crud_cov_set(object $obj, string $prop, mixed $value): void
{
    $p = new ReflectionProperty($obj, $prop);
    $p->setAccessible(true);
    $p->setValue($obj, $value);
}

/**
 * @param  class-string  $class
 * @param  array<int,Column>  $columns
 */
function crud_cov_make_request_data(string $class, Model $model, Request $request, string|array $primary_key, array $columns = []): object
{
    $ref = new ReflectionClass($class);
    $data = $ref->newInstanceWithoutConstructor();

    crud_cov_set($data, 'request', $request);
    crud_cov_set($data, 'mainEntity', $model->getTable());
    crud_cov_set($data, 'primaryKey', $primary_key);
    crud_cov_set($data, 'model', $model);
    crud_cov_set($data, 'connection', $model->getConnectionName());

    if (property_exists($data, 'columns')) {
        crud_cov_set($data, 'columns', $columns);
    }

    if (property_exists($data, 'relations')) {
        crud_cov_set($data, 'relations', []);
    }

    if (property_exists($data, 'sort')) {
        crud_cov_set($data, 'sort', []);
    }

    if (property_exists($data, 'filters')) {
        crud_cov_set($data, 'filters', null);
    }

    if (property_exists($data, 'group_by')) {
        crud_cov_set($data, 'group_by', []);
    }

    return $data;
}

/**
 * @param  array<string,mixed>  $validated
 */
function crud_cov_validated_request(array $validated = []): Request
{
    return new class($validated) extends Request
    {
        /**
         * @param  array<string,mixed>  $validated
         */
        public function __construct(private readonly array $validated)
        {
            parent::__construct($validated);
        }

        public function validated(?string $key = null, mixed $default = null): mixed
        {
            if ($key === null) {
                return $this->validated;
            }

            return $this->validated[$key] ?? $default;
        }
    };
}

function crud_cov_login_superadmin(): User
{
    $superadmin_role = Role::factory()->create([
        'name' => config('permission.roles.superadmin'),
        'guard_name' => 'web',
    ]);

    $user = User::factory()->create([
        'username' => 'crud_cov_admin_' . uniqid(),
        'email' => 'crud_cov_admin_' . uniqid() . '@example.com',
    ]);
    $user->assignRole($superadmin_role);
    auth()->login($user);

    return $user;
}

/**
 * A non-superadmin user granted exactly the given permission names (web guard).
 *
 * @param  list<string>  $permission_names
 */
function crud_cov_user_with_permissions(array $permission_names): User
{
    $user = User::factory()->create([
        'username' => 'crud_perm_' . uniqid(),
        'email' => 'crud_perm_' . uniqid() . '@example.com',
    ]);

    foreach ($permission_names as $name) {
        Permission::findOrCreate($name, 'web');
        $user->givePermissionTo($name);
    }

    auth()->login($user);

    return $user;
}

function crud_cov_advanced_search_returning(int|string $id): AdvancedSearchService
{
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble
        ->shouldReceive('search')
        ->andReturnUsing(static fn (Model $model, string $query, array $plan, ?array $vector, int $page, int $perPage): AdvancedSearchResult => new AdvancedSearchResult(
            hits: [['id' => (string) $id, 'score' => 1.0, 'source' => []]],
            total: 1,
            page: $page,
            perPage: $perPage,
            totalPages: 1,
            meta: ['matching' => ['effective_preference' => 'balanced']],
        ));

    return new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new FallbackSearchPlanner(), intent_parser: new SimpleQueryIntentParser()),
        $ensemble,
        app(),
    );
}

function crud_cov_make_modify_data(Model $model, Request $request, array $changes = [], string|array|int $primary_key = 'id'): ModifyRequestData
{
    $modify_ref = new ReflectionClass(ModifyRequestData::class);

    /** @var ModifyRequestData $modify */
    $modify = $modify_ref->newInstanceWithoutConstructor();
    crud_cov_set($modify, 'request', $request);
    crud_cov_set($modify, 'mainEntity', $model->getTable());

    /** @var string|array $pk */
    $pk = is_int($primary_key) ? (string) $primary_key : $primary_key;
    crud_cov_set($modify, 'primaryKey', $pk);
    crud_cov_set($modify, 'model', $model);
    crud_cov_set($modify, 'connection', $model->getConnectionName());
    crud_cov_set($modify, 'changes', $changes);

    return $modify;
}

it('list applies group_by branch when requested', function (): void {
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = crud_cov_validated_request();
    $request->setUserResolver(fn () => $superadmin);
    $model = new User();
    $data = crud_cov_make_request_data(
        ListRequestData::class,
        $model,
        $request,
        $model->getKeyName(),
        [new Column('users.lang', ColumnType::Column)],
    );

    crud_cov_set($data, 'page', null);
    crud_cov_set($data, 'from', null);
    crud_cov_set($data, 'limit', null);
    crud_cov_set($data, 'count', false);
    crud_cov_set($data, 'pagination', 25);
    crud_cov_set($data, 'group_by', ['lang']);

    User::factory()->create(['lang' => 'en']);
    User::factory()->create(['lang' => 'en']);
    User::factory()->create(['lang' => 'it']);

    $result = $service->list($data);

    expect($result->data)->toBeInstanceOf(Illuminate\Support\Collection::class);
    expect($result->data->keys()->all())->toContain('en');
});

it('detail throws when primary key is missing', function (): void {
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = crud_cov_validated_request();
    $request->setUserResolver(fn () => $superadmin);
    $model = new User();
    $data = crud_cov_make_request_data(
        DetailRequestData::class,
        $model,
        $request,
        $model->getKeyName(),
        [new Column('users.id', ColumnType::Column)],
    );

    $service->detail($data);
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class, 'Primary key is required for detail.');

it('search rejects models without core searchable trait', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = crud_cov_validated_request(['qs' => 'needle']);
    $model = new User();
    $data = crud_cov_make_request_data(SearchRequestData::class, $model, $request, $model->getKeyName());
    crud_cov_set($data, 'qs', 'needle');
    crud_cov_set($data, 'mode', SearchMode::Basic);

    $result = $service->search($data);

    expect($result->statusCode)->toBe(Response::HTTP_BAD_REQUEST)
        ->and($result->error)->toBe('Full-search operation can be done only on Searchable entities');
});

it('search returns a controlled result for orchestrated mode when the model does not use a core search engine', function (): void {
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));
    $engine = Mockery::mock(ScoutEngine::class);

    $request = crud_cov_validated_request(['qs' => 'needle']);
    $request->setUserResolver(fn () => $superadmin);
    $model = new class($engine) extends Model
    {
        use CoreSearchable;

        protected $table = 'users';

        public function __construct(private readonly mixed $engine = null)
        {
            parent::__construct();
        }

        public function searchableUsing(): mixed
        {
            return $this->engine;
        }
    };

    $data = crud_cov_make_request_data(SearchRequestData::class, $model, $request, $model->getKeyName());
    crud_cov_set($data, 'qs', 'needle');
    crud_cov_set($data, 'mode', SearchMode::Orchestrated);

    $result = $service->search($data);

    expect($result->statusCode)->toBe(Response::HTTP_NOT_IMPLEMENTED)
        ->and($result->error)->toBe('Orchestrated search pipeline is not available for the configured search driver.');
});

it('search orchestrated mode hydrates advanced search ids', function (): void {
    config()->set('scout.driver', 'elasticsearch');

    $superadmin = crud_cov_login_superadmin();
    $target = User::factory()->create([
        'username' => 'advanced_target_' . uniqid(),
        'email' => 'advanced_target_' . uniqid() . '@example.com',
    ]);

    $advanced_search = crud_cov_advanced_search_returning($target->getKey());
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class), $advanced_search);
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();

    $request = crud_cov_validated_request(['qs' => 'needle']);
    $request->setUserResolver(fn () => $superadmin);
    $model = new class($engine) extends User
    {
        use CoreSearchable;

        public function __construct(private readonly mixed $engine = null)
        {
            parent::__construct();
        }

        public function searchableUsing(): mixed
        {
            return $this->engine;
        }
    };

    $data = crud_cov_make_request_data(SearchRequestData::class, $model, $request, $model->getKeyName());
    crud_cov_set($data, 'qs', 'needle');
    crud_cov_set($data, 'mode', SearchMode::Orchestrated);
    crud_cov_set($data, 'page', null);
    crud_cov_set($data, 'limit', 5);
    crud_cov_set($data, 'pagination', 5);
    crud_cov_set($data, 'from', null);
    crud_cov_set($data, 'to', null);
    crud_cov_set($data, 'count', false);

    $result = $service->search($data);

    expect($result->data)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class)
        ->and($result->data->first()?->getKey())->toBe($target->getKey())
        ->and($result->meta?->totalRecords)->toBe(1)
        ->and($result->meta?->currentRecords)->toBe(1)
        ->and($result->meta?->search['matching']['effective_preference'])->toBe('balanced');
});

it('search auto mode uses orchestrated search when current driver supports it', function (): void {
    config()->set('scout.driver', 'elasticsearch');

    $superadmin = crud_cov_login_superadmin();
    $target = User::factory()->create([
        'username' => 'auto_target_' . uniqid(),
        'email' => 'auto_target_' . uniqid() . '@example.com',
    ]);
    $advanced_search = crud_cov_advanced_search_returning($target->getKey());
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class), $advanced_search);
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();

    $request = crud_cov_validated_request(['qs' => 'needle']);
    $request->setUserResolver(fn () => $superadmin);
    $model = new class($engine) extends User
    {
        use CoreSearchable;

        public function __construct(private readonly mixed $engine = null)
        {
            parent::__construct();
        }

        public function searchableUsing(): mixed
        {
            return $this->engine;
        }
    };

    $data = crud_cov_make_request_data(SearchRequestData::class, $model, $request, $model->getKeyName());
    crud_cov_set($data, 'qs', 'needle');
    crud_cov_set($data, 'mode', SearchMode::Auto);
    crud_cov_set($data, 'page', 1);
    crud_cov_set($data, 'limit', 5);
    crud_cov_set($data, 'pagination', 5);
    crud_cov_set($data, 'from', 1);
    crud_cov_set($data, 'to', 6);
    crud_cov_set($data, 'count', false);

    $result = $service->search($data);

    expect($result->data)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class)
        ->and($result->data->first()?->getKey())->toBe($target->getKey())
        ->and($result->meta?->currentPage)->toBe(1)
        ->and($result->meta?->pagination)->toBe(5);
});

it('search auto mode uses orchestrated keyword search with the database engine', function (): void {
    config()->set('scout.driver', 'database');

    $superadmin = crud_cov_login_superadmin();
    $target = User::factory()->create([
        'username' => 'database_auto_target_' . uniqid(),
        'email' => 'database_auto_target_' . uniqid() . '@example.com',
    ]);
    $advanced_search = crud_cov_advanced_search_returning($target->getKey());
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class), $advanced_search);
    $engine = new CoreDatabaseEngine();

    $request = crud_cov_validated_request(['qs' => 'needle']);
    $request->setUserResolver(fn () => $superadmin);
    $model = new class($engine) extends User
    {
        use CoreSearchable;

        public function __construct(private readonly mixed $engine = null)
        {
            parent::__construct();
        }

        public function searchableUsing(): mixed
        {
            return $this->engine;
        }
    };

    $data = crud_cov_make_request_data(SearchRequestData::class, $model, $request, $model->getKeyName());
    crud_cov_set($data, 'qs', 'needle');
    crud_cov_set($data, 'mode', SearchMode::Auto);
    crud_cov_set($data, 'page', 1);
    crud_cov_set($data, 'limit', 5);
    crud_cov_set($data, 'pagination', 5);
    crud_cov_set($data, 'from', 1);
    crud_cov_set($data, 'to', 6);
    crud_cov_set($data, 'count', false);

    $result = $service->search($data);

    expect($result->data)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class)
        ->and($result->data->first()?->getKey())->toBe($target->getKey())
        ->and($result->meta?->currentPage)->toBe(1)
        ->and($result->meta?->pagination)->toBe(5);
});

it('search rejects filters that cannot be pushed into scout pagination', function (): void {
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = crud_cov_validated_request(['qs' => 'needle']);
    $request->setUserResolver(fn () => $superadmin);
    $model = new class extends Model
    {
        use CoreSearchable;

        protected $table = 'users';
    };

    $data = crud_cov_make_request_data(SearchRequestData::class, $model, $request, $model->getKeyName());
    crud_cov_set($data, 'qs', 'needle');
    crud_cov_set($data, 'mode', SearchMode::Basic);
    crud_cov_set($data, 'filters', new FiltersGroup([
        new Filter('name', 'needle', FilterOperator::Like),
    ]));

    $result = $service->search($data);

    expect($result->statusCode)->toBe(Response::HTTP_BAD_REQUEST)
        ->and($result->error)->toBe('Search filters must be applied by the search engine to keep pagination consistent.');
});

it('getModelKeyValue handles composite key request data', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $model = new class extends Model
    {
        public function getKeyName(): array
        {
            return ['tenant_id', 'id'];
        }
    };

    $modify_ref = new ReflectionClass(ModifyRequestData::class);
    $modify = $modify_ref->newInstanceWithoutConstructor();
    crud_cov_set($modify, 'model', $model);
    crud_cov_set($modify, 'changes', ['tenant_id' => 11, 'id' => 22]);

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('getModelKeyValue');
    $method->setAccessible(true);
    $key_value = $method->invoke($service, $modify);

    expect($key_value)->toBe(['tenant_id' => 11, 'id' => 22]);
});

it('tree selects relation type and switches between sole and get modes', function (): void {
    if (! Schema::hasTable('crud_cov_tree')) {
        Schema::create('crud_cov_tree', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
        MigrateUtils::closureTable('crud_cov_tree');
    }

    $tree_model = new TreeStubModel;

    $root = $tree_model->newQuery()->create(['name' => 'root']);

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request_single = Request::create('/tree', 'GET', ['id' => $root->getKey()]);
    $request_single->query->set('id', $root->getKey());
    $request_single->setUserResolver(fn () => $superadmin);
    $data_single = crud_cov_make_request_data(TreeRequestData::class, $tree_model, $request_single, 'id', [
        new Column('crud_cov_tree.id', ColumnType::Column),
    ]);
    crud_cov_set($data_single, 'parents', true);
    crud_cov_set($data_single, 'children', true);
    $single_result = $service->tree($data_single);
    expect($single_result->data)->toBeInstanceOf(Model::class);

    $tree_model->newQuery()->create(['name' => 'child', 'parent_id' => $root->getKey()]);

    $request_many = Request::create('/tree', 'GET');
    $request_many->setUserResolver(fn () => $superadmin);
    $data_many = crud_cov_make_request_data(TreeRequestData::class, $tree_model, $request_many, 'id', [
        new Column('crud_cov_tree.id', ColumnType::Column),
    ]);
    crud_cov_set($data_many, 'parents', false);
    crud_cov_set($data_many, 'children', true);
    $many_result = $service->tree($data_many);
    expect($many_result->data)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});

it('activate and inactivate use doActivateOperation path', function (): void {
    if (! Schema::hasTable('crud_cov_soft')) {
        Schema::create('crud_cov_soft', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    $soft_model = new class extends Model
    {
        use SoftDeletes;

        protected $table = 'crud_cov_soft';

        protected $guarded = [];
    };

    $record = $soft_model->newQuery()->create(['name' => 'x']);
    $record->delete();

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = Request::create('/modify', 'POST', ['id' => $record->getKey()]);
    $request->request->set('id', $record->getKey());
    $request->setUserResolver(fn () => $superadmin);
    $modify = crud_cov_make_modify_data($soft_model, $request, []);

    $activate_result = $service->activate($modify);
    expect($activate_result->data)->toBeInstanceOf(Model::class);

    $inactivate_result = $service->inactivate($modify);
    expect($inactivate_result->data)->toBeInstanceOf(Model::class);
});

function crud_perm_soft_model(): Model
{
    if (! Schema::hasTable('crud_perm_soft')) {
        Schema::create('crud_perm_soft', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    return new class extends Model
    {
        use SoftDeletes;

        protected $table = 'crud_perm_soft';

        protected $guarded = [];
    };
}

it('inactivate soft-deletes when the user holds the delete permission', function (): void {
    $soft_model = crud_perm_soft_model();
    $record = $soft_model->newQuery()->create(['name' => 'live']);

    $user = crud_cov_user_with_permissions(['default.crud_perm_soft.delete']);
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = Request::create('/modify', 'POST', ['id' => $record->getKey()]);
    $request->request->set('id', $record->getKey());
    $request->setUserResolver(fn () => $user);
    $modify = crud_cov_make_modify_data($soft_model, $request, []);

    $service->inactivate($modify);

    expect($soft_model->newQuery()->withTrashed()->find($record->getKey())->trashed())->toBeTrue();
});

it('inactivate is denied when the user lacks the delete permission', function (): void {
    $soft_model = crud_perm_soft_model();
    $record = $soft_model->newQuery()->create(['name' => 'live']);

    // Only restore — before the fix this passed, because inactivate wrongly
    // required the restore permission instead of delete.
    $user = crud_cov_user_with_permissions(['default.crud_perm_soft.restore']);
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = Request::create('/modify', 'POST', ['id' => $record->getKey()]);
    $request->request->set('id', $record->getKey());
    $request->setUserResolver(fn () => $user);
    $modify = crud_cov_make_modify_data($soft_model, $request, []);

    expect(fn (): mixed => $service->inactivate($modify))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('activate restores a trashed record and leaves it active', function (): void {
    $soft_model = crud_perm_soft_model();
    $record = $soft_model->newQuery()->create(['name' => 'gone']);
    $record->delete();

    $user = crud_cov_user_with_permissions(['default.crud_perm_soft.restore']);
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = Request::create('/modify', 'POST', ['id' => $record->getKey()]);
    $request->request->set('id', $record->getKey());
    $request->setUserResolver(fn () => $user);
    $modify = crud_cov_make_modify_data($soft_model, $request, []);

    $service->activate($modify);

    // Regression: activate must not re-soft-delete after restoring, so a plain
    // (not withTrashed) lookup finds a live record.
    expect($soft_model->newQuery()->find($record->getKey()))->not->toBeNull();
});

it('insert, update and delete cover core mutation flows', function (): void {
    if (! Schema::hasTable('crud_cov_items')) {
        Schema::create('crud_cov_items', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    $item_model = new class extends Model
    {
        protected $table = 'crud_cov_items';

        protected $fillable = ['name'];
    };

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $insert_request = Request::create('/modify', 'POST', ['name' => 'first']);
    $insert_request->setUserResolver(fn () => $superadmin);
    $insert_data = crud_cov_make_modify_data($item_model, $insert_request, [
        'name' => 'first',
        'not_fillable' => 'x',
    ]);

    $insert_result = $service->insert($insert_data);
    expect($insert_result->statusCode)->toBe(201);
    expect($insert_result->error)->toContain("Discarder 'not_fillable'");

    $inserted_id = $insert_result->data->getKey();

    $update_request = Request::create('/modify', 'POST', ['id' => $inserted_id]);
    $update_request->request->set('id', $inserted_id);
    $update_request->setUserResolver(fn () => $superadmin);
    $update_data = crud_cov_make_modify_data($item_model, $update_request, [
        'name' => 'updated',
        'not_fillable' => 'x',
        'filters' => 'ignored',
    ]);

    $update_result = $service->update($update_data);
    expect($update_result->data)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
    expect($update_result->data->first()?->getAttribute('name'))->toBe('updated');
    expect($update_result->error)->toContain("Discarder 'not_fillable'");

    $delete_request = Request::create('/modify', 'POST', ['id' => $inserted_id]);
    $delete_request->request->set('id', $inserted_id);
    $delete_request->setUserResolver(fn () => $superadmin);
    $delete_data = crud_cov_make_modify_data($item_model, $delete_request, []);

    $delete_result = $service->delete($delete_data);
    expect($delete_result->data)->toBe(['deleted' => 1]);
});

it('updates affinity models inside the affinity transaction', function (): void {
    config()->set('database.connections.affinity', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('affinity');

    Schema::connection('affinity')->create('activation_stub', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    try {
        $model = (new ActivationStubModel)->setConnection('affinity');
        $record = $model->newQuery()->create(['name' => 'before']);
        $superadmin = crud_cov_login_superadmin();
        $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));
        $request = Request::create('/modify', 'POST', ['id' => $record->getKey()]);
        $request->request->set('id', $record->getKey());
        $request->setUserResolver(fn () => $superadmin);
        $request_data = crud_cov_make_modify_data($model, $request, ['name' => 'after']);

        $affinity_transaction_started = false;
        $default_transaction_started = false;
        DB::connection('affinity')->beforeStartingTransaction(static function () use (&$affinity_transaction_started): void {
            $affinity_transaction_started = true;
        });
        DB::connection()->beforeStartingTransaction(static function () use (&$default_transaction_started): void {
            $default_transaction_started = true;
        });
        $select_transaction_level = null;
        $select_sql = null;
        DB::connection('affinity')->listen(static function (QueryExecuted $query) use (&$select_transaction_level, &$select_sql): void {
            if ($select_transaction_level === null && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'activation_stub')) {
                $select_transaction_level = $query->connection->transactionLevel();
                $select_sql = $query->sql;
            }
        });

        $result = $service->update($request_data);

        expect($result->data->first()?->name)->toBe('after')
            ->and($affinity_transaction_started)->toBeTrue()
            ->and($default_transaction_started)->toBeFalse()
            ->and($select_transaction_level)->toBe(1)
            ->and($select_sql)->toContain('limit 100');
    } finally {
        DB::disconnect('affinity');
        DB::purge('affinity');
    }
});

it('history and tree throw on unsupported models', function (): void {
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $plain_model = new class extends Model
    {
        protected $table = 'users';
    };

    $history_request = crud_cov_validated_request();
    $history_request->setUserResolver(fn () => $superadmin);
    $history_data = crud_cov_make_request_data(
        HistoryRequestData::class,
        $plain_model,
        $history_request,
        'id',
        [new Column('users.id', ColumnType::Column)],
    );

    expect(fn () => $service->history($history_data))
        ->toThrow(BadMethodCallException::class);

    $tree_request = crud_cov_validated_request();
    $tree_request->setUserResolver(fn () => $superadmin);
    $tree_data = crud_cov_make_request_data(
        TreeRequestData::class,
        $plain_model,
        $tree_request,
        'id',
        [new Column('users.id', ColumnType::Column)],
    );
    crud_cov_set($tree_data, 'parents', false);
    crud_cov_set($tree_data, 'children', true);

    expect(fn () => $service->tree($tree_data))
        ->toThrow(UnexpectedValueException::class);
});

it('detail resolves single primary key and applies main-entity method columns', function (): void {
    Schema::dropIfExists('crud_cov_d_scalar');
    Schema::create('crud_cov_d_scalar', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('code')->nullable();
        $table->timestamps();
    });

    $scalar_model = new class extends Model
    {
        protected $table = 'crud_cov_d_scalar';

        protected $guarded = [];

        public function rowCode(): string
        {
            return 'X';
        }

        /**
         * A request may only invoke a method the model declares as computable for the CRUD.
         *
         * @return array<string, array<string, list<string>>>
         */
        public function crudComputedDependencies(): array
        {
            return ['rowCode' => ['columns' => []]];
        }
    };

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $row = $scalar_model->newQuery()->create(['code' => 'c']);

    $request = crud_cov_validated_request(['id' => $row->getKey()]);
    $request->setUserResolver(fn () => $superadmin);
    $data = crud_cov_make_request_data(
        DetailRequestData::class,
        $scalar_model,
        $request,
        'id',
        [
            new Column('crud_cov_d_scalar.id', ColumnType::Column),
            new Column('crud_cov_d_scalar.rowCode', ColumnType::Method),
        ],
    );

    $result = $service->detail($data);

    expect($result->data->getKey())->toBe($row->getKey());
    expect($result->data->getAttribute('rowCode'))->toBe('X');
});

it('detail resolves composite primary key from validated input', function (): void {
    if (! Schema::hasTable('crud_cov_composite_detail')) {
        Schema::create('crud_cov_composite_detail', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('id');
            $table->string('slug')->nullable();
            $table->timestamps();
            $table->primary(['tenant_id', 'id']);
        });
    }

    $composite = new class extends Model
    {
        public $incrementing = false;

        protected $table = 'crud_cov_composite_detail';

        protected $guarded = [];

        public function getKeyName(): array
        {
            return ['tenant_id', 'id'];
        }
    };

    $composite->newQuery()->create([
        'tenant_id' => 7,
        'id' => 8,
        'slug' => 'row',
    ]);

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = crud_cov_validated_request(['tenant_id' => 7, 'id' => 8]);
    $request->setUserResolver(fn () => $superadmin);
    $data = crud_cov_make_request_data(
        DetailRequestData::class,
        $composite,
        $request,
        ['tenant_id', 'id'],
        [new Column('crud_cov_composite_detail.slug', ColumnType::Column)],
    );

    $result = $service->detail($data);

    expect($result->data)->toBeInstanceOf(Model::class);
    expect($result->data->getAttribute('slug'))->toBe('row');
});

it('history returns record and version history for versionable models', function (): void {
    config()->set('versionable.version_model', Modules\Core\Models\Version::class);

    Schema::dropIfExists('crud_cov_hist_one');
    Schema::create('crud_cov_hist_one', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('label')->nullable();
        $table->timestamps();
    });

    $hist_model = new class extends Model
    {
        use HasApprovals;
        use HasVersions;

        protected $table = 'crud_cov_hist_one';

        protected $guarded = [];

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    };

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $target_id = DB::table('crud_cov_hist_one')->insertGetId([
        'label' => 'hist-row',
        'user_id' => $superadmin->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $target = $hist_model->newQuery()->findOrFail($target_id);

    $request = crud_cov_validated_request(['id' => $target->getKey()]);
    $request->setUserResolver(fn () => $superadmin);
    $history_data = crud_cov_make_request_data(
        HistoryRequestData::class,
        $target,
        $request,
        'id',
        [new Column('crud_cov_hist_one.id', ColumnType::Column)],
    );

    $limit_prop = new ReflectionProperty($history_data, 'limit');
    $limit_prop->setAccessible(true);
    $limit_prop->setValue($history_data, 5);

    $result = $service->history($history_data);

    expect($result->data)->toBeArray();
    expect($result->data)->toHaveKeys(['record', 'history']);
});

it('tree uses ancestorsAndSelf when only parents are requested', function (): void {
    Schema::dropIfExists('crud_cov_tree_anc_closure');
    Schema::dropIfExists('crud_cov_tree_anc');
    Schema::create('crud_cov_tree_anc', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('parent_id')->nullable();
        $table->string('name');
        $table->timestamps();
    });
    MigrateUtils::closureTable('crud_cov_tree_anc');

    $tree_model = new AncestorTreeStubModel;

    $solo = $tree_model->newQuery()->create(['name' => 'solo-node', 'parent_id' => null]);

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = Request::create('/tree', 'GET', ['id' => $solo->getKey()]);
    $request->query->set('id', $solo->getKey());
    $request->setUserResolver(fn () => $superadmin);
    $data = crud_cov_make_request_data(TreeRequestData::class, $tree_model, $request, 'id', [
        new Column('crud_cov_tree_anc.id', ColumnType::Column),
    ]);
    crud_cov_set($data, 'parents', true);
    crud_cov_set($data, 'children', false);

    $result = $service->tree($data);

    expect($result->data)->toBeInstanceOf(Model::class);
});

it('list uses pagination from-to and count branches', function (): void {
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));
    $model = new User();

    $base_columns = [new Column('users.id', ColumnType::Column)];

    $req_page = crud_cov_validated_request();
    $req_page->setUserResolver(fn () => $superadmin);
    $data_page = crud_cov_make_request_data(ListRequestData::class, $model, $req_page, 'id', $base_columns);
    crud_cov_set($data_page, 'page', 1);
    crud_cov_set($data_page, 'from', 1);
    crud_cov_set($data_page, 'to', 5);
    crud_cov_set($data_page, 'pagination', 5);
    crud_cov_set($data_page, 'filters', null);
    crud_cov_set($data_page, 'count', false);
    crud_cov_set($data_page, 'group_by', []);
    crud_cov_set($data_page, 'limit', null);
    $page_result = $service->list($data_page);
    expect($page_result->data)->toBeInstanceOf(Illuminate\Support\Collection::class);

    $req_range = crud_cov_validated_request();
    $req_range->setUserResolver(fn () => $superadmin);
    $data_range = crud_cov_make_request_data(ListRequestData::class, $model, $req_range, 'id', $base_columns);
    crud_cov_set($data_range, 'page', null);
    crud_cov_set($data_range, 'from', 1);
    crud_cov_set($data_range, 'to', 3);
    crud_cov_set($data_range, 'pagination', 25);
    crud_cov_set($data_range, 'filters', null);
    crud_cov_set($data_range, 'count', false);
    crud_cov_set($data_range, 'group_by', []);
    crud_cov_set($data_range, 'limit', null);
    $range_result = $service->list($data_range);
    expect($range_result->data)->toBeInstanceOf(Illuminate\Support\Collection::class);

    $req_count = crud_cov_validated_request();
    $req_count->setUserResolver(fn () => $superadmin);
    $data_count = crud_cov_make_request_data(ListRequestData::class, $model, $req_count, 'id', $base_columns);
    crud_cov_set($data_count, 'page', null);
    crud_cov_set($data_count, 'from', null);
    crud_cov_set($data_count, 'to', null);
    crud_cov_set($data_count, 'pagination', 25);
    crud_cov_set($data_count, 'filters', null);
    crud_cov_set($data_count, 'count', true);
    crud_cov_set($data_count, 'group_by', []);
    crud_cov_set($data_count, 'limit', null);
    $count_result = $service->list($data_count);
    expect($count_result->data)->toBeInt();
});

it('approve and disapprove run modification workflows', function (): void {
    if (! Schema::hasTable('crud_cov_approval')) {
        Schema::create('crud_cov_approval', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    } elseif (! Schema::hasColumn('crud_cov_approval', 'deleted_at')) {
        Schema::table('crud_cov_approval', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->softDeletes();
        });
    }

    $approval_model = new class extends Model
    {
        use HasApprovals;
        use SoftDeletes;

        protected $table = 'crud_cov_approval';

        protected $guarded = [];

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    };

    $author = User::factory()->create();
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $row = $approval_model->newQuery()->create(['title' => 'pending']);

    $mod = Modification::query()->create([
        'modifiable_id' => $row->getKey(),
        'modifiable_type' => $approval_model::class,
        'modifier_id' => $author->getKey(),
        'modifier_type' => User::class,
        'active' => true,
        'operation' => Operation::Update,
        'approvers_required' => 2,
        'disapprovers_required' => 2,
        'md5' => md5(json_encode(['title' => 'next'])),
        'modifications' => ['title' => 'next'],
    ]);

    $req = Request::create('/approve', 'POST');
    $req->setUserResolver(fn () => $superadmin);
    $modify_loop = crud_cov_make_modify_data($row, $req, ['id' => $row->getKey()], $row->getKey());
    $loop_result = $service->approve($modify_loop);
    expect($loop_result->data)->toBeInstanceOf(Model::class);

    $modify_single = crud_cov_make_modify_data($row, $req, [
        'id' => $row->getKey(),
        'modification' => $mod->getKey(),
    ], $row->getKey());
    $single_result = $service->approve($modify_single);
    expect($single_result->data)->toBeInstanceOf(Model::class);

    $disapprove_result = $service->disapprove($modify_single);
    expect($disapprove_result->data)->toBeInstanceOf(Model::class);
});

it('approves the modification on the target model connection', function (): void {
    config()->set('database.connections.crud-approval-secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $schema = Schema::connection('crud-approval-secondary');
    $schema->create('crud_cov_approval_affinity', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create((new Modification)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modifiable_id')->nullable();
        $table->string('modifiable_type')->nullable();
        $table->unsignedBigInteger('modifier_id')->nullable();
        $table->string('modifier_type')->nullable();
        $table->boolean('active')->default(true);
        $table->string('operation', 16)->default('update');
        $table->unsignedInteger('approvers_required')->default(1);
        $table->unsignedInteger('disapprovers_required')->default(1);
        $table->string('md5');
        $table->json('modifications');
        $table->timestamps();
    });
    $schema->create((new Modules\Core\Models\Approval)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modification_id');
        $table->unsignedBigInteger('approver_id');
        $table->string('approver_type');
        $table->text('reason')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });
    $schema->create((new Modules\Core\Models\Disapproval)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modification_id');
        $table->unsignedBigInteger('disapprover_id');
        $table->string('disapprover_type');
        $table->text('reason')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });

    $model = (new class extends Model
    {
        use HasApprovals;
        use SoftDeletes;

        protected $table = 'crud_cov_approval_affinity';

        protected $guarded = [];

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    })->setConnection('crud-approval-secondary');
    $row = $model->newQuery()->create(['id' => 7101, 'title' => 'secondary target']);
    $author = User::factory()->create();
    $superadmin = crud_cov_login_superadmin();
    $attributes = [
        'id' => 7201,
        'modifiable_id' => $row->getKey(),
        'modifiable_type' => $model::class,
        'modifier_id' => $author->getKey(),
        'modifier_type' => User::class,
        'active' => true,
        'operation' => Operation::Update,
        'approvers_required' => 2,
        'disapprovers_required' => 2,
        'md5' => md5('affinity'),
        'modifications' => json_encode(['title' => ['original' => 'secondary target', 'modified' => 'approved']]),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $schema->getConnection()->table((new Modification)->getTable())->insert($attributes);
    (new Modification)->getConnection()->table((new Modification)->getTable())->insert($attributes);

    $request = Request::create('/approve', 'POST');
    $request->setUserResolver(fn () => $superadmin);
    $data = crud_cov_make_modify_data($row, $request, [
        'id' => $row->getKey(),
        'modification' => 7201,
    ], $row->getKey());

    (new CrudService(app(AuthorizationService::class), app(QueryBuilder::class)))->approve($data);

    expect($schema->getConnection()->table((new Modules\Core\Models\Approval)->getTable())->where('modification_id', 7201)->count())->toBe(1)
        ->and(Modules\Core\Models\Approval::query()->where('modification_id', 7201)->count())->toBe(0)
        ->and($schema->getConnection()->transactionLevel())->toBe(0);

    $failing_model = (new class extends Model
    {
        use HasApprovals;
        use SoftDeletes;

        protected $table = 'crud_cov_approval_affinity';

        protected $guarded = [];

        public function applyModificationChanges(Modification $modification, bool $approved): void
        {
            throw new RuntimeException('forced approval failure');
        }

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    })->setConnection('crud-approval-secondary');
    $failing_row = $failing_model->newQuery()->create(['id' => 7102, 'title' => 'rollback target']);
    $rollback_attributes = $attributes;
    $rollback_attributes['id'] = 7202;
    $rollback_attributes['modifiable_id'] = $failing_row->getKey();
    $rollback_attributes['modifiable_type'] = $failing_model::class;
    $rollback_attributes['approvers_required'] = 1;
    $schema->getConnection()->table((new Modification)->getTable())->insert($rollback_attributes);
    $rollback_data = crud_cov_make_modify_data($failing_row, $request, [
        'id' => $failing_row->getKey(),
        'modification' => 7202,
    ], $failing_row->getKey());

    expect(fn () => (new CrudService(app(AuthorizationService::class), app(QueryBuilder::class)))->approve($rollback_data))
        ->toThrow(RuntimeException::class, 'forced approval failure')
        ->and($schema->getConnection()->table((new Modules\Core\Models\Approval)->getTable())->where('modification_id', 7202)->count())->toBe(0)
        ->and($schema->getConnection()->table((new Modification)->getTable())->where('id', 7202)->value('active'))->toBe(1)
        ->and($schema->getConnection()->transactionLevel())->toBe(0);
});

it('keeps one owner-scoped vote per actor while its reason and direction change', function (): void {
    config()->set('database.connections.crud_approval_denied_secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $schema = Schema::connection('crud_approval_denied_secondary');
    $schema->create('crud_cov_approval_affinity_denied', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create((new Modification)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modifiable_id')->nullable();
        $table->string('modifiable_type')->nullable();
        $table->unsignedBigInteger('modifier_id')->nullable();
        $table->string('modifier_type')->nullable();
        $table->boolean('active')->default(true);
        $table->string('operation', 16)->default('update');
        $table->unsignedInteger('approvers_required')->default(1);
        $table->unsignedInteger('disapprovers_required')->default(1);
        $table->string('md5');
        $table->json('modifications');
        $table->timestamps();
    });
    $schema->create((new Modules\Core\Models\Approval)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modification_id');
        $table->unsignedBigInteger('approver_id');
        $table->string('approver_type');
        $table->text('reason')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });
    $schema->create((new Modules\Core\Models\Disapproval)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modification_id');
        $table->unsignedBigInteger('disapprover_id');
        $table->string('disapprover_type');
        $table->text('reason')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });

    $model = new class extends Model
    {
        use HasApprovals;
        use SoftDeletes;

        protected $connection = 'crud_approval_denied_secondary';

        protected $table = 'crud_cov_approval_affinity_denied';

        protected $guarded = [];

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    };
    $row = $model->newQuery()->create(['id' => 7301, 'title' => 'approval sentinel']);
    $author = User::factory()->create();
    $user = User::factory()->create();
    $endpoint_permission = Permission::query()->create([
        'name' => 'crud_approval_denied_secondary.crud_cov_approval_affinity_denied.approve',
        'guard_name' => 'web',
    ]);
    $user->givePermissionTo($endpoint_permission);
    auth()->login($user);

    $attributes = [
        'id' => 7401,
        'modifiable_id' => $row->getKey(),
        'modifiable_type' => $model::class,
        'modifier_id' => $author->getKey(),
        'modifier_type' => User::class,
        'active' => true,
        'operation' => Operation::Update,
        'approvers_required' => 2,
        'disapprovers_required' => 2,
        'md5' => md5('unauthorized approval'),
        'modifications' => json_encode(['title' => ['original' => 'approval sentinel', 'modified' => 'must not apply']]),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $schema->getConnection()->table((new Modification)->getTable())->insert($attributes);
    (new Modification)->getConnection()->table((new Modification)->getTable())->insert($attributes);

    $request = Request::create('/approve', 'POST');
    $request->setUserResolver(fn () => $user);
    $data = crud_cov_make_modify_data($row, $request, [
        'id' => $row->getKey(),
        'modification' => 7401,
        'reason' => 'initial reason',
    ], $row->getKey());

    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));
    $service->approve($data);

    crud_cov_set($data, 'changes', [
        'id' => $row->getKey(),
        'modification' => 7401,
        'reason' => 'updated reason',
    ]);
    $service->approve($data);

    expect($schema->getConnection()->table((new Modules\Core\Models\Approval)->getTable())->where('modification_id', 7401)->count())->toBe(1)
        ->and($schema->getConnection()->table((new Modules\Core\Models\Approval)->getTable())->where('modification_id', 7401)->value('reason'))->toBe('updated reason')
        ->and($schema->getConnection()->table((new Modification)->getTable())->where('id', 7401)->value('active'))->toBe(1)
        ->and($row->fresh()?->title)->toBe('approval sentinel')
        ->and(Modules\Core\Models\Approval::query()->where('modification_id', 7401)->count())->toBe(0)
        ->and(Modification::query()->whereKey(7401)->value('active'))->toBeTrue();

    $disapprove_permission = Permission::query()->create([
        'name' => 'crud_approval_denied_secondary.crud_cov_approval_affinity_denied.disapprove',
        'guard_name' => 'web',
    ]);
    $user->givePermissionTo($disapprove_permission);
    $disapprove_request = Request::create('/disapprove', 'POST');
    $disapprove_request->setUserResolver(fn () => $user);
    $disapprove_data = crud_cov_make_modify_data($row, $disapprove_request, [
        'id' => $row->getKey(),
        'modification' => 7401,
        'reason' => 'changed direction',
    ], $row->getKey());

    $service->disapprove($disapprove_data);

    expect($schema->getConnection()->table((new Modules\Core\Models\Approval)->getTable())->where('modification_id', 7401)->count())->toBe(0)
        ->and($schema->getConnection()->table((new Modules\Core\Models\Disapproval)->getTable())->where('modification_id', 7401)->count())->toBe(1)
        ->and($schema->getConnection()->table((new Modules\Core\Models\Disapproval)->getTable())->where('modification_id', 7401)->value('reason'))->toBe('changed direction')
        ->and($schema->getConnection()->table((new Modification)->getTable())->where('id', 7401)->value('active'))->toBe(1);
});

it('does not cast an unauthorized disapproval vote on the target model connection', function (): void {
    config()->set('database.connections.crud_disapproval_denied_secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $schema = Schema::connection('crud_disapproval_denied_secondary');
    $schema->create('crud_cov_disapproval_affinity_denied', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create((new Modification)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modifiable_id')->nullable();
        $table->string('modifiable_type')->nullable();
        $table->unsignedBigInteger('modifier_id')->nullable();
        $table->string('modifier_type')->nullable();
        $table->boolean('active')->default(true);
        $table->string('operation', 16)->default('update');
        $table->unsignedInteger('approvers_required')->default(1);
        $table->unsignedInteger('disapprovers_required')->default(1);
        $table->string('md5');
        $table->json('modifications');
        $table->timestamps();
    });
    $schema->create((new Modules\Core\Models\Approval)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modification_id');
        $table->unsignedBigInteger('approver_id');
        $table->string('approver_type');
        $table->text('reason')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });
    $schema->create((new Modules\Core\Models\Disapproval)->getTable(), function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('modification_id');
        $table->unsignedBigInteger('disapprover_id');
        $table->string('disapprover_type');
        $table->text('reason')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
    });

    $model = new class extends Model
    {
        use HasApprovals;
        use SoftDeletes;

        protected $connection = 'crud_disapproval_denied_secondary';

        protected $table = 'crud_cov_disapproval_affinity_denied';

        protected $guarded = [];

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    };
    $row = $model->newQuery()->create(['id' => 7302, 'title' => 'disapproval sentinel']);
    $user = User::factory()->create();
    $endpoint_permission = Permission::query()->create([
        'name' => 'crud_disapproval_denied_secondary.crud_cov_disapproval_affinity_denied.approve',
        'guard_name' => 'web',
    ]);
    Permission::query()->create([
        'name' => 'default.crud_cov_disapproval_affinity_denied.disapprove',
        'guard_name' => 'web',
    ]);
    $user->givePermissionTo($endpoint_permission);
    auth()->login($user);

    $attributes = [
        'id' => 7402,
        'modifiable_id' => $row->getKey(),
        'modifiable_type' => $model::class,
        'modifier_id' => $user->getKey(),
        'modifier_type' => User::class,
        'active' => true,
        'operation' => Operation::Update,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('unauthorized disapproval'),
        'modifications' => json_encode(['title' => ['original' => 'disapproval sentinel', 'modified' => 'must not apply']]),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    $schema->getConnection()->table((new Modification)->getTable())->insert($attributes);
    (new Modification)->getConnection()->table((new Modification)->getTable())->insert($attributes);

    $request = Request::create('/disapprove', 'POST');
    $request->setUserResolver(fn () => $user);
    $data = crud_cov_make_modify_data($row, $request, [
        'id' => $row->getKey(),
        'modification' => 7402,
    ], $row->getKey());

    (new CrudService(app(AuthorizationService::class), app(QueryBuilder::class)))->disapprove($data);

    expect($schema->getConnection()->table((new Modules\Core\Models\Disapproval)->getTable())->where('modification_id', 7402)->count())->toBe(0)
        ->and($schema->getConnection()->table((new Modification)->getTable())->where('id', 7402)->value('active'))->toBe(1)
        ->and($row->fresh()?->title)->toBe('disapproval sentinel')
        ->and(Modules\Core\Models\Disapproval::query()->where('modification_id', 7402)->count())->toBe(0)
        ->and(Modification::query()->whereKey(7402)->value('active'))->toBeTrue();
});

it('disapprove iterates active modifications when no modification id is passed', function (): void {
    if (! Schema::hasTable('crud_cov_approval')) {
        Schema::create('crud_cov_approval', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    } elseif (! Schema::hasColumn('crud_cov_approval', 'deleted_at')) {
        Schema::table('crud_cov_approval', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->softDeletes();
        });
    }

    $approval_model = new class extends Model
    {
        use HasApprovals;
        use SoftDeletes;

        protected $table = 'crud_cov_approval';

        protected $guarded = [];

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    };

    $author = User::factory()->create();
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $row = $approval_model->newQuery()->create(['title' => 'dis-cursor']);

    Modification::query()->create([
        'modifiable_id' => $row->getKey(),
        'modifiable_type' => $approval_model::class,
        'modifier_id' => $author->getKey(),
        'modifier_type' => User::class,
        'active' => true,
        'operation' => Operation::Update,
        'approvers_required' => 2,
        'disapprovers_required' => 2,
        'md5' => md5(json_encode(['title' => 'a'])),
        'modifications' => ['title' => 'a'],
    ]);

    $req = Request::create('/disapprove', 'POST');
    $req->setUserResolver(fn () => $superadmin);
    $modify = crud_cov_make_modify_data($row, $req, ['id' => $row->getKey()], $row->getKey());

    $result = $service->disapprove($modify);

    expect($result->data)->toBeInstanceOf(Model::class);
});

it('approve throws when no active modifications exist', function (): void {
    if (! Schema::hasTable('crud_cov_approval')) {
        Schema::create('crud_cov_approval', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    } elseif (! Schema::hasColumn('crud_cov_approval', 'deleted_at')) {
        Schema::table('crud_cov_approval', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->softDeletes();
        });
    }

    $approval_model = new class extends Model
    {
        use HasApprovals;
        use SoftDeletes;

        protected $table = 'crud_cov_approval';

        protected $guarded = [];

        protected function requiresApprovalWhen($modifications): bool
        {
            return false;
        }
    };

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));
    $row = $approval_model->newQuery()->create(['title' => 'empty-mods']);

    $req = Request::create('/approve', 'POST');
    $req->setUserResolver(fn () => $superadmin);
    $modify = crud_cov_make_modify_data($row, $req, ['id' => $row->getKey()], $row->getKey());

    expect(fn () => $service->approve($modify))->toThrow(LogicException::class);
});

it('lock unlock and guard branches on lockable models', function (): void {
    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $target = User::factory()->create([
        'username' => 'lock_' . uniqid(),
        'email' => 'lock_' . uniqid() . '@example.com',
    ]);

    $req_no_query_id = Request::create('/lock', 'POST');
    $req_no_query_id->setUserResolver(fn () => $superadmin);
    $modify_unlocked = crud_cov_make_modify_data($target, $req_no_query_id, ['id' => $target->getKey()]);
    $lock_result = $service->lock($modify_unlocked);
    expect($lock_result->data)->not->toBeEmpty();

    $req_with_id = Request::create('/lock', 'POST', ['id' => $target->getKey()]);
    $req_with_id->request->set('id', $target->getKey());
    $req_with_id->setUserResolver(fn () => $superadmin);
    $modify_locked = crud_cov_make_modify_data($target, $req_with_id, ['id' => $target->getKey()]);

    // Re-locking a record the caller already holds is not an error and writes nothing: the empty
    // `data` is how the envelope says "still yours, still valid".
    expect($service->lock($modify_locked)->data)->toBeEmpty();

    $service->unlock($modify_unlocked);

    $req_missing = Request::create('/lock', 'POST', ['id' => 999_999_999]);
    $req_missing->request->set('id', 999_999_999);
    $req_missing->setUserResolver(fn () => $superadmin);
    $modify_missing = crud_cov_make_modify_data($target, $req_missing, ['id' => 999_999_999]);

    expect(fn () => $service->lock($modify_missing))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class, 'No model Found');
});

it('lock throws when model does not support locks', function (): void {
    if (! Schema::hasTable('crud_cov_items')) {
        Schema::create('crud_cov_items', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    $plain = new class extends Model
    {
        protected $table = 'crud_cov_items';

        protected $guarded = [];
    };

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $row = $plain->newQuery()->create(['name' => 'n']);

    $req = Request::create('/lock', 'POST');
    $req->setUserResolver(fn () => $superadmin);
    $modify = crud_cov_make_modify_data($row, $req, ['id' => $row->getKey()]);

    expect(fn () => $service->lock($modify))->toThrow(BadMethodCallException::class);
});

it('insert does not issue a redundant select on the created model table', function (): void {
    if (! Schema::hasTable('crud_perf_insert')) {
        Schema::create('crud_perf_insert', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    $item_model = new class extends Model
    {
        protected $table = 'crud_perf_insert';

        protected $fillable = ['name'];
    };

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = Request::create('/modify', 'POST', ['name' => 'first']);
    $request->setUserResolver(fn () => $superadmin);
    $insert_data = crud_cov_make_modify_data($item_model, $request, ['name' => 'first']);

    $selects_on_table = [];
    DB::connection()->listen(static function (QueryExecuted $query) use (&$selects_on_table): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'crud_perf_insert')) {
            $selects_on_table[] = $query->sql;
        }
    });

    $result = $service->insert($insert_data);

    expect($result->statusCode)->toBe(201)
        ->and($selects_on_table)->toBe([]);
});

it('activate does not issue a redundant select after restoring the record', function (): void {
    if (! Schema::hasTable('crud_perf_activate')) {
        Schema::create('crud_perf_activate', function (Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    $soft_model = new class extends Model
    {
        use SoftDeletes;

        protected $table = 'crud_perf_activate';

        protected $guarded = [];
    };

    $record = $soft_model->newQuery()->create(['name' => 'x']);
    $record->delete();

    $superadmin = crud_cov_login_superadmin();
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request = Request::create('/modify', 'POST', ['id' => $record->getKey()]);
    $request->request->set('id', $record->getKey());
    $request->setUserResolver(fn () => $superadmin);
    $modify = crud_cov_make_modify_data($soft_model, $request, []);

    $select_count = 0;
    DB::connection()->listen(static function (QueryExecuted $query) use (&$select_count): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'crud_perf_activate')) {
            $select_count++;
        }
    });

    $result = $service->activate($modify);

    // Only the lookup select is expected; the discarded fresh() must not add a second one.
    expect($result->data)->toBeInstanceOf(Model::class)
        ->and($select_count)->toBe(1);
});

it('search applies the model rehydration authorization to the hits', function (): void {
    config()->set('scout.driver', 'elasticsearch');

    $superadmin = crud_cov_login_superadmin();
    $hidden = User::factory()->create([
        'username' => 'rehydration_hidden_' . uniqid(),
        'email' => 'rehydration_hidden_' . uniqid() . '@example.com',
    ]);

    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class), crud_cov_advanced_search_returning($hidden->getKey()));
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();
    Modules\Core\Tests\Stubs\Search\RehydrationGuardedUserStub::$engine = $engine;
    Modules\Core\Tests\Stubs\Search\RehydrationGuardedUserStub::$hiddenKey = $hidden->getKey();

    $request = crud_cov_validated_request(['qs' => 'needle']);
    $request->setUserResolver(fn () => $superadmin);
    $model = new Modules\Core\Tests\Stubs\Search\RehydrationGuardedUserStub();

    $data = crud_cov_make_request_data(SearchRequestData::class, $model, $request, $model->getKeyName());
    crud_cov_set($data, 'qs', 'needle');
    crud_cov_set($data, 'mode', SearchMode::Orchestrated);
    crud_cov_set($data, 'page', null);
    crud_cov_set($data, 'limit', 5);
    crud_cov_set($data, 'pagination', 5);
    crud_cov_set($data, 'from', null);
    crud_cov_set($data, 'to', null);
    crud_cov_set($data, 'count', false);

    expect($service->search($data)->data)->toHaveCount(0);
});
