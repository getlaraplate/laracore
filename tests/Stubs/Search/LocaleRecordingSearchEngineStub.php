<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\NullEngine;
use Modules\Core\Search\Contracts\ILocaleFilterableEngine;
use Modules\Core\Search\Contracts\ISearchEngine;
use Override;

/**
 * An engine that filters by locale, as Elasticsearch does for an index that records `locales`, finds nothing, and
 * keeps every Scout builder it is asked to run, so a test reads the constraints each search path hands the engine.
 */
final class LocaleRecordingSearchEngineStub extends NullEngine implements ILocaleFilterableEngine, ISearchEngine
{
    /**
     * @var list<Builder<covariant Model>>
     */
    public array $builders = [];

    public function filtersByLocale(Model $model): bool
    {
        return true;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    #[Override]
    public function search(Builder $builder): mixed
    {
        $this->builders[] = $builder;

        return [];
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    #[Override]
    public function paginate(Builder $builder, $perPage, $page): mixed
    {
        $this->builders[] = $builder;

        return [];
    }

    public function offersVectorSearch(): bool
    {
        return false;
    }

    public function supportsOrchestratedSearch(): bool
    {
        return true;
    }

    public function supportsOrchestratedVectorSearch(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function textMatchCapabilities(): array
    {
        return [];
    }

    #[Override]
    public function createIndex(mixed $name, array $options = [], bool $force = false): void {}

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return [];
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'locale-recording';
    }

    public function sync(string $modelClass, ?int $id = null, ?string $from = null): int
    {
        return 0;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, mixed>
     */
    public function buildSearchFilters(array $filters): array|string
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSearchMapping(Model $model): array
    {
        return [];
    }

    public function prepareDataToEmbed(Model $model): ?string
    {
        return null;
    }

    public function reindex(string $modelClass): void {}

    public function ensureSearchable(Model $model): void {}

    public function checkIndex(string|Model $model): bool
    {
        return true;
    }

    public function ensureIndex(string|Model $model): bool
    {
        return true;
    }

    public function getLastIndexedTimestamp(Model $model): ?string
    {
        return null;
    }
}
