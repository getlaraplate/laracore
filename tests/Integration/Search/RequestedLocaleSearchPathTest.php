<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Casts\SearchMode;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Role;
use Modules\Core\Search\Support\ScoutWheres;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Tests\Stubs\Search\AclRehydrationUserStub;
use Modules\Core\Tests\Stubs\Search\LocaleRecordingSearchEngineStub;
use Modules\Core\Tests\Stubs\Search\ValidatedSearchRequestStub;
use Modules\Core\Tests\Support\FakeVectorProfile;

/*
 * The requested locale is an eligibility constraint: a search returns only the documents available in the language
 * of the locale context. Every search path hands it to an engine that filters by locale, the basic path as well as
 * the orchestrated one, so no path returns a document in another language that another path keeps out.
 */

beforeEach(function (): void {
    FakeVectorProfile::none();
    config()->set('permission.roles.superadmin', 'superadmin');
});

afterEach(function (): void {
    AclRehydrationUserStub::$engine = null;
    LocaleContext::set((string) config('app.locale'));
});

/**
 * Runs a CRUD search of the stub users in the given mode, as a superadmin, against the engine.
 */
function requested_locale_search(SearchMode $mode, LocaleRecordingSearchEngineStub $engine): void
{
    AclRehydrationUserStub::$engine = $engine;

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('superadmin', 'web'));
    Auth::login($user);

    $model = new AclRehydrationUserStub;
    $request = new ValidatedSearchRequestStub(['qs' => 'festival']);
    $request->setUserResolver(static fn (): User => $user);

    $data = (new ReflectionClass(SearchRequestData::class))->newInstanceWithoutConstructor();

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
        'qs' => 'festival',
        'mode' => $mode,
        'page' => null,
        'limit' => 5,
        'pagination' => 5,
        'from' => null,
        'to' => null,
        'count' => false,
    ] as $property => $value) {
        (new ReflectionProperty($data, $property))->setValue($data, $value);
    }

    $result = app(CrudService::class)->search($data);

    expect($result->error)->toBeNull();
}

it('restricts every search path to the requested locale at the engine', function (SearchMode $mode, string $locale): void {
    LocaleContext::set($locale);
    $engine = new LocaleRecordingSearchEngineStub;

    requested_locale_search($mode, $engine);

    expect($engine->builders)->not->toBeEmpty();

    foreach ($engine->builders as $builder) {
        expect(ScoutWheres::map($builder))->toMatchArray(['locales' => [$locale]]);
    }
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto])->with(['it', 'en']);

it('hands the engine the requested locale once per search', function (SearchMode $mode): void {
    LocaleContext::set('it');
    $engine = new LocaleRecordingSearchEngineStub;

    requested_locale_search($mode, $engine);

    foreach ($engine->builders as $builder) {
        $locale_wheres = array_filter(
            $builder->wheres,
            static fn (mixed $where, int|string $key): bool => $key === 'locales' || (is_array($where) && ($where['field'] ?? null) === 'locales'),
            ARRAY_FILTER_USE_BOTH,
        );

        expect($locale_wheres)->toHaveCount(1);
    }
})->with([SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]);
