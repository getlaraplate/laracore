<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Search;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Search\Support\ScoutWheres;

/**
 * Returns a fixed ranking per strategy: no vector means keyword, a vector with the `*` query
 * means vector, a vector with a text query means hybrid. For a {@see FusionFixtureTranslatedSearchModel}
 * the hits carry no title.
 */
final class FusionFixtureSearchBuilder extends ScoutBuilder
{
    /**
     * @var array<string, list<array{id: int, _score: float, title: string}>>
     */
    public const array RANKINGS = [
        'keyword' => [
            ['id' => 1, '_score' => 12.5, 'title' => 'invoice approval workflow'],
            ['id' => 2, '_score' => 9.0, 'title' => 'supplier invoice list'],
            ['id' => 3, '_score' => 4.25, 'title' => 'payment reminders'],
            ['id' => 4, '_score' => 1.0, 'title' => 'archived invoices'],
        ],
        'vector' => [
            ['id' => 3, '_score' => 0.91, 'title' => 'payment reminders'],
            ['id' => 5, '_score' => 0.88, 'title' => 'overdue supplier payments'],
            ['id' => 2, '_score' => 0.74, 'title' => 'supplier invoice list'],
            ['id' => 6, '_score' => 0.52, 'title' => 'vendor onboarding'],
        ],
        'hybrid' => [
            ['id' => 2, '_score' => 7.4, 'title' => 'supplier invoice list'],
            ['id' => 3, '_score' => 6.9, 'title' => 'payment reminders'],
            ['id' => 1, '_score' => 5.1, 'title' => 'invoice approval workflow'],
            ['id' => 5, '_score' => 2.2, 'title' => 'overdue supplier payments'],
        ],
    ];

    public function paginate($perPage = null, $pageName = 'page', $page = null): LengthAwarePaginator
    {
        $items = collect(self::RANKINGS[$this->strategy()])
            ->map(fn (array $attributes): FusionFixtureSearchModel => FusionFixtureSearchModel::hit(
                $this->model instanceof FusionFixtureTranslatedSearchModel ? Arr::except($attributes, 'title') : $attributes,
            ));

        return new LengthAwarePaginator($items, $items->count(), (int) $perPage, (int) ($page ?? 1));
    }

    private function strategy(): string
    {
        // Through ScoutWheres: Scout 11 stores where() constraints as a list of field/operator/value
        // triples, so array_key_exists('vector', $this->wheres) is always false and every strategy would
        // read as keyword.
        if (! array_key_exists('vector', ScoutWheres::map($this))) {
            return 'keyword';
        }

        return $this->query === '*' ? 'vector' : 'hybrid';
    }
}
