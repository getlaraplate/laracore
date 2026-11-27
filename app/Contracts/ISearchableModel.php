<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A model that is indexed in the search engine.
 *
 * Supplied by {@see \Modules\Core\Search\Traits\Searchable}, which wraps Scout and
 * adds the index lifecycle this application needs. Generic code — the reindex
 * action on a Filament table, the index-check command, the ensemble search — took
 * an Eloquent Model and called these on faith.
 *
 * Scout's own methods are declared without return types, the way Scout declares
 * them, so a model inheriting them stays compatible. The trait also requires the
 * embedding contract, so every searchable model is one.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
interface ISearchableModel extends IEmbeddableModel
{
    public static function reindex(?int $chunk = null): void;

    /**
     * @phpstan-return \Illuminate\Database\Eloquent\Builder<Model&self>
     */
    public static function makeAllSearchableQuery(); // @pest-ignore-type: Scout declares these without return types

    /**
     * @param  string  $query
     * @param  Closure|null  $callback
     *
     * @phpstan-return \Laravel\Scout\Builder<Model&static>
     */
    public static function search($query = '', $callback = null); // @pest-ignore-type: Scout declares these without return types

    public function ensureIndexExists(): bool;

    public function isEmbeddable(): bool;

    /**
     * When the index was last written for this model, or null when its engine cannot tell.
     */
    public function getLastIndexedTimestamp(): ?string;

    /**
     * @return list<string>
     */
    public function getEmbedFields(): array;

    /**
     * @phpstan-return \Laravel\Scout\Engines\Engine
     */
    public function searchableUsing(); // @pest-ignore-type: Scout declares these without return types

    /**
     * Prepare a chunk of this class's models for the index: the relations the searchable array reads are loaded here.
     *
     * @template TCollection of Collection<int, covariant Model>
     *
     * @param  TCollection  $models
     * @return TCollection
     */
    public function makeSearchableUsing(Collection $models): Collection;

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array;

    /**
     * @phpstan-return string
     */
    public function searchableAs(); // @pest-ignore-type: Scout declares these without return types

    /**
     * @phpstan-return bool
     */
    public function shouldBeSearchable(); // @pest-ignore-type: Scout declares these without return types

    /**
     * @phpstan-return int|string
     */
    public function getScoutKey(); // @pest-ignore-type: Scout declares these without return types

    /**
     * @phpstan-return string
     */
    public function getScoutKeyName(); // @pest-ignore-type: Scout declares these without return types

    public function searchable(); // @pest-ignore-type: Scout declares these without return types

    public function unsearchable(); // @pest-ignore-type: Scout declares these without return types

    /**
     * Index the given models of this class through the bulk path now.
     *
     * @param  Collection<int, static>  $models
     */
    public function makeSearchableInBulk(Collection $models): void;
}
