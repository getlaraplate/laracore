<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Cache\Repository as CacheRepository;
use Modules\Core\Casts\Column;
use Modules\Core\Casts\ColumnType;
use Modules\Core\Casts\SelectRequestData;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Tests\Fixtures\CrudServiceTestSingleRelChild;
use Modules\Core\Tests\Fixtures\CrudServiceTestSingleRelParent;
use Modules\Core\Tests\Stubs\ClosureTreeStubModel;

it('normalizes scalar and array key values to where condition', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $model = new class extends Model
    {
        protected $table = 'items';

        protected $primaryKey = 'id';
    };

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('keyValueToWhereCondition');
    $method->setAccessible(true);

    $single = $method->invoke($service, $model, 10);
    $composite = $method->invoke($service, $model, ['tenant_id' => 1, 'id' => 10]);

    expect($single)->toBe(['id' => 10])
        ->and($composite)->toBe(['tenant_id' => 1, 'id' => 10]);
});

it('detects models using recursive, approval, and history traits', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $recursive = new ClosureTreeStubModel;

    $approvable = new class extends Model
    {
        use HasApprovals;
    };

    $versioned = new class extends Model implements IVersionedModel
    {
        use HasVersions;
    };

    $ref = new ReflectionClass(CrudService::class);

    $useRecursive = $ref->getMethod('useRecursiveRelationships');
    $useRecursive->setAccessible(true);
    $useApproval = $ref->getMethod('useHasApproval');
    $useApproval->setAccessible(true);
    $hasHistory = $ref->getMethod('hasHistory');
    $hasHistory->setAccessible(true);

    expect($useRecursive->invoke($service, $recursive))->toBeTrue()
        ->and($useRecursive->invoke($service, $approvable))->toBeFalse()
        ->and($useApproval->invoke($service, $approvable))->toBeTrue()
        ->and($useApproval->invoke($service, $recursive))->toBeFalse()
        ->and($hasHistory->invoke($service, $versioned))->toBeTrue()
        ->and($hasHistory->invoke($service, $recursive))->toBeFalse();
});

it('clearModelCache clears cache for the given model and returns message', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $model = new class extends Model
    {
        protected $table = 'items';
    };

    Cache::shouldReceive('store')
        ->once()
        ->andReturn($repository = Mockery::mock(CacheRepository::class));

    $repository->shouldReceive('clearByEntity')
        ->once()
        ->with($model);

    $requestData = new class($model) extends Modules\Core\Casts\CrudRequestData
    {
        public function __construct(Model $model)
        {
            $this->model = $model;
        }
    };

    $result = $service->clearModelCache($requestData);

    expect($result->data)->toBe('items cached cleared');
});

it('extracts method columns grouped by relation and without duplicates', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $request_data = (new ReflectionClass(SelectRequestData::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($request_data, 'model'))->setValue($request_data, new Modules\Core\Models\User());
    (new ReflectionProperty($request_data, 'columns'))->setValue($request_data, [
        new Column('users.full_name', ColumnType::Method),
        new Column('users.roles.isEditable', ColumnType::Method),
        new Column('users.roles.isEditable', ColumnType::Method),
    ]);

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('extractMethodColumns');
    $method->setAccessible(true);

    $methods_by_relation = $method->invoke($service, $request_data);

    expect($methods_by_relation)->toBe([
        '' => ['full_name'],
        'roles' => ['isEditable'],
    ]);
});

it('applies method columns to relation path targets', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $child_a = new class extends Model
    {
        public function exposeCode(): string
        {
            return 'A';
        }
    };
    $child_b = new class extends Model
    {
        public function exposeCode(): string
        {
            return 'B';
        }
    };

    $parent = new class extends Model
    {
        public function children(): mixed
        {
            return null;
        }
    };
    $parent->setRelation('children', new Collection([$child_a, $child_b]));

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('applyMethodsToModel');
    $method->setAccessible(true);
    $method->invoke($service, $parent, ['children' => ['exposeCode']]);

    expect($child_a->getAttribute('exposeCode'))->toBe('A');
    expect($child_b->getAttribute('exposeCode'))->toBe('B');
});

it('returns early from relation path when nested segment is missing', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $mid = new class extends Model {};

    $parent = new class extends Model {};
    $parent->setRelation('mid', $mid);

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('applyMethodsToRelationPath');
    $method->setAccessible(true);
    $method->invoke($service, $parent, 'mid.child', ['anything']);

    expect($mid->getAttribute('anything'))->toBeNull();
});

it('applies method columns when relation resolves to a single model', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $child = new CrudServiceTestSingleRelChild;
    $parent = new CrudServiceTestSingleRelParent;
    $parent->setRelation('childRecord', $child);

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('applyMethodsToModel');
    $method->setAccessible(true);
    $method->invoke($service, $parent, ['childRecord' => ['childLabel']]);

    expect($child->getAttribute('childLabel'))->toBe('single');
});

it('applyComputedMethods iterates collection models when method columns requested', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $row = new class extends Model
    {
        protected $table = 'crud_cov_method_rows';

        public function rowLabel(): string
        {
            return 'L';
        }
    };

    $request_data = (new ReflectionClass(SelectRequestData::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($request_data, 'model'))->setValue($request_data, $row);
    (new ReflectionProperty($request_data, 'columns'))->setValue($request_data, [
        new Column('crud_cov_method_rows.rowLabel', ColumnType::Method),
    ]);

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('applyComputedMethods');
    $method->setAccessible(true);
    $method->invoke($service, new Collection([$row]), $request_data);

    expect($row->getAttribute('rowLabel'))->toBe('L');
});

it('throws when resolveMethodValue targets missing or invalid methods', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $model = new class extends Model
    {
        public function requiresArgument(string $value): string
        {
            return $value;
        }
    };

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('resolveMethodValue');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($service, $model, 'missingMethod'))
        ->toThrow(UnexpectedValueException::class);
    expect(fn () => $method->invoke($service, $model, 'requiresArgument'))
        ->toThrow(UnexpectedValueException::class);
});

it('resolveMethodValue keeps parameter checks per class for same-named methods', function (): void {
    $service = new CrudService(app(AuthorizationService::class), app(QueryBuilder::class));

    $zero_param = new class extends Model
    {
        public function label(): string
        {
            return 'zero';
        }
    };

    $requires_param = new class extends Model
    {
        public function label(string $value): string
        {
            return $value;
        }
    };

    $ref = new ReflectionClass(CrudService::class);
    $method = $ref->getMethod('resolveMethodValue');
    $method->setAccessible(true);

    // Resolve the zero-parameter variant first so any memoization is seeded with
    // the "no required parameters" signature before the same method name is
    // resolved on a different class that does require a parameter.
    expect($method->invoke($service, $zero_param, 'label'))->toBe('zero');
    expect(fn () => $method->invoke($service, $requires_param, 'label'))
        ->toThrow(UnexpectedValueException::class);
});
