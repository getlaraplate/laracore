<?php

declare(strict_types=1);

namespace Modules\Core\Contracts;

/**
 * A model that always narrows its full-text (Scout) search with a fixed set of engine filters.
 *
 * Applied by every generic search path (`Modules\Core\Search\Support\DefaultSearchFilters`) as equality
 * constraints on the search-engine query, after the request constraints, so a request filter cannot widen
 * a default and the engine never returns rows the caller must then drop at rehydration (which would
 * short-page the results). Example: CMS `Content` filters `extended_type => null` so extended contents
 * stay out of the generic content search.
 */
interface ProvidesDefaultSearchFilters
{
    /**
     * Field => value equality filters applied to every Scout search of this model.
     *
     * @return array<string, scalar|null>
     */
    public function defaultSearchFilters(): array;
}
