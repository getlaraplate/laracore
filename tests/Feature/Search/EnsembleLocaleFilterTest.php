<?php

declare(strict_types=1);

use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Contracts\ILocaleFilterableEngine;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Services\EnsembleSearchService;
use Modules\Core\Search\Support\ScoutWheres;
use Modules\Core\Tests\Stubs\Search\EngineBoundStubModel;

/**
 * The language the results are requested in comes from the locale context, and it reaches every strategy as
 * a `locales` restriction, but only where the engine understands it and the index has the field: another
 * engine would read `locales` as an attribute the models do not have, and return nothing.
 */
function ensemble_locale_wheres(mixed $engine): array
{
    $model = new EngineBoundStubModel($engine);
    $builder = new ScoutBuilder($model, 'festival');

    app(EnsembleSearchService::class)->applyRequestedLocale($builder, $model);

    // Read the way the engines read it. Scout 11 stores where() constraints as a list of
    // field/operator/value triples, and asserting that shape here would pin a package internal instead of
    // the restriction these cases are about.
    return ScoutWheres::map($builder);
}

afterEach(function (): void {
    LocaleContext::set((string) config('app.locale'));
});

it('asks for the documents of the language in the locale context', function (string $locale): void {
    LocaleContext::set($locale);
    $engine = Mockery::mock(ISearchEngine::class, ILocaleFilterableEngine::class);
    $engine->shouldReceive('filtersByLocale')->andReturn(true);

    expect(ensemble_locale_wheres($engine))->toBe(['locales' => [$locale]]);
})->with(['it', 'en']);

it('does not filter a model whose index has no language', function (): void {
    $engine = Mockery::mock(ISearchEngine::class, ILocaleFilterableEngine::class);
    $engine->shouldReceive('filtersByLocale')->andReturn(false);

    expect(ensemble_locale_wheres($engine))->toBe([]);
});

it('does not filter through an engine that does not understand the restriction', function (bool $engine_exists): void {
    $engine = $engine_exists ? Mockery::mock(ISearchEngine::class) : null;

    expect(ensemble_locale_wheres($engine))->toBe([]);
})->with([
    'an engine without the capability' => [true],
    'no engine' => [false],
]);
