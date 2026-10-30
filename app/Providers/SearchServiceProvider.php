<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use Elastic\ScoutDriverPlus\Engine as BaseElasticsearchEngine;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\DatabaseEngine as BaseDatabaseEngine;
use Laravel\Scout\Engines\TypesenseEngine as BaseTypesenseEngine;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Contracts\ISearchStrategyResolver;
use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\TypesenseEngine;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\CoreSearchStrategyResolver;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\HeuristicReranker;
use Modules\Core\Search\Services\NullVectorProfileProvider;
use Modules\Core\Search\Services\RetrievalTuningProfile;
use Modules\Core\Search\Services\SimpleQueryIntentParser;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Modules\Core\Search\Support\SearchDegradations;

/**
 * Extended search service provider.
 *
 * Adds support for vector search, ensemble retrieval, reranking,
 * and custom features on top of Laravel Scout.
 * Registers fallback implementations that the AI module can override.
 */
final class SearchServiceProvider extends ServiceProvider
{
    public array $bindings = [
        BaseDatabaseEngine::class => DatabaseEngine::class,
        BaseElasticsearchEngine::class => ElasticsearchEngine::class,
        BaseTypesenseEngine::class => TypesenseEngine::class,
    ];

    /**
     * Register services in the container.
     */
    public function register(): void
    {
        $this->app->make(EngineManager::class)->extend('database', static fn (Application $app): DatabaseEngine => $app->make(DatabaseEngine::class));

        $this->app->singleton(ISearchEngine::class, static fn (Application $app) => $app->make(EngineManager::class)->engine());

        $this->app->alias(ISearchEngine::class, 'search');

        $this->app->singletonIf(IReranker::class, HeuristicReranker::class);
        $this->app->singletonIf(IVectorProfileProvider::class, NullVectorProfileProvider::class);
        $this->app->singletonIf(IVectorSearchAvailability::class, VectorSearchAvailability::class);
        $this->app->singletonIf(ISearchPlanner::class, FallbackSearchPlanner::class);
        $this->app->singletonIf(IQueryIntentParser::class, SimpleQueryIntentParser::class);
        $this->app->singletonIf(ISearchStrategyResolver::class, CoreSearchStrategyResolver::class);
        $this->app->scoped(SearchDegradations::class);
        $this->app->singleton(RetrievalTuningProfile::class);
        $this->app->singleton(AdvancedSearchService::class);
    }
}
