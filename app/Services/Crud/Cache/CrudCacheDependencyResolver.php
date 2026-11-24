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
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Contracts\IDeclaresCacheDependencies;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\QueryBuilder;
use Modules\Core\Support\RelationGuard;
use SplObjectStorage;
use WeakMap;

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
 * - a `morphTo` segment names no table before the query. On an index that checks at the store every label it receives,
 *   also those found after the query ({@see ICacheLabelIndex::supportsRowLabels()}), its labels are added after the
 *   query: for a displayed `morphTo` the row label of each loaded target and what reaching its class brings, for one
 *   used only to choose rows the table of every type the column holds. Otherwise, and for a `morphTo` that does not
 *   end its path, the walk stops there and the dependencies are incomplete;
 * - the translations table of every translated model reached, the tables every reached model declares through
 *   {@see IDeclaresCacheDependencies}, its default eager loads (`$with`), walked as displayed paths, and its default
 *   relation counts (`$withCount`), walked as membership paths: a count depends on which related rows exist;
 * - the relations the engine loads on its own: the versions (and pending modifications) of a history read, the
 *   nodes of a tree read, which are rows of the main table;
 * - a history read needs no row label after the query: see {@see forRequest()}.
 *
 * After the query, {@see labelsAfterQuery()} gives the row labels of the records the response embeds and the labels of
 * the `morphTo` targets.
 *
 * @phpstan-type DependencyState array{labels: list<string>, row_tables: list<string>, complete: bool, rows_after_query: bool, labels_after_query: bool, morph_types: list<array{0: Model, 1: string}>, expanded: array<string, true>}
 */
