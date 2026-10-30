<?php

declare(strict_types=1);

use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Tests\Support\FakeVectorProfile;

function pgvector_engine_query(): Illuminate\Database\Eloquent\Builder
{
    $method = new ReflectionMethod(DatabaseEngine::class, 'postgreSQLVectorSearchQuery');

    return $method->invoke(app(DatabaseEngine::class), [0.1, 0.2], new ModelEmbedding, [1, 2]);
}

it('casts the column and filters on the active model like the partial index', function (): void {
    FakeVectorProfile::use(new VectorProfile('prov:model-a', 384, 'cosine'));

    $query = pgvector_engine_query();

    expect($query->toSql())
        ->toContain('"embedding"::vector(384) <=> ?::vector')
        ->toContain('"model_key" = \'prov:model-a\'')
        ->not->toContain('"model_key" = ?')
        ->and($query->getBindings())->not->toContain('prov:model-a');
});

it('uses the operator of the profile similarity', function (string $similarity, string $operator): void {
    FakeVectorProfile::use(new VectorProfile('prov:model-a', 8, $similarity));

    expect(pgvector_engine_query()->toSql())->toContain("\"embedding\"::vector(8) {$operator} ?::vector");
})->with([['cosine', '<=>'], ['l2', '<->'], ['ip', '<#>']]);

it('does not filter on a model when the profile names none', function (): void {
    FakeVectorProfile::use(new VectorProfile('', 8, 'cosine'));

    expect(pgvector_engine_query()->toSql())->not->toContain('"model_key"')
        ->toContain('vector_dims("embedding") = 8');
});

it('escapes the model key literal', function (): void {
    FakeVectorProfile::use(new VectorProfile("a'b", 8, 'cosine'));

    expect(pgvector_engine_query()->toSql())->toContain("\"model_key\" = 'a''b'");
});

it('rejects non-positive dimensions before building SQL', function (int $dimensions): void {
    FakeVectorProfile::use(new VectorProfile('prov:model-a', $dimensions, 'cosine'));

    expect(fn () => pgvector_engine_query())->toThrow(InvalidArgumentException::class);
})->with([0, -3]);

it('rejects a query when there is no vector profile', function (): void {
    FakeVectorProfile::none();

    expect(fn () => pgvector_engine_query())->toThrow(InvalidArgumentException::class, 'vector profile');
});
