<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

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
 * them, so a model inheriting them stays compatible.
 */
interface ISearchableModel
{
    public static function reindex(?int $chunk = null): void;

    /**
     * @phpstan-return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model&self>
     */
    public static function makeAllSearchableQuery(); // @pest-ignore-type: Scout declares these without return types

    public function ensureIndexExists(): bool;

    public function isEmbeddable(): bool;

    /**
     * @return list<string>
     */
    public function getEmbedFields(): array;

    /**
     * @phpstan-return \Laravel\Scout\Engines\Engine
     */
    public function searchableUsing(); // @pest-ignore-type: Scout declares these without return types

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
