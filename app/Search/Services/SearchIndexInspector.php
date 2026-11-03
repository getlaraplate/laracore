<?php

declare(strict_types=1);

namespace Modules\Core\Search\Services;

use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Engines\NullEngine;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\DTOs\SearchIndexState;

/**
 * Reads whether a model's search index matches the model, the check behind `scout:check-index`
 * and `scout:rebuild`.
 *
 * Two questions, both answered by the engine when it can: does the index exist
 * (`checkIndex`, e.g. ElasticsearchEngine::checkIndex), and does its live mapping still match the
 * declared one (`structureMismatches`). Existence is not enough: a field whose type drifted (e.g.
 * text -> object) keeps the index present but breaks every write. An engine without one of the
 * methods passes that check. Engines that keep no index of their own (database, collection, null)
 * are reported as unmanaged.
 */
final readonly class SearchIndexInspector
{
    public function inspect(ISearchableModel $model): SearchIndexState
    {
        $engine = $model->searchableUsing();

        if (! $this->engineKeepsIndex($engine)) {
            return SearchIndexState::unmanaged();
        }

        // method_exists rather than is_callable: both hold here, and only one narrows $engine to
        // an object carrying the method.
        $exists = ! method_exists($engine, 'checkIndex') || (bool) $engine->checkIndex($model);

        if (! $exists) {
            return new SearchIndexState(managed: true, exists: false);
        }

        $mismatches = method_exists($engine, 'structureMismatches')
            ? (array) $engine->structureMismatches($model)
            : [];

        return new SearchIndexState(
            managed: true,
            exists: true,
            mismatches: array_values(array_map(strval(...), array_filter($mismatches, is_scalar(...)))),
        );
    }

    /**
     * Whether the engine stores documents in an index of its own, which can drift from the model.
     */
    public function engineKeepsIndex(object $engine): bool
    {
        return ! $engine instanceof DatabaseEngine
            && ! $engine instanceof CollectionEngine
            && ! $engine instanceof NullEngine;
    }
}
