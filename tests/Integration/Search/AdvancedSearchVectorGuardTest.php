<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\DTOs\SearchStrategy;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Enums\SearchMode;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\HeuristicReranker;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;
use Modules\Core\Tests\Stubs\Search\SuspendedVectorSearchAvailabilityStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardOrchestratedEngineStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardPlannerStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Cache::flush();
    // This file exercises Core's guard, whichever module bound the interface.
    app()->instance(IVectorSearchAvailability::class, new VectorSearchAvailability);
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));
    VectorGuardStubModel::$engine = VectorGuardOrchestratedEngineStub::make(384);
});

afterEach(function (): void {
    VectorGuardStubModel::$engine = null;
});

function guarded_search(?array &$seen_vector): AdvancedSearchResult
{
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->once()->andReturnUsing(
        function (...$args) use (&$seen_vector): AdvancedSearchResult {
            $seen_vector = $args[3];

            return AdvancedSearchResult::empty(1, 10, ['strategies_executed' => 1]);
        },
    );

    $service = new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new VectorGuardPlannerStub(), intent_parser: new SimpleQueryIntentParser(), embedder: app()->bound(ITextEmbedder::class) ? app(ITextEmbedder::class) : null),
        $ensemble,
        app(),
    );

    return $service->search(new VectorGuardStubModel(), 'semantic query', 1, 10);
}

it('falls back to keywords with the reason of a decorating guard and never embeds', function (): void {
    app()->instance(IVectorSearchAvailability::class, new SuspendedVectorSearchAvailabilityStub);
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldNotReceive('embed');
    app()->instance(ITextEmbedder::class, $embedder);

    $seen = ['sentinel'];
    $result = guarded_search($seen);

    expect($seen)->toBeNull()
        ->and($result->meta['vector_disabled'])->toBe('suspended')
        ->and($result->meta['strategies_executed'])->toBe(1);
});

it('falls back to keywords with the dimension_mismatch reason', function (): void {
    VectorGuardStubModel::$engine = VectorGuardOrchestratedEngineStub::make(768);
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldNotReceive('embed');
    app()->instance(ITextEmbedder::class, $embedder);

    $seen = ['sentinel'];
    $result = guarded_search($seen);

    expect($seen)->toBeNull()->and($result->meta['vector_disabled'])->toBe('dimension_mismatch');
});

it('embeds the query and adds no meta when the guard says yes', function (): void {
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->once()->andReturn([0.1, 0.2]);
    app()->instance(ITextEmbedder::class, $embedder);

    $seen = null;
    $result = guarded_search($seen);

    expect($seen)->toBe([0.1, 0.2])->and($result->meta)->not->toHaveKey('vector_disabled');
});

it('keeps the keyword results when the embedder throws', function (): void {
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->once()->andThrow(new RuntimeException('embedding service down'));
    app()->instance(ITextEmbedder::class, $embedder);

    $seen = ['sentinel'];
    $result = guarded_search($seen);

    expect($seen)->toBeNull()->and($result->meta['strategies_executed'])->toBe(1);
});

it('reports embedder_failed as the degraded reason when the embedder throws', function (): void {
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->once()->andThrow(new RuntimeException('embedding service down'));
    app()->instance(ITextEmbedder::class, $embedder);

    $seen = ['sentinel'];
    $result = guarded_search($seen);

    expect($result->meta['search']['degraded_reason'])->toBe('embedder_failed')
        ->and($result->meta['search']['mode_applied'])->toBe('fast');
});

it('leaves the degraded reason empty when nothing fell back', function (): void {
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->once()->andReturn([0.1, 0.2]);
    app()->instance(ITextEmbedder::class, $embedder);

    $seen = null;

    expect(guarded_search($seen)->meta['search']['degraded_reason'])->toBeNull();
});

/**
 * Runs a deep search whose first plan keeps vectors off (the query has digits) and returns the plan of the retry.
 *
 * @return array<string, mixed>
 */
function relaxed_retry_plan(?ITextEmbedder $embedder): array
{
    $plans = [];
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturnUsing(
        function (Model $model, string $query, array $plan) use (&$plans): AdvancedSearchResult {
            $plans[] = $plan;

            return new AdvancedSearchResult([['id' => 'doc-1', 'score' => 3.0, 'source' => []]], 1, 1, 10, 1, ['strategies_executed' => 1]);
        },
    );

    $deep = new SearchStrategy(
        applied_mode: SearchMode::Deep,
        planner: new FallbackSearchPlanner,
        reranker: new HeuristicReranker,
        intent_parser: new SimpleQueryIntentParser,
        embedder: $embedder,
        max_retries: 1,
    );

    (new AdvancedSearchService(new FixedSearchStrategyResolver(deep: $deep), $ensemble, app()))
        ->search(new VectorGuardStubModel(), 'order 12345', 1, 10, mode: SearchMode::Deep, retries: 1);

    expect($plans)->toHaveCount(2)->and($plans[0]['retrieval']['use_vector'])->toBeFalse();

    return $plans[1];
}

function stub_text_embedder(): ITextEmbedder
{
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);

    return $embedder;
}

it('switches vector retrieval on in a retry when there is a profile and an embedder', function (): void {
    $plan = relaxed_retry_plan(stub_text_embedder());

    expect($plan['retrieval']['use_vector'])->toBeTrue()->and($plan['vector']['enabled'])->toBeTrue();
});

it('keeps vector retrieval off in a retry when there is no profile', function (): void {
    FakeVectorProfile::none();

    expect(relaxed_retry_plan(stub_text_embedder())['retrieval']['use_vector'])->toBeFalse();
});

it('keeps vector retrieval off in a retry when the strategy has no embedder', function (): void {
    expect(relaxed_retry_plan(null)['retrieval']['use_vector'])->toBeFalse();
});
