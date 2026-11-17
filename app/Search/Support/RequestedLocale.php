<?php

declare(strict_types=1);

namespace Modules\Core\Search\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Contracts\ILocaleFilterableEngine;

/**
 * Restricts a search to the documents available in the language the results are requested in, which is the
 * language of the locale context. The query may be in any language and the vectors carry none: only the documents
 * the language can be shown for come back, and the engine applies the restriction itself, so the first results are
 * not taken by documents that would be dropped afterwards.
 *
 * Every search path applies it: the basic one (`CrudService::searchWithScout()`) and each strategy of the
 * orchestrated one ({@see \Modules\Core\Search\Services\EnsembleSearchService}), so no path returns a document in
 * another language that another path keeps out.
 *
 * Only an engine that understands the restriction gets it, and only for a model whose index records the languages:
 * any other would read `locales` as an attribute the models do not have and return nothing.
 */
final class RequestedLocale
{
    /**
     * @param  ScoutBuilder<covariant Model>  $builder
     */
    public static function apply(ScoutBuilder $builder, Model $model): void
    {
        $engine = method_exists($model, 'searchableUsing') ? $model->searchableUsing() : null;

        if ($engine instanceof ILocaleFilterableEngine && $engine->filtersByLocale($model)) {
            $builder->where('locales', [LocaleContext::get()]);
        }
    }
}
