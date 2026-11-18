<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Casts\Sort;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Tests\Stubs\Search\DefaultFilteredSearchStub;
use Modules\Core\Tests\Stubs\Search\ValidatedSearchRequestStub;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * The table of {@see DefaultFilteredSearchStub}, its rows, and a CRUD search of it in a given mode.
 */
final class DefaultFilteredSearchFixture
{
    public const string QUERY = 'needle';

    public static function createTable(): void
    {
        Schema::create(DefaultFilteredSearchStub::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('variant')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();
        });
    }

    public static function dropTable(): void
    {
        Schema::dropIfExists(DefaultFilteredSearchStub::TABLE);
    }

    /**
     * A row whose title matches {@see self::QUERY}, valid from yesterday unless told otherwise.
     */
    public static function row(string $label, ?string $variant = null, ?string $valid_to = null): int
    {
        return (int) DB::table(DefaultFilteredSearchStub::TABLE)->insertGetId([
            'title' => self::QUERY . ' ' . $label,
            'variant' => $variant,
            'valid_from' => now()->subDay(),
            'valid_to' => $valid_to,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function superadmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate((string) config('permission.roles.superadmin'), 'web'));

        return $user;
    }

    /**
     * The keys of the records a CRUD search of {@see self::QUERY} returns, in the order it returns them.
     *
     * @param  Model|null  $model  The searchable model; {@see DefaultFilteredSearchStub} when null
     * @return list<int>
     */
    public static function search(SearchMode $mode, int $limit, ?FiltersGroup $filters = null, ?User $user = null, ?Model $model = null): array
    {
        $result = self::run($mode, $limit, $filters, $user, $model);

        if ($result->error !== null) {
            throw new RuntimeException(sprintf('The %s search failed: %s', $mode->value, $result->error));
        }

        return array_values($result->data->map(static fn (Model $record): int => (int) $record->getKey())->all());
    }

    /**
     * A CRUD search of {@see self::QUERY}, its result as the service returns it, errors included.
     *
     * @param  Model|null  $model  The model searched; {@see DefaultFilteredSearchStub} when null
     * @param  list<Sort>  $sort
     */
    public static function run(SearchMode $mode, int $limit, ?FiltersGroup $filters = null, ?User $user = null, ?Model $model = null, array $sort = [], bool $with_extended = false): CrudResult
    {
        $data = self::requestData(SearchRequestData::class, $model ?? new DefaultFilteredSearchStub, $user, [
            'sort' => $sort,
            'filters' => $filters,
            'qs' => self::QUERY,
            'mode' => $mode,
            'limit' => $limit,
            'pagination' => $limit,
            'with_extended' => $with_extended,
        ]);

        return app(CrudService::class)->search($data);
    }

    /**
     * The request data of a CRUD read of the model by the user (a superadmin when null), signed in, with the given
     * properties over the defaults of a plain request.
     *
     * @template TData of ListRequestData
     *
     * @param  class-string<TData>  $class
     * @param  array<string, mixed>  $properties
     * @return TData
     */
    public static function requestData(string $class, Model $model, ?User $user = null, array $properties = []): ListRequestData
    {
        $user ??= self::superadmin();
        Auth::login($user);

        $request = new ValidatedSearchRequestStub(['qs' => self::QUERY]);
        $request->setUserResolver(static fn (): User => $user);

        $data = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        foreach ([
            'request' => $request,
            'mainEntity' => $model->getTable(),
            'primaryKey' => $model->getKeyName(),
            'model' => $model,
            'connection' => $model->getConnectionName(),
            'columns' => [],
            'relations' => [],
            'sort' => [],
            'filters' => null,
            'group_by' => [],
            'page' => null,
            'limit' => 100,
            'pagination' => 100,
            'from' => null,
            'to' => null,
            'count' => false,
            ...$properties,
        ] as $property => $value) {
            (new ReflectionProperty($data, $property))->setValue($data, $value);
        }

        return $data;
    }
}
