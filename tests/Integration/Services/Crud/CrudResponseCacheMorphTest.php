<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheLabelIndexFactory;
use Modules\Core\Cache\Repository;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\SelectRequestData;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Http\Requests\DetailRequest;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Models\Role;
use Modules\Core\Models\Version;
use Modules\Core\Services\Crud\Cache\CrudResponseCache;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Tests\Stubs\Cache\CacheMorphStubModel;
use Modules\Core\Tests\Stubs\Cache\RedisLabelIndexFactory;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Symfony\Component\HttpFoundation\Response;

/*
 * A response that displays a `morphTo` names its target tables only after the query. On an index that checks every
 * label it receives at the store (Redis, the array index) it is cached with the labels of the targets it loaded; on a
 * version counter index, whose key is fixed at the lookup, it is never stored.
 */

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(TranslatedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('code')->nullable();
        $table->unsignedInteger('lock_version')->default(1);
        $table->timestamps();
    });

    Schema::create(TranslatedDocumentTranslation::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('translated_document_id')->constrained(TranslatedDocument::TABLE)->cascadeOnDelete();
        $table->string('locale');
        $table->string('title')->nullable();
        $table->string('body')->nullable();
        $table->timestamps();
    });

    Schema::create(CacheMorphStubModel::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->nullableMorphs('owner');
        $table->timestamps();
    });

    HelpersCache::setModels('active', array_values(array_unique([...models(), CacheMorphStubModel::class])));

    $this->document = TranslatedDocument::query()->create(['code' => 'doc']);
    $this->document->setTranslation('en', ['title' => 'Title', 'body' => 'Body']);
    $this->record = CacheMorphStubModel::query()->create(['owner_type' => TranslatedDocument::class, 'owner_id' => $this->document->getKey()]);
    $this->computed = 0;
});

afterEach(function (): void {
    HelpersCache::clearModels();
    Schema::dropIfExists(CacheMorphStubModel::TABLE);
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
    RedisLabelIndexFactory::cleanup();
});

/**
 * The stores of CRUD responses, leaving out anything else the index keeps.
 *
 * @return list<array{lookup: Modules\Core\Cache\Labels\CacheLookup, value: mixed, ttl: int, row_labels: list<string>, stored: bool}>
 */
function crudMorphCacheStores(SpyLabelIndex $index): array
{
    return array_values(array_filter($index->stores, static fn (array $store): bool => str_starts_with($store['lookup']->key, 'crud:')));
}

/**
 * Reads the request through the response cache, counting the computes in `$this->computed`.
 *
 * @param  Closure(): mixed  $load
 */
function crudMorphCacheRead(SelectRequestData $request_data, Closure $load): Response
{
    return app(CrudResponseCache::class)->remember(
        $request_data,
        static function () use ($load): CrudResult {
            test()->computed++;

            return new CrudResult(data: $load());
        },
        static fn (CrudResult $result): Response => new JsonResponse(['data' => $result->data]),
        static fn (): null => null,
    );
}

function crudMorphCacheDetail(int $id): DetailRequestData
{
    return new DetailRequestData(
        DetailRequest::create('/app/crud/detail/core/CacheMorphStubModel', 'GET', ['id' => $id]),
        'CacheMorphStubModel',
        ['relations' => ['owner']],
        'id',
    );
}

function crudMorphCacheReadDetail(): Response
{
    $id = (int) test()->record->getKey();

    return crudMorphCacheRead(crudMorphCacheDetail($id), static fn (): CacheMorphStubModel => CacheMorphStubModel::query()->with('owner')->findOrFail($id));
}

/**
 * Detail reads of the record and writes of its target, with the computes counted after each read.
 *
 * @return list<int>
 */
function crudMorphCacheScenario(): array
{
    $counts = [];

    crudMorphCacheReadDetail();
    crudMorphCacheReadDetail();
    $counts[] = test()->computed;

    test()->document->forceFill(['code' => 'changed'])->save();
    crudMorphCacheReadDetail();
    crudMorphCacheReadDetail();
    $counts[] = test()->computed;

    $translation = TranslatedDocumentTranslation::query()->where('translated_document_id', test()->document->getKey())->firstOrFail();
    $translation->forceFill(['title' => 'Changed title'])->save();
    crudMorphCacheReadDetail();
    $counts[] = test()->computed;

    return $counts;
}

it('serves a cached detail that displays a morphTo until its target or a translation of it changes', function (): void {
    $spy = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $spy);

    expect(crudMorphCacheScenario())->toBe([1, 2, 3])
        ->and(crudMorphCacheStores($spy)[0]['row_labels'])->toContain(
            CacheLabel::row(CacheMorphStubModel::TABLE, $this->record->getKey()),
            CacheLabel::row(TranslatedDocument::TABLE, $this->document->getKey()),
            CacheLabel::table(TranslatedDocumentTranslation::TABLE),
        );
});

it('serves a cached detail that displays a morphTo from Redis until its target or a translation of it changes', function (): void {
    app()->instance(ICacheLabelIndex::class, RedisLabelIndexFactory::make());

    expect(crudMorphCacheScenario())->toBe([1, 2, 3]);
})->group('redis');

it('stores nothing for a detail that displays a morphTo on a version counter index', function (): void {
    $directory = storage_path('framework/testing/crud-morph-cache-' . uniqid());
    $spy = new SpyLabelIndex(CacheLabelIndexFactory::forStore(new Repository(new FileStore(new Filesystem(), $directory))));
    app()->instance(ICacheLabelIndex::class, $spy);

    try {
        crudMorphCacheReadDetail();
        crudMorphCacheReadDetail();
    } finally {
        (new Filesystem())->deleteDirectory($directory);
    }

    expect($this->computed)->toBe(2)
        ->and(crudMorphCacheStores($spy))->toBe([]);
});

it('labels a list filtered through a morphTo with the table of every target type the main table holds', function (): void {
    $spy = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $spy);
    $role = Role::factory()->create();
    CacheMorphStubModel::query()->create(['owner_type' => $role::class, 'owner_id' => $role->getKey()]);
    $request_data = new ListRequestData(
        ListRequest::create('/app/crud/select/core/CacheMorphStubModel', 'GET'),
        'CacheMorphStubModel',
        ['filters' => [['property' => 'owner.id', 'value' => $this->document->getKey(), 'operator' => '=']]],
        'id',
    );
    $load = static fn (): mixed => CacheMorphStubModel::query()->whereHasMorph('owner', '*', static fn (Builder $query): Builder => $query->whereKey(test()->document->getKey()))->get();

    crudMorphCacheRead($request_data, $load);
    crudMorphCacheRead($request_data, $load);
    TranslatedDocument::query()->create(['code' => 'another']);
    crudMorphCacheRead($request_data, $load);

    expect($this->computed)->toBe(2)
        ->and(crudMorphCacheStores($spy)[0]['row_labels'])->toContain(CacheLabel::table(TranslatedDocument::TABLE), CacheLabel::table($role->getTable()));
});