final readonly class CrudCacheDependencyResolver
{
    /**
     * Relations the engine loads on its own for a history read, by name, with the default eager loads of the related
     * model that the engine leaves out (a version's `versionable` is the main record itself).
     *
     * @var array<string, list<string>>
     */
    private const array HISTORY_RELATIONS = [
        'history' => ['versionable'],
        'modifications' => [],
    ];

    /**
     * The `morphTo` relations used only to choose rows of each request this instance resolved, by the dependencies it
     * answered, for {@see labelsAfterQuery()}.
     *
     * @var WeakMap<CacheDependencies, list<array{0: Model, 1: string}>>
     */
    private WeakMap $morph_types;

    public function __construct(private QueryBuilder $query_builder)
    {
        $this->morph_types = new WeakMap();
    }

    /**
     * A history read is recognised by its request class ({@see HistoryRequestData}): its payload is serialized inside
     * the service, so {@see labelsAfterQuery()} finds no model in it. Its dependencies are therefore complete before
     * the query: the main record by its row label, taken from the key the request names, and every displayed to-one
     * relation by the set label of its table instead of a row table.
     *
     * @param  bool  $single_record  True for a read of one record (detail, tree or history of a key), whose main table
     *                               is a row dependency; false for a read of many rows, whose main table is a set label.
     * @param  list<string>|null  $role_keys  The caller's role keys as {@see CacheScope::roleKeys()} builds them, when
     *                                        the caller already has them; computed from the user when null.
     * @param  bool  $labels_after_query  True when the index checks at the store every label it receives, also those
     *                                    {@see labelsAfterQuery()} finds ({@see ICacheLabelIndex::supportsRowLabels()}):
     *                                    a `morphTo` then keeps the dependencies complete.
     */
    public function forRequest(Model $model, SelectRequestData $request_data, ?User $user, bool $single_record, ?array $role_keys = null, bool $labels_after_query = false): CacheDependencies
    {
        $state = $this->stateFor($model, $request_data, $this->scopeLabels($user, $role_keys), $single_record, $labels_after_query);
        $dependencies = new CacheDependencies(array_values(array_unique($state['labels'])), array_values(array_unique($state['row_tables'])), $state['complete']);
        $this->morph_types->offsetSet($dependencies, $state['morph_types']);

        return $dependencies;
    }

    /**
     * The labels of a computed response known only after its query, to store it with: the row labels of the records it
     * embeds, what reaching the class of every loaded `morphTo` target brings (its translations, declared tables,
     * default eager loads and counts), and, for a `morphTo` used only to choose rows, the table of every type its column
     * holds, read now: a type written later is a write of that table, whose label the response carries, so a store
     * racing it is refused. Null when the response cannot be stored: a target class whose labels are incomplete. Only
     * an index that checks every label at the store may receive the labels of the targets (see `$labels_after_query`
     * of {@see forRequest()}).
     *
     * @param  mixed  $data  A model, a collection or paginator of models, or arrays of them.
     * @return list<string>|null
     */
    public function labelsAfterQuery(Model $model, SelectRequestData $request_data, CacheDependencies $dependencies, mixed $data): ?array
    {
        $targets = [];
        $labels = $this->collectRowLabels($model, $dependencies, $data, $targets);

        // Dependencies another instance answered: the request is walked again to find its morph relations.
        $request_morph_types = $this->morph_types->offsetExists($dependencies)
            ? $this->morph_types->offsetGet($dependencies)
            : $this->stateFor($model, $request_data, [], in_array($model->getTable(), $dependencies->row_tables, true), true)['morph_types'];
        $state = self::emptyState(true, true);

        foreach ($targets as $target) {
            $this->reach($target, $state, []);
        }

        if (! $state['complete']) {
            return null;
        }

        foreach ([...$request_morph_types, ...$state['morph_types']] as [$owner, $type_column]) {
            foreach ($this->morphTargetsIn($owner, $type_column) as $target) {
                $labels[] = CacheLabel::table($target->getTable());
                array_push($labels, ...$this->modelLabels($target));
            }
        }

        return array_values(array_unique([
            ...$labels,
            ...$state['labels'],
            ...array_map(CacheLabel::rows(...), $state['row_tables']),
        ]));
    }

    /**
     * A state with nothing labelled yet.
     *
     * @return DependencyState
     */
    private static function emptyState(bool $rows_after_query, bool $labels_after_query): array
    {
        return [
            'labels' => [],
            'row_tables' => [],
            'complete' => true,
            'rows_after_query' => $rows_after_query,
            'labels_after_query' => $labels_after_query,
            'morph_types' => [],
            'expanded' => [],
        ];
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
     * The row label of the record a single-record read names by its key, known before the query; the set label of the
     * main table when the key is composite or missing.
     */
    private static function requestedRecordLabel(Model $model, SelectRequestData $request_data): string
    {
        $key_name = $request_data->primaryKey;

        if (is_string($key_name)) {
            $id = $request_data->request->input($key_name) ?? $request_data->request->route($key_name);

            if (is_int($id) || (is_string($id) && $id !== '')) {
                return CacheLabel::row($model->getTable(), $id);
            }
        }

        return CacheLabel::table($model->getTable());
    }

    /**
     * The relations a model counts on every query (`$withCount`), by name: an aliased count (`items as total`) and a
     * constrained one (`'items' => fn`) name the relation `items`. Eloquent exposes the list to no public reader.
     *
     * @return list<string>
     */
    private static function defaultCountedRelations(Model $model): array
    {
        /** @var array<int|string, mixed> $counts */
        $counts = (fn (): array => $this->withCount)->call($model);
        $names = [];

        foreach ($counts as $key => $value) {
            $entry = is_string($key) ? $key : $value;

            if (! is_string($entry)) {
                continue;
            }

            $names[] = mb_trim((string) preg_replace('/\s+as\s+.*$/i', '', $entry));
        }

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /**
     * The state of a request walked as described on the class.
     *
     * @param  list<string>  $scope_labels
     * @return DependencyState
     */
    private function stateFor(Model $model, SelectRequestData $request_data, array $scope_labels, bool $single_record, bool $labels_after_query): array
    {
        $is_history = $request_data instanceof HistoryRequestData;

        $state = self::emptyState(! $is_history, $labels_after_query);
        $state['labels'] = [CacheLabel::all(), ...$scope_labels];

        if ($single_record) {
            $state['row_tables'][] = $model->getTable();

            if ($is_history) {
                $state['labels'][] = self::requestedRecordLabel($model, $request_data);
            }
        } else {
            $state['labels'][] = CacheLabel::table($model->getTable());
        }

        $this->reach($model, $state, []);

        $paths = $this->query_builder->requestedRelationPathsByUse($model, $request_data);

        foreach ($paths['display'] as $path) {
            $this->walk($model, $path, true, $state);
        }

        foreach ($paths['membership'] as $path) {
            $this->walk($model, $path, false, $state);
        }

        if ($is_history) {
            foreach (self::HISTORY_RELATIONS as $name => $left_out) {
                if (RelationGuard::relationOf($model, $name) instanceof Relation) {
                    $this->walk($model, $name, false, $state, $left_out);
                }
            }
        }

        if ($request_data instanceof TreeRequestData && ($request_data->parents || $request_data->children)) {
            $state['labels'][] = CacheLabel::table($model->getTable());
        }

        return $state;
    }

    /**
     * The row labels of the records a computed response embeds: the main records when the dependencies hold the main
     * table as a row table (a read of one record), and, at any depth, the record of every loaded `belongsTo` and
     * `morphTo` relation, also below a loaded to-many relation. The models loaded through a `morphTo` are collected
     * in `$targets`, one per class.
     *
     * A to-one relation loaded empty still names the row its foreign key points at, when that key is the related
     * primary key: the response changes when that row appears, is restored or becomes visible.
     *
     * @param  mixed  $data  A model, a collection or paginator of models, or arrays of them.
     * @param  array<class-string<Model>, Model>  $targets
     * @return list<string>
     */
    private function collectRowLabels(Model $model, CacheDependencies $dependencies, mixed $data, array &$targets): array
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

            $this->collectRelationRowLabels($record, $labels, $visited, $relations, $targets);
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  list<string>|null  $role_keys
     * @return list<string>
     */
    private function scopeLabels(?User $user, ?array $role_keys): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $key = $user->getKey();
        $labels = [CacheLabel::user(is_int($key) || is_string($key) ? $key : '0')];

        foreach ($role_keys ?? CacheScope::roleKeys($user) as $role_key) {
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
     * Label each segment of a relation path, as described on the class, and reach each related model.
     *
     * @param  DependencyState  $state
     * @param  list<string>  $left_out_at_end  Default eager loads of the last related model the engine leaves out.
     */
    private function walk(Model $model, string $path, bool $displayed, array &$state, array $left_out_at_end = []): void
    {
        $current = $model;
        $names = explode('.', $path);
        $last = count($names) - 1;

        foreach ($names as $index => $name) {
            $relation = RelationGuard::relationOf($current, $name);

            if (! $relation instanceof Relation) {
                $state['complete'] = false;

                return;
            }

            if ($relation instanceof MorphTo) {
                $this->walkMorph($current, $relation, $index === $last, $displayed, $state);

                return;
            }

            $related = $relation->getRelated();

            if ($relation instanceof BelongsTo) {
                if ($displayed && $state['rows_after_query']) {
                    $state['row_tables'][] = $related->getTable();
                } else {
                    $state['labels'][] = CacheLabel::table($related->getTable());
                }
            } else {
                $state['labels'][] = CacheLabel::table($related->getTable());

                if ($relation instanceof BelongsToMany) {
                    $state['labels'][] = CacheLabel::table($relation->getTable());
                } elseif ($relation instanceof HasOneOrManyThrough) {
                    $state['labels'][] = CacheLabel::table($relation->getParent()->getTable());
                }
            }

            $this->reach($related, $state, $index === $last ? $left_out_at_end : []);
            $current = $related;
        }
    }

    /**
     * A `morphTo` segment: its targets are known only after the query. A displayed one is labelled from the models it
     * loaded ({@see labelsAfterQuery()}), when rows are labelled after the query; one that only chooses rows is
     * recorded, so the types its column holds are read after the query. Anything else, a segment after it included,
     * leaves the dependencies incomplete.
     *
     * @param  MorphTo<Model, Model>  $relation
     * @param  DependencyState  $state
     */
    private function walkMorph(Model $owner, MorphTo $relation, bool $ends_path, bool $displayed, array &$state): void
    {
        if (! $ends_path || ! $state['labels_after_query'] || ($displayed && ! $state['rows_after_query'])) {
            $state['complete'] = false;

            return;
        }

        if (! $displayed) {
            $state['morph_types'][] = [$owner, $relation->getMorphType()];
        }
    }

    /**
     * The models of the types a morph type column of the owner's table holds, read without scopes: a type the scopes
     * hide only adds a label.
     *
     * @return list<Model>
     */
    private function morphTargetsIn(Model $owner, string $type_column): array
    {
        $targets = [];

        foreach ($owner->newModelQuery()->distinct()->pluck($type_column) as $type) {
            $target = is_string($type) ? RelationGuard::morphModel($type) : null;

            if ($target instanceof Model) {
                $targets[$target::class] = $target;
            }
        }

        return array_values($targets);
    }

    /**
     * A model the read loads or joins: its own labels, then its default eager loads (`$with`) walked as displayed
     * paths and its default relation counts (`$withCount`) walked as membership paths, both applied wherever the model
     * is queried. Each model class is expanded once per read, which also stops a cycle of defaults.
     *
     * @param  DependencyState  $state
     * @param  list<string>  $left_out  Default eager loads the engine removes from this model's query.
     */
    private function reach(Model $model, array &$state, array $left_out): void
    {
        array_push($state['labels'], ...$this->modelLabels($model));

        $expansion = $model::class . '|' . implode(',', $left_out);

        if (isset($state['expanded'][$expansion])) {
            return;
        }

        $state['expanded'][$expansion] = true;

        foreach (array_keys($model->newQueryWithoutScopes()->getEagerLoads()) as $path) {
            $path = (string) $path;

            if (in_array(explode('.', $path)[0], $left_out, true)) {
                continue;
            }

            $this->walk($model, $path, true, $state);
        }

        foreach (self::defaultCountedRelations($model) as $name) {
            $this->walk($model, $name, false, $state);
        }
    }

    /**
     * @param  list<string>  $labels
     * @param  SplObjectStorage<Model, null>  $visited
     * @param  array<string, Relation<Model, Model, mixed>|null>  $relations  Relation instances by class and name, built once.
     * @param  array<class-string<Model>, Model>  $targets  The models loaded through a `morphTo`, one per class.
     */
    private function collectRelationRowLabels(Model $record, array &$labels, SplObjectStorage $visited, array &$relations, array &$targets): void
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

            // A morphTo takes its owner key from the target class of the record it was built on: built once per class,
            // it would test the record of another target class against a key name that is not its own.
            if ($relation instanceof MorphTo && ! $loaded instanceof Model) {
                $relation = RelationGuard::relationOf($record, $name);
            }

            if ($relation instanceof BelongsTo) {
                $label = $loaded instanceof Model ? self::rowLabelOf($loaded) : self::pointedRowLabel($record, $relation);

                if ($label !== null) {
                    $labels[] = $label;
                }

                if ($relation instanceof MorphTo && $loaded instanceof Model) {
                    $targets[$loaded::class] ??= $loaded;
                }
            }

            foreach (self::modelsIn($loaded) as $child) {
                $this->collectRelationRowLabels($child, $labels, $visited, $relations, $targets);
            }
        }
    }
}
