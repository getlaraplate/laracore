<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Widgets;

use Exception;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Searchable;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Services\ElasticsearchService;
use Override;
use Throwable;

final class SearchEngineHealthTableWidget extends Widget
{
    /**
     * Cache TTL in seconds. Shown in widget heading so users know data is refreshed about every this period.
     */
    private const int CACHE_TTL_SECONDS = 300;

    #[Override]
    protected string $view = 'core::filament.widgets.search-engine-health';

    #[Override]
    protected int|string|array $columnSpan = 'full';

    #[Override]
    protected static ?int $sort = 51;

    /**
     * Rendered only by the CacheHealth page, which lists it explicitly; kept out of
     * panel discovery so the dashboard does not pick it up.
     */
    #[Override]
    protected static bool $isDiscovered = false;

    protected function getViewData(): array
    {
        $cache_key = 'filament_search_engine_health_widget';
        $cache_ttl = self::CACHE_TTL_SECONDS;
        $cached = Cache::remember($cache_key, $cache_ttl, fn (): array => $this->fetchSearchEngineData());

        $cached['cache_minutes'] = (int) ceil($cache_ttl / 60);

        return $cached;
    }

    /**
     * @return array{driver: string|null, models: array<int, array>, error: string|null}
     */
    private function fetchSearchEngineData(): array
    {
        $data = [
            'driver' => null,
            'models' => [],
            'error' => null,
        ];

        try {
            $engine = resolve(EngineManager::class)->engine();
            $data['driver'] = config('scout.driver', 'unknown');

            $models = models(filter: static fn (string|object $model): bool => class_uses_trait($model, Searchable::class));
            $modelData = [];

            foreach ($models as $model) {
                $instance = new $model();
                $documents = 0;
                $index_exists = false;

                if ($engine instanceof ISearchEngine) {
                    try {
                        $index_exists = $engine->checkIndex($instance);
                    } catch (Throwable) {
                        $index_exists = false;
                    }

                    try {
                        $documents = $index_exists ? $this->countDocuments($engine, $instance) : 0;
                    } catch (Throwable) {
                        $documents = 0;
                    }
                }

                $modelData[] = [
                    'name' => class_basename($model),
                    'full_name' => $model,
                    'searchable_as' => $instance->searchableAs(),
                    'count' => $model::query()->count(),
                    'index_exists' => $index_exists,
                    'documents' => $documents,
                ];
            }

            $data['models'] = $modelData;
        } catch (Exception $exception) {
            $data['error'] = $exception->getMessage();
        }

        return $data;
    }

    /**
     * The number of documents in the model's index. Elasticsearch is asked through the
     * `_count` API, since a search reports at most 10,000 hits and its cluster stats
     * hold no per-index figures; other engines report the total of an empty search.
     */
    private function countDocuments(ISearchEngine $engine, Model $model): int
    {
        if (config('scout.driver') === 'elasticsearch') {
            $response = ElasticsearchService::getInstance()->client->count(['index' => $model->searchableAs()]);

            return (int) ($response->asArray()['count'] ?? 0);
        }

        return (int) $engine->getTotalCount($engine->search($model::search('')));
    }
}
