<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

/**
 * A model that always narrows its search with a fixed set of engine filters.
 *
 * Applied by every search path as AND equality constraints on the search-engine query, so the engine never
 * returns rows the caller must then drop at rehydration (which would short-page the results): the basic path
 * (`CrudService::searchWithScout()`) puts them on the Scout builder, and `AdvancedSearchService` joins them to the
 * caller's filters, which covers the orchestrated and auto paths and every other caller of the service. Example:
 * CMS `Content` filters `extended_type IS NULL` so extended contents stay out of the generic content search.
 *
 * Each field must be filterable in the model's search schema: the orchestrated path applies the defaults through
 * the same constraint applier as the request filters, which refuses a field the index cannot filter on.
 */
interface ProvidesDefaultSearchFilters
{
    /**
     * Field => value equality filters applied to every search of this model, whatever the path.
     *
     * @return array<string, scalar|null>
     */
    public function defaultSearchFilters(): array;
}
