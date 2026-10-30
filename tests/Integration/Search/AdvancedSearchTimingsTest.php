<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Modules\Core\Tests\Stubs\Search\EngineBoundStubModel;
use Modules\Core\Tests\Stubs\Search\FixedSearchStrategyResolver;
use Modules\Core\Tests\Stubs\Search\SuspendedVectorSearchAvailabilityStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardOrchestratedEngineStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardPlannerStub;
use Modules\Core\Tests\Stubs\Search\VectorGuardStubModel;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    Cache::flush();
    app()->instance(IVectorSearchAvailability::class, new VectorSearchAvailability);
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));
    config()->set('core.search.debug_timings', false);
    VectorGuardStubModel::$engine = VectorGuardOrchestratedEngineStub::make(384);
});

afterEach(function (): void {
    VectorGuardStubModel::$engine = null;
});

/**
 * @return array<string, mixed>
 */
function timed_search_ensemble_meta(): array
{
    return ['driver' => 'typesense', 'strategies_executed' => 3, 'reranked' => false];
}

/**
 * @return array<string, mixed>
 */
function timed_search_mode_meta(): array
{
    return ['mode_requested' => 'fast', 'mode_applied' => 'fast', 'degraded_reason' => null, 'retries_used' => 0];
}

function timed_search(?IQueryIntentParser $intent_parser = null): AdvancedSearchResult
{
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturn(new AdvancedSearchResult(
        hits: [['id' => '7', 'score' => 0.9, 'source' => ['title' => 'Alpha']]],
        total: 1,
        page: 1,
        perPage: 10,
        totalPages: 1,
        meta: timed_search_ensemble_meta(),
    ));

    $service = new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new VectorGuardPlannerStub(), intent_parser: $intent_parser ?? new SimpleQueryIntentParser(), embedder: app()->bound(ITextEmbedder::class) ? app(ITextEmbedder::class) : null),
        $ensemble,
        app(),
    );

    return $service->search(new VectorGuardStubModel(), 'semantic query', 1, 10);
}

function bind_embedder(): void
{
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2]);
    app()->instance(ITextEmbedder::class, $embedder);
}

it('seeds the debug timings switch off in the search group', function (): void {
    $definition = collect(CoreDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name')->get('search.debug_timings');

    expect($definition)->not->toBeNull()
        ->and($definition['value'])->toBeFalse()
        ->and($definition['type'])->toBe(SettingTypeEnum::Boolean)
        ->and($definition['group_name'])->toBe('search');
});

it('leaves the meta exactly as the ensemble returned it when the flag is off', function (): void {
    bind_embedder();

    expect(timed_search()->meta)->toBe([...timed_search_ensemble_meta(), 'search' => timed_search_mode_meta()]);
});

it('leaves the vector_disabled meta unchanged when the flag is off', function (): void {
    app()->instance(IVectorSearchAvailability::class, new SuspendedVectorSearchAvailabilityStub);
    bind_embedder();

    expect(timed_search()->meta)->toBe([...timed_search_ensemble_meta(), 'vector_disabled' => 'suspended', 'search' => timed_search_mode_meta()]);
});

it('adds the four stage timings and the total in milliseconds when the flag is on', function (): void {
    config()->set('core.search.debug_timings', true);
    bind_embedder();

    $timings = timed_search()->meta['timings'];

    expect(array_keys($timings))->toBe(['intent_ms', 'plan_ms', 'vector_ms', 'ensemble_ms', 'total_ms']);

    foreach ($timings as $milliseconds) {
        expect($milliseconds)->toBeFloat()->toBeGreaterThanOrEqual(0.0);
    }

    expect($timings['total_ms'])->toBeGreaterThanOrEqual($timings['intent_ms'] + $timings['plan_ms'] + $timings['vector_ms'] + $timings['ensemble_ms'] - 0.01);
});

it('reports the vector stage as null when no embedder is bound', function (): void {
    config()->set('core.search.debug_timings', true);
    app()->offsetUnset(ITextEmbedder::class);

    $timings = timed_search()->meta['timings'];

    expect($timings['vector_ms'])->toBeNull()
        ->and($timings['intent_ms'])->toBeFloat()
        ->and($timings['plan_ms'])->toBeFloat()
        ->and($timings['ensemble_ms'])->toBeFloat();
});

it('reports the vector stage as null when the guard refuses vectors', function (): void {
    config()->set('core.search.debug_timings', true);
    app()->instance(IVectorSearchAvailability::class, new SuspendedVectorSearchAvailabilityStub);
    bind_embedder();

    $meta = timed_search()->meta;

    expect($meta['vector_disabled'])->toBe('suspended')
        ->and($meta['timings']['vector_ms'])->toBeNull();
});

it('does not alter the result when the flag is on', function (): void {
    bind_embedder();
    $plain = timed_search();

    config()->set('core.search.debug_timings', true);
    $timed = timed_search();
    $meta_without_timings = $timed->meta;
    unset($meta_without_timings['timings']);

    expect($timed->hits)->toBe($plain->hits)
        ->and($timed->total)->toBe($plain->total)
        ->and($timed->page)->toBe($plain->page)
        ->and($timed->perPage)->toBe($plain->perPage)
        ->and($timed->totalPages)->toBe($plain->totalPages)
        ->and($meta_without_timings)->toBe($plain->meta);
});

it('reports every stage as null on the unsupported driver path when the flag is on', function (): void {
    config()->set('core.search.debug_timings', true);
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('supportsOrchestratedSearch')->andReturnFalse();

    $service = new AdvancedSearchService(
        new FixedSearchStrategyResolver(planner: new VectorGuardPlannerStub(), intent_parser: new SimpleQueryIntentParser(), embedder: app()->bound(ITextEmbedder::class) ? app(ITextEmbedder::class) : null),
        Mockery::mock(EnsembleSearchService::class), app());
    $meta = $service->search(new EngineBoundStubModel($engine), 'query', 1, 10)->meta;

    expect($meta['unsupported_driver'])->toBeTrue()
        ->and($meta['timings']['intent_ms'])->toBeNull()
        ->and($meta['timings']['plan_ms'])->toBeNull()
        ->and($meta['timings']['vector_ms'])->toBeNull()
        ->and($meta['timings']['ensemble_ms'])->toBeNull()
        ->and($meta['timings']['total_ms'])->toBeFloat();
});

it('logs the timings measured so far when the embedding fails, and still answers with the keyword results', function (): void {
    config()->set('core.search.debug_timings', true);
    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->andThrow(new RuntimeException('embedding service down'));
    app()->instance(ITextEmbedder::class, $embedder);
    Log::spy();

    $result = timed_search();

    expect($result->total)->toBe(1);

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
        return $context['failed_stage'] === 'vector'
            && is_float($context['timings']['intent_ms'])
            && is_float($context['timings']['plan_ms'])
            && is_float($context['timings']['vector_ms'])
            && $context['timings']['ensemble_ms'] === null;
    });
});
