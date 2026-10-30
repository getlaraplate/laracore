<?php

declare(strict_types=1);

use Laravel\Scout\EngineManager;
use Modules\Core\Providers\SearchServiceProvider;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Contracts\ISearchStrategyResolver;
use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Services\AdvancedSearchService;
use Modules\Core\Search\Services\CoreSearchStrategyResolver;
use Modules\Core\Search\Services\NullVectorProfileProvider;
use Modules\Core\Search\Support\VectorProfileContext;

beforeEach(function (): void {
    $this->provider = new SearchServiceProvider(app());
});

it('registers ISearchEngine singleton and search alias', function (): void {
    $this->provider->register();

    expect(app()->bound(ISearchEngine::class))->toBeTrue();
    expect(app()->bound('search'))->toBeTrue();
});

it('registers advanced search coordinator', function (): void {
    $this->provider->register();

    expect(app()->bound(AdvancedSearchService::class))->toBeTrue();
});

it('binds the strategy resolver contract, to Core\'s cheap resolver unless a module replaces it', function (): void {
    expect(app(ISearchStrategyResolver::class))->toBeInstanceOf(ISearchStrategyResolver::class)
        ->and(app(CoreSearchStrategyResolver::class))->toBeInstanceOf(CoreSearchStrategyResolver::class);
});

it('binds the vector profile provider to the null provider, so there is no profile by default', function (): void {
    expect(app(IVectorProfileProvider::class))->toBeInstanceOf(NullVectorProfileProvider::class)
        ->and(VectorProfileContext::get())->toBeNull()
        ->and(VectorProfileContext::modelKey())->toBeNull();
});

it('binds Scout engine implementations', function (): void {
    expect($this->provider->bindings)->toHaveKey(Elastic\ScoutDriverPlus\Engine::class);
    expect($this->provider->bindings)->toHaveKey(Laravel\Scout\Engines\TypesenseEngine::class);
});

it('registers the Core database search engine implementation with Scout', function (): void {
    config()->set('scout.driver', 'database');

    $this->provider->register();
    app(EngineManager::class)->forgetEngines();

    expect(app(EngineManager::class)->engine())->toBeInstanceOf(DatabaseEngine::class);
});

it('is registered by the Core module so the search contracts resolve in the app', function (): void {
    expect(app()->getProvider(SearchServiceProvider::class))->not->toBeNull()
        ->and(app()->bound(ISearchEngine::class))->toBeTrue()
        ->and(app(AdvancedSearchService::class))->toBeInstanceOf(AdvancedSearchService::class);
});
