<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Tests\Support\FakeVectorProfile;

beforeEach(function (): void {
    $container = Container::getInstance();

    if (! $container->bound('config')) {
        $container->singleton('config', fn (): Repository => new Repository([]));
    }
});

it('implements ISearchPlanner contract', function (): void {
    expect(new FallbackSearchPlanner)->toBeInstanceOf(ISearchPlanner::class);
});

it('safePlan delegates to fallbackPlan', function (): void {
    $planner = new FallbackSearchPlanner;
    expect($planner->safePlan('test query'))->toBe($planner->fallbackPlan('test query'));
});

it('returns a valid plan structure', function (): void {
    $planner = new FallbackSearchPlanner;
    $plan = $planner->fallbackPlan('test query');

    expect($plan)->toHaveKeys(['strategy', 'retrieval', 'ensemble', 'ranking', 'vector', 'filters', 'retry_policy', 'meta']);
    expect($plan['strategy'])->toBeIn(['hybrid', 'fulltext']);
    expect($plan['meta']['source'])->toBe('fallback_rules');
});

it('disables vector when there is no vector profile', function (): void {
    FakeVectorProfile::none();

    $planner = new FallbackSearchPlanner;
    $plan = $planner->fallbackPlan('test query');

    expect($plan['retrieval']['use_vector'])->toBeFalse();
    expect($plan['vector']['enabled'])->toBeFalse();
    expect($plan['strategy'])->toBe('fulltext');
    expect($plan['ensemble']['vector_weight'])->toBe(0.0);
    expect($plan['ensemble']['keyword_weight'])->toBe(1.0);
});

it('enables vector when there is a vector profile and no numbers', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));

    $planner = new FallbackSearchPlanner;
    $plan = $planner->fallbackPlan('test query');

    expect($plan['retrieval']['use_vector'])->toBeTrue();
    expect($plan['vector']['enabled'])->toBeTrue();
    expect($plan['strategy'])->toBe('hybrid');
});

it('disables vector for queries with numbers even with a vector profile', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));

    $planner = new FallbackSearchPlanner;
    $plan = $planner->fallbackPlan('order 12345');

    expect($plan['retrieval']['use_vector'])->toBeFalse();
    expect($plan['vector']['enabled'])->toBeFalse();
});

it('uses larger size for short queries', function (): void {
    $planner = new FallbackSearchPlanner;
    $short_plan = $planner->fallbackPlan('test');
    $long_plan = $planner->fallbackPlan('this is a much longer query for searching');

    expect($short_plan['retrieval']['size'])->toBe(80);
    expect($long_plan['retrieval']['size'])->toBe(50);
});

it('reads reranker config from search config', function (): void {
    config()->set('core.search.reranker.enabled', true);
    config()->set('core.search.reranker.top_k', 50);

    $planner = new FallbackSearchPlanner;
    $plan = $planner->fallbackPlan('test');

    expect($plan['ranking']['use_reranker'])->toBeTrue();
    expect($plan['ranking']['rerank_top_k'])->toBe(50);
});

it('leaves the reranker off by default under the shipped config', function (): void {
    $planner = new FallbackSearchPlanner;
    $plan = $planner->fallbackPlan('test');

    expect($plan['ranking']['use_reranker'])->toBeFalse();
});

it('honours the reranker being explicitly disabled', function (): void {
    config()->set('core.search.reranker.enabled', false);

    $planner = new FallbackSearchPlanner;
    $plan = $planner->fallbackPlan('test');

    expect($plan['ranking']['use_reranker'])->toBeFalse();
});
