<?php

declare(strict_types=1);

use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\ElasticsearchLocaleTextFields;
use Modules\Core\Search\Services\TextMatchOptionsResolver;
use Modules\Core\Tests\Stubs\Search\NestedVectorLocaleStubModel;

/**
 * A stemming analyzer reduces a proper name to a stem it may share with common words ("Rossi" and "rosso").
 * Text fields analysed by one also carry an `exact` sub-field that is only lowercased: the text query searches
 * both, and the exact-match clause searches the exact one, so a word that only shares the stem of the name gets
 * no exact-match boost. A field without the sub-field (an index built before it) is queried as before.
 */
function unstemmed_engine(): ElasticsearchEngine
{
    return (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();
}

/**
 * @param  array<string, mixed>  $text_match
 * @return array<string, mixed>
 */
function unstemmed_query(string $query, array $text_match = []): array
{
    $builder = new ScoutBuilder(new NestedVectorLocaleStubModel, $query);
    $builder->options[TextMatchOptionsResolver::BUILDER_OPTION] = $text_match;
    $builder->where('locales', ['it']);

    return unstemmed_engine()->buildKeywordSearchParams($builder, 5, 1)['body']['query']['bool']['must'][0];
}

/**
 * @return array<string, mixed>
 */
function unstemmed_text(bool $with_exact): array
{
    $text = ['type' => 'text', 'analyzer' => 'italian'];

    return $with_exact ? [...$text, 'fields' => ['exact' => ['type' => 'text', 'analyzer' => 'standard']]] : $text;
}

function unstemmed_live_mapping(bool $with_exact): void
{
    app()->instance(ElasticsearchLocaleTextFields::class, new ElasticsearchLocaleTextFields(static fn (): array => [
        'title' => ['type' => 'object', 'properties' => ['en' => unstemmed_text($with_exact), 'it' => unstemmed_text($with_exact)]],
        'subtitle' => ['type' => 'object', 'properties' => ['it' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword']]]]],
        'entity' => ['type' => 'keyword'],
        'locales' => ['type' => 'keyword'],
    ]));
}

it('the text match queries the stemmed and the exact field together', function (): void {
    unstemmed_live_mapping(with_exact: true);

    $query = unstemmed_query('festival', ['exact_match_boost' => 0]);

    expect($query['multi_match']['fields'])->toBe(['title.it^2', 'title.it.exact^2', 'subtitle.it', 'entity']);
});

it('a proper name matches through the exact field', function (): void {
    unstemmed_live_mapping(with_exact: true);

    $should = unstemmed_query('Rossi', ['exact_match_boost' => 2.0])['bool']['should'];

    expect($should[0]['multi_match']['fields'])->toBe(['title.it^2', 'title.it.exact^2', 'subtitle.it', 'entity'])
        ->and($should[1]['multi_match'])->toMatchArray([
            'query' => 'Rossi',
            'type' => 'phrase',
            'boost' => 2.0,
            'fields' => ['title.it.exact^2', 'subtitle.it', 'entity'],
        ]);
});

it('keeps the query shape of an index built before the exact sub-field', function (): void {
    unstemmed_live_mapping(with_exact: false);

    $should = unstemmed_query('Rossi', ['exact_match_boost' => 2.0])['bool']['should'];

    expect($should[0]['multi_match']['fields'])->toBe(['title.it^2', 'subtitle.it', 'entity'])
        ->and($should[1]['multi_match']['fields'])->toBe(['title.it^2', 'subtitle.it', 'entity']);
});
