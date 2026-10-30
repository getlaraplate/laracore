<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Core\Search\Enums\QueryClass;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\RetrievalTuningProfile;
use Modules\Core\Tests\Support\FakeVectorProfile;
use Psr\Log\LoggerInterface;

/**
 * @param  array<string, mixed>|null  $profile
 */
function retrieval_tuning_config(bool $enabled, ?array $profile): Repository
{
    $config = new Repository([
        'core' => ['search' => ['adaptive_tuning' => $enabled, 'vector' => ['enabled' => true], 'reranker' => ['enabled' => true, 'top_k' => 30]]],
    ]);

    if ($profile !== null) {
        $config->set('search_tuning', $profile);
    }

    return $config;
}

/**
 * @return array<string, mixed>
 */
function retrieval_tuning_plan(): array
{
    $previous = config()->all();
    FakeVectorProfile::use();
    config()->set('core.search.reranker.enabled', true);
    config()->set('core.search.reranker.top_k', 30);
    $plan = (new FallbackSearchPlanner())->safePlan('fatture fornitori scadute del mese');
    config()->set($previous);

    return $plan;
}

/**
 * @return array<string, mixed>
 */
function retrieval_tuning_profile(): array
{
    return [
        'version' => 'test.1',
        'default' => ['rrf_k' => 60, 'rrf_weight' => 0.25, 'agreement_boost' => 0.15],
        'classes' => [
            'identifier' => ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0],
            'natural_language' => ['keyword_weight' => 0.25, 'vector_weight' => 0.45, 'hybrid_weight' => 0.30, 'rerank_top_k' => 20, 'rerank_blend' => 0.5],
        ],
    ];
}

it('returns the plan untouched when the switch is off', function (): void {
    $plan = retrieval_tuning_plan();
    $profile = new RetrievalTuningProfile(retrieval_tuning_config(false, retrieval_tuning_profile()), Mockery::mock(LoggerInterface::class));

    expect($profile->apply($plan, QueryClass::NaturalLanguage))->toBe($plan);
});

it('merges the class parameters into ensemble and ranking only, and records the tuning meta', function (): void {
    $plan = retrieval_tuning_plan();
    $profile = new RetrievalTuningProfile(retrieval_tuning_config(true, retrieval_tuning_profile()), Mockery::mock(LoggerInterface::class));

    $tuned = $profile->apply($plan, QueryClass::NaturalLanguage);

    expect($tuned['ensemble'])->toBe([...$plan['ensemble'], 'keyword_weight' => 0.25, 'vector_weight' => 0.45, 'hybrid_weight' => 0.30])
        ->and($tuned['ranking'])->toBe([...$plan['ranking'], 'rerank_top_k' => 20, 'rerank_blend' => 0.5])
        ->and($tuned['meta']['tuning'])->toBe(['applied' => true, 'profile_version' => 'test.1', 'query_class' => 'natural_language'])
        ->and($tuned['retrieval'])->toBe($plan['retrieval'])
        ->and(array_diff_key($tuned, array_flip(['ensemble', 'ranking', 'meta'])))
        ->toBe(array_diff_key($plan, array_flip(['ensemble', 'ranking', 'meta'])))
        ->and(array_diff_key($tuned['meta'], ['tuning' => true]))->toBe($plan['meta']);
});

it('falls back to the default entry for a class without its own entry', function (): void {
    $plan = retrieval_tuning_plan();
    $profile = new RetrievalTuningProfile(retrieval_tuning_config(true, retrieval_tuning_profile()), Mockery::mock(LoggerInterface::class));

    $tuned = $profile->apply($plan, QueryClass::MultiTerm);

    expect($tuned['ensemble'])->toBe($plan['ensemble'])
        ->and($tuned['ranking'])->toBe($plan['ranking'])
        ->and($tuned['meta']['tuning']['query_class'])->toBe('multi_term');
});

it('returns the plan untouched and warns once when the profile is invalid', function (array $invalid): void {
    $plan = retrieval_tuning_plan();
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();
    $profile = new RetrievalTuningProfile(retrieval_tuning_config(true, $invalid), $logger);

    expect($profile->apply($plan, QueryClass::NaturalLanguage))->toBe($plan)
        ->and($profile->apply($plan, QueryClass::Identifier))->toBe($plan);
})->with([
    'weight above one' => [['version' => 'bad', 'default' => [], 'classes' => ['natural_language' => ['keyword_weight' => 1.7]]]],
    'all weights zero' => [['version' => 'bad', 'default' => ['keyword_weight' => 0.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0], 'classes' => []]],
    'rrf_k below one' => [['version' => 'bad', 'default' => ['rrf_k' => 0], 'classes' => []]],
    'blend out of range' => [['version' => 'bad', 'default' => ['rerank_blend' => -0.1], 'classes' => []]],
    'unknown parameter' => [['version' => 'bad', 'default' => ['use_vector' => false], 'classes' => []]],
    'unknown class' => [['version' => 'bad', 'default' => [], 'classes' => ['questions' => []]]],
    'missing version' => [['default' => [], 'classes' => []]],
]);

it('returns the plan untouched and warns once when the profile config is missing', function (): void {
    $plan = retrieval_tuning_plan();
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();
    $profile = new RetrievalTuningProfile(retrieval_tuning_config(true, null), $logger);

    expect($profile->apply($plan, QueryClass::ShortKeyword))->toBe($plan)
        ->and($profile->apply($plan, QueryClass::ShortKeyword))->toBe($plan);
});

it('ships a profile that leaves the planner values unchanged', function (): void {
    $plan = retrieval_tuning_plan();
    $shipped = require dirname(__DIR__, 3) . '/config/search_tuning.php';
    $profile = new RetrievalTuningProfile(retrieval_tuning_config(true, $shipped), Mockery::mock(LoggerInterface::class));

    foreach (QueryClass::cases() as $class) {
        $tuned = $profile->apply($plan, $class);

        expect($tuned['meta']['tuning']['applied'])->toBeTrue()
            ->and(array_diff_key($tuned, ['meta' => true]))->toBe(array_diff_key($plan, ['meta' => true]));
    }
});
