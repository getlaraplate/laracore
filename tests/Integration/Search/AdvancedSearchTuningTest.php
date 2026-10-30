<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\RetrievalTuningProfile;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Tests\Stubs\Search\EngineBoundStubModel;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;
use Modules\Core\Tests\Stubs\Search\FusionFixtureSearchModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

/**
 * Runs one advanced search and returns the plan the ensemble received.
 *
 * @return array<string, mixed>
 */
function advanced_search_tuning_captured_plan(string $query): array
{
    $captured = null;
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')
        ->once()
        ->withArgs(function (Model $model, string $passed_query, array $plan) use (&$captured): bool {
            $captured = $plan;

            return true;
        })
        ->andReturn(AdvancedSearchResult::empty(1, 10));

    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnTrue();
    $engine->shouldReceive('supportsOrchestratedVectorSearch')->andReturnTrue();

    $service = new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new FallbackSearchPlanner(), intent_parser: new SimpleQueryIntentParser()),
        $ensemble, app());
    $service->search(new EngineBoundStubModel($engine), $query, 1, 10);

    return $captured;
}

beforeEach(function (): void {
    FakeVectorProfile::use();
    config()->set('search_tuning', [
        'version' => 'test.2',
        'default' => ['rrf_k' => 40],
        'classes' => [
            'identifier' => ['keyword_weight' => 0.9, 'vector_weight' => 0.0, 'hybrid_weight' => 0.1],
            'natural_language' => ['keyword_weight' => 0.2, 'vector_weight' => 0.5, 'hybrid_weight' => 0.3, 'rerank_blend' => 0.5],
        ],
    ]);
    app()->forgetInstance(RetrievalTuningProfile::class);
});

it('hands the ensemble exactly the fallback planner plan when tuning is off', function (string $query): void {
    config()->set('core.search.adaptive_tuning', false);

    $expected = (new FallbackSearchPlanner())->safePlan($query);
    $expected['intent'] = (new SimpleQueryIntentParser())->parse($query);
    $expected['retrieval']['size'] = 10;

    expect(advanced_search_tuning_captured_plan($query))->toBe($expected);
})->with([
    'identifier' => ['INV-1042 fattura'],
    'short keyword' => ['Mario Rossi'],
    'natural language' => ['come faccio ad annullare una fattura già inviata'],
]);

it('applies the profile for the query class when tuning is on', function (): void {
    config()->set('core.search.adaptive_tuning', true);

    $plan = advanced_search_tuning_captured_plan('come faccio ad annullare una fattura già inviata');

    expect($plan['ensemble'])->toMatchArray(['keyword_weight' => 0.2, 'vector_weight' => 0.5, 'hybrid_weight' => 0.3, 'rrf_k' => 40])
        ->and($plan['ranking']['rerank_blend'])->toBe(0.5)
        ->and($plan['retrieval']['use_vector'])->toBeTrue()
        ->and($plan['meta']['tuning'])->toBe(['applied' => true, 'profile_version' => 'test.2', 'query_class' => 'natural_language']);
});

it('classifies an identifier query and leaves strategy selection to the planner', function (): void {
    config()->set('core.search.adaptive_tuning', true);

    $plan = advanced_search_tuning_captured_plan('INV-1042 fattura');

    expect($plan['meta']['tuning']['query_class'])->toBe('identifier')
        ->and($plan['ensemble'])->toMatchArray(['keyword_weight' => 0.9, 'vector_weight' => 0.0, 'hybrid_weight' => 0.1])
        ->and($plan['retrieval']['use_vector'])->toBeFalse()
        ->and($plan['retrieval']['use_fulltext'])->toBeTrue();
});

it('reports the tuning decision on the search result', function (): void {
    $service = new EnsembleSearchService(Mockery::mock(IReranker::class));
    $plan = [
        'retrieval' => ['use_fulltext' => true, 'use_vector' => false],
        'ensemble' => [],
        'ranking' => ['use_reranker' => false],
    ];
    $tuning = ['applied' => true, 'profile_version' => 'test.2', 'query_class' => 'multi_term'];

    $tuned = $service->search(new FusionFixtureSearchModel(), 'invoices', [...$plan, 'meta' => ['tuning' => $tuning]], null, 1, 5);
    $untuned = $service->search(new FusionFixtureSearchModel(), 'invoices', $plan, null, 1, 5);

    expect($tuned->meta['tuning'])->toBe($tuning)
        ->and($untuned->meta)->not->toHaveKey('tuning');
});
