<?php

declare(strict_types=1);

namespace Modules\Core\Search\Contracts;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;

/**
 * Interface that defines methods for search engines
 * Extends ISearchable to include engine-specific functionality.
 */
interface ISearchEngine extends ISearchable // , ISearchAnalytics
{
    //    public array $config {
    //        get;
    //        set;
    //    }

    /**
     * Check if the engine offers vector search at all.
     *
     * Deliberately not named `supportsVectorSearch`: Scout 11 introduced a protected
     * `supportsVectorSearch($model)` on {@see \Laravel\Scout\Engines\Concerns\PerformsDatabaseVectorSearch},
     * which asks a different question (is *this model's* connection PostgreSQL with pgvector) and which
     * {@see \Laravel\Scout\Engines\DatabaseEngine} calls on itself. An engine extending it cannot answer both
     * under one name, and the framework's signature is the one worth preserving untouched.
     */
    public function offersVectorSearch(): bool;

    /**
     * Check if the engine supports the Core orchestrated search pipeline.
     */
    public function supportsOrchestratedSearch(): bool;

    /**
     * Check if the engine supports vector retrieval inside the Core orchestrated search pipeline.
     */
    public function supportsOrchestratedVectorSearch(): bool;

    /**
     * Describe native and degraded portable text matching support.
     *
     * @return array<string, mixed>
     */
    public function textMatchCapabilities(): array;

    /**
     * Perform the Scout search for the given builder.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function search(Builder $builder): mixed;

    /**
     * Create an index.
     *
     * @param  mixed  $name  string index name, model instance, or model class-string depending on the engine
     * @param  array<string,mixed>  $options  Index options
     * @param  bool  $force  Force index creation even if it already exists
     */
    public function createIndex(mixed $name, array $options = [], bool $force = false): void;

    public function health(): array;

    /**
     * Lightweight reachability check, without collecting any statistics.
     */
    public function ping(): bool;

    public function stats(): array;

    public function getName(): string;
}
