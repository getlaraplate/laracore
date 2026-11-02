<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;
use Modules\Core\Tests\Stubs\Search\VectorGuardOrchestratedEngineStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardPlannerStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

/**
 * Request words ("trovami", "articoli su") say what the reader wants, not what the documents contain: the
 * keyword search drops them, the vector search embeds the whole question. The lists are the shipped ones.
 */
beforeEach(function (): void {
    Cache::flush();
    app()->instance(IVectorSearchAvailability::class, new VectorSearchAvailability);
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));
    VectorGuardStubModel::$engine = VectorGuardOrchestratedEngineStub::make(384);
});

afterEach(function (): void {
    VectorGuardStubModel::$engine = null;
});

/**
 * Runs a search and returns the keyword queries the ensemble got and the texts the embedder got.
 *
 * @return array{keyword: list<string>, embedded: list<string>}
 */
function request_words_search(string $query, string $locale): array
{
    LocaleContext::set($locale);
    $seen = ['keyword' => [], 'embedded' => []];

    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->andReturnUsing(function (string $text) use (&$seen): array {
        $seen['embedded'][] = $text;

        return [0.1, 0.2];
    });

    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturnUsing(function (...$args) use (&$seen): AdvancedSearchResult {
        $seen['keyword'][] = $args[1];

        return AdvancedSearchResult::empty(1, 10, ['strategies_executed' => 1]);
    });

    $service = new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new VectorGuardPlannerStub(), intent_parser: new SimpleQueryIntentParser(), embedder: $embedder),
        $ensemble,
        app(),
    );
    $service->search(new VectorGuardStubModel(), $query, 1, 10);

    return $seen;
}

it('request words are dropped from the keyword terms and kept in the vector query', function (): void {
    $italian = request_words_search('Trovami articoli su Mario Rossi', 'it');
    $english = request_words_search('Show me news about the harbour', 'en');

    expect($italian['keyword'])->toBe(['Mario Rossi'])
        ->and($italian['embedded'])->toBe(['Trovami articoli su Mario Rossi'])
        ->and($english['keyword'])->toBe(['the harbour'])
        ->and($english['embedded'])->toBe(['Show me news about the harbour']);
});

it('keeps the whole query for the keyword search when it is made only of request words', function (): void {
    $seen = request_words_search('mostrami i documenti', 'it');

    expect($seen['keyword'])->toBe(['mostrami i documenti'])
        ->and($seen['embedded'])->toBe(['mostrami i documenti']);
});
