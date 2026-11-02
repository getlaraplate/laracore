<?php

declare(strict_types=1);

namespace Modules\Core\Search\Contracts;

/**
 * Contract for parsing search query intent.
 *
 * Extracts structured information (keywords, expanded query, filters)
 * from a raw user query string.
 */
interface IQueryIntentParser
{
    /**
     * Parse a raw query into structured intent.
     *
     * `date_range` is the interval the query names (`from` and `to`, ISO dates), null when it names none;
     * `recent` says whether it asks for recent results. Both are optional for the search: an intent that
     * lacks them searches without a date filter.
     *
     * @return array{keywords: list<string>, date_range: ?array<string, string>, recent?: bool, query: array{expanded: string}}
     */
    public function parse(string $query): array;
}
