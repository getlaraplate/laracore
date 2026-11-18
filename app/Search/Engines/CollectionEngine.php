<?php

declare(strict_types=1);

namespace Modules\Core\Search\Engines;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\CollectionEngine as BaseCollectionEngine;
use Override;

/**
 * Scout's collection engine, the engine of the test suites, with the two behaviours this application's database
 * engine has and Scout's collection engine lacks:
 *
 * - the base query is the model's `newScoutQuery()` when it declares one, as the database engine reads it;
 * - the builder's query callback runs, which carries what the constraint applier pushes as advanced filters (OR
 *   groups, ranges, relation and `extension` conditions) and the opening of a host to its extended rows. Scout's
 *   collection engine ignores it, so those filters held only at hydration, if at all.
 *
 * The rest is Scout 11's own `searchModels()`: wheres, whereIns, whereNotIns, order, soft deletes, and the match of
 * the query string against the searchable array.
 */
final class CollectionEngine extends BaseCollectionEngine
{
    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $builder
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    #[Override]
    protected function searchModels(Builder $builder)
    {
        $query = method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->query();

        $query->when($builder->callback !== null, static function ($query) use ($builder): void {
            call_user_func($builder->callback, $query, $builder, $builder->query);
        })->when($builder->callback === null && $builder->wheres !== [], static function ($query) use ($builder): void {
            foreach ($builder->wheres as $where) {
                if ($where['field'] !== '__soft_deleted') {
                    $query->where($where['field'], $where['operator'], $where['value']);
                }
            }
        })->when($builder->callback === null && $builder->whereIns !== [], static function ($query) use ($builder): void {
            foreach ($builder->whereIns as $key => $values) {
                $query->whereIn($key, $values);
            }
        })->when($builder->callback === null && $builder->whereNotIns !== [], static function ($query) use ($builder): void {
            foreach ($builder->whereNotIns as $key => $values) {
                $query->whereNotIn($key, $values);
            }
        })->when($builder->queryCallback !== null, static function ($query) use ($builder): void {
            call_user_func($builder->queryCallback, $query);
        })->when($builder->orders !== [], static function ($query) use ($builder): void {
            foreach ($builder->orders as $order) {
                $query->orderBy($order['column'], $order['direction']);
            }
        }, static function ($query) use ($builder): void {
            $query->orderBy($builder->model->qualifyColumn($builder->model->getScoutKeyName()), 'desc');
        });

        $models = $this->ensureSoftDeletesAreHandled($builder, $query)
            ->get()
            ->values();

        if (count($models) === 0) {
            return $models;
        }

        return $models->first()->makeSearchableUsing($models)->filter(static function ($model) use ($builder): bool {
            if (! $model->shouldBeSearchable()) {
                return false;
            }

            if ($builder->query === null || $builder->query === '') {
                return true;
            }

            foreach ($model->toSearchableArray() as $value) {
                if (! is_scalar($value)) {
                    $value = json_encode($value);
                }

                if (Str::contains(Str::lower((string) $value), Str::lower($builder->query))) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
