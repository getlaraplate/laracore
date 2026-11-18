<?php

declare(strict_types=1);

namespace Modules\Core\Services\Crud\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Cache\Labels\CacheScope;
use Modules\Core\Casts\HistoryRequestData;
use Modules\Core\Casts\SelectRequestData;
use Modules\Core\Casts\TreeRequestData;
use Modules\Core\Contracts\IDeclaresCacheDependencies;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Support\RelationGuard;
use SplObjectStorage;

/**
 * The labels of a cached CRUD read: what its response depends on.
 *
 * Before the query, {@see forRequest()} reads the request plan:
 *
 * - `crud` and the scope of the caller (its user and roles) always;
 * - the main table: a set label for a read of many rows, a row table for a read of one record (its row label is
 *   known after the query);
 * - each segment of each relation path the request names, by kind: a `belongsTo` loaded for display is a row table,
 *   its records labelled by row after the query, because its foreign key sits on the row that embeds it; one used
 *   only to choose rows (filter, sort, group by, aggregate) is a set label, since any change of the related rows can
 *   change which rows match. Every other relation is a set label of its related table: `hasOne` and `hasMany`
 *   (their foreign key sits on the related row), many-to-many with its pivot, through relations with the table they
 *   go through. A segment after a to-many one keeps its own kind;
 * - a `morphTo` segment names no table before the query: the walk stops there and the dependencies are incomplete;
 * - the translations table of every translated model reached, and the tables every reached model declares through
 *   {@see IDeclaresCacheDependencies};
 * - the relations the engine loads on its own: the versions (and pending modifications) of a history read, the
 *   nodes of a tree read, which are rows of the main table.
 *
 * After the query, {@see rowLabels()} gives the row labels of the records the response embeds.
 */
final readonly class CrudCacheDependencyResolver
{
    public function __construct(private QueryBuilder $query_builder) {}

    /**
     * @param  bool  $single_record  True for a read of one record (detail, tree or history of a key), whose main table
     *                               is a row dependency; false for a read of many rows, whose main table is a set label.
     */
    public function forRequest(Model $model, SelectRequestData $request_data, ?User $user, bool $single_record): CacheDependencies
    {
        $labels = [CacheLabel::all(), ...$this->scopeLabels($user)];
        $row_tables = [];
        $complete = true;

        if ($single_record) {
            $row_tables[] = $model->getTable();
        } else {
            $labels[] = CacheLabel::table($model->getTable());
        }

        array_push($labels, ...$this->modelLabels($model));

        $paths = $this->query_builder->requestedRelationPathsByUse($model, $request_data);

        foreach ($paths['display'] as $path) {
            $this->walk($model, $path, true, $labels, $row_tables, $complete);
        }

        foreach ($paths['membership'] as $path) {
            $this->walk($model, $path, false, $labels, $row_tables, $complete);
        }

        foreach ($this->engineRelations($model, $request_data) as $name) {
            $this->walk($model, $name, false, $labels, $row_tables, $complete);
        }

        if ($request_data instanceof TreeRequestData && ($request_data->parents || $request_data->children)) {
            $labels[] = CacheLabel::table($model->getTable());
        }

        return new CacheDependencies(array_values(array_unique($labels)), array_values(array_unique($row_tables)), $complete);
    }

    /**
     * The row labels of the records a computed response embeds: the main records when the dependencies hold the main
     * table as a row table (a read of one record), and, at any depth, the record of every loaded `belongsTo` and
     * `morphTo` relation, also below a loaded to-many relation.
     *
     * A to-one relation loaded empty still names the row its foreign key points at, when that key is the related
     * primary key: the response changes when that row appears, is restored or becomes visible.
     *
     * @param  mixed  $data  A model, a collection or paginator of models, or arrays of them.
     * @return list<string>
     */
    public function rowLabels(Model $model, CacheDependencies $dependencies, mixed $data): array
    {
        $labels = [];

        /** @var SplObjectStorage<Model, null> $visited */
        $visited = new SplObjectStorage();

        /** @var array<string, Relation<Model, Model, mixed>|null> $relations */
        $relations = [];
        $main_table = $model->getTable();
        $main_by_row = in_array($main_table, $dependencies->row_tables, true);

        foreach (self::modelsIn($data) as $record) {
            if ($main_by_row && $main_table === $record->getTable()) {
                $label = self::rowLabelOf($record);

                if ($label !== null) {
                    $labels[] = $label;
                }
            }

            $this->collectRelationRowLabels($record, $labels, $visited, $relations);
        }

        return array_values(array_unique($labels));
    }

    /**
     * The row label of the record a to-one relation loaded empty points at, or null when its foreign key is empty or
     * points at a column other than the related primary key.
     *
     * @param  BelongsTo<Model, Model>  $relation
     */
    private static function pointedRowLabel(Model $record, BelongsTo $relation): ?string
    {
        $related = $relation->getRelated();

        if ($relation instanceof MorphTo) {
            $type = $record->getAttribute($relation->getMorphType());
            $related = is_string($type) ? RelationGuard::morphModel($type) : null;

            if (! $related instanceof Model) {
                return null;
            }
        }

        $owner_key = $relation->getOwnerKeyName();

        if ($owner_key !== null && $owner_key !== $related->getKeyName()) {
            return null;
        }

        $id = $record->getAttribute($relation->getForeignKeyName());

        return is_int($id) || (is_string($id) && $id !== '') ? CacheLabel::row($related->getTable(), $id) : null;
    }

    private static function rowLabelOf(Model $record): ?string
    {
        $id = $record->getKey();

        return is_int($id) || (is_string($id) && $id !== '') ? CacheLabel::row($record->getTable(), $id) : null;
    }

    /**
     * Every model in a result: the model itself, or the models of a collection, paginator or array, at any depth.
     *
     * @return iterable<Model>
     */
    private static function modelsIn(mixed $data): iterable
    {
        if ($data instanceof Model) {
            yield $data;

            return;
        }

        if (! is_iterable($data)) {
            return;
        }

        foreach ($data as $item) {
            yield from self::modelsIn($item);
        }
    }

    /**
     * @return list<string>
     */
    private function scopeLabels(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $key = $user->getKey();
        $labels = [CacheLabel::user(is_int($key) || is_string($key) ? $key : '0')];

        foreach (CacheScope::roleKeys($user) as $role_key) {
            $labels[] = CacheLabel::role($role_key);
        }

        return $labels;
    }

    /**
     * The set labels a read of the model brings wherever it is reached: its translations and its declared tables.
     *
     * @return list<string>
     */
    private function modelLabels(Model $model): array
    {
        $labels = [];

        if (class_uses_trait($model, HasTranslations::class)) {
            $translations = RelationGuard::relationOf($model, 'translations');

            if ($translations instanceof Relation) {
                $labels[] = CacheLabel::table($translations->getRelated()->getTable());
            }
        }

        if ($model instanceof IDeclaresCacheDependencies) {
            foreach ($model->cacheDependencyTables() as $table) {
                $labels[] = CacheLabel::table($table);
            }
        }

        return $labels;
    }

    /**
     * Label each segment of a relation path, as described on the class.
     *
     * @param  list<string>  $labels
     * @param  list<string>  $row_tables
     */
    private function walk(Model $model, string $path, bool $displayed, array &$labels, array &$row_tables, bool &$complete): void
    {
        $current = $model;

        foreach (explode('.', $path) as $name) {
            $relation = RelationGuard::relationOf($current, $name);

            if (! $relation instanceof Relation || $relation instanceof MorphTo) {
                $complete = false;

                return;
            }

            $related = $relation->getRelated();

            if ($relation instanceof BelongsTo) {
                if ($displayed) {
                    $row_tables[] = $related->getTable();
                } else {
                    $labels[] = CacheLabel::table($related->getTable());
                }
            } else {
                $labels[] = CacheLabel::table($related->getTable());

                if ($relation instanceof BelongsToMany) {
                    $labels[] = CacheLabel::table($relation->getTable());
                } elseif ($relation instanceof HasOneOrManyThrough) {
                    $labels[] = CacheLabel::table($relation->getParent()->getTable());
                }
            }

            array_push($labels, ...$this->modelLabels($related));
            $current = $related;
        }
    }

    /**
     * The relations the engine loads on its own for the read, beside those the request names.
     *
     * @return list<string>
     */
    private function engineRelations(Model $model, SelectRequestData $request_data): array
    {
        if (! $request_data instanceof HistoryRequestData) {
            return [];
        }

        return array_values(array_filter(
            ['history', 'modifications'],
            static fn (string $name): bool => RelationGuard::relationOf($model, $name) instanceof Relation,
        ));
    }

    /**
     * @param  list<string>  $labels
     * @param  SplObjectStorage<Model, null>  $visited
     * @param  array<string, Relation<Model, Model, mixed>|null>  $relations  Relation instances by class and name, built once.
     */
    private function collectRelationRowLabels(Model $record, array &$labels, SplObjectStorage $visited, array &$relations): void
    {
        if ($visited->contains($record)) {
            return;
        }

        $visited->attach($record);

        foreach ($record->getRelations() as $name => $loaded) {
            $name = (string) $name;
            $cache_key = $record::class . '::' . $name;

            if (! array_key_exists($cache_key, $relations)) {
                $relations[$cache_key] = RelationGuard::relationOf($record, $name);
            }

            $relation = $relations[$cache_key];

            if ($relation instanceof BelongsTo) {
                $label = $loaded instanceof Model ? self::rowLabelOf($loaded) : self::pointedRowLabel($record, $relation);

                if ($label !== null) {
                    $labels[] = $label;
                }
            }

            foreach (self::modelsIn($loaded) as $child) {
                $this->collectRelationRowLabels($child, $labels, $visited, $relations);
            }
        }
    }
}
