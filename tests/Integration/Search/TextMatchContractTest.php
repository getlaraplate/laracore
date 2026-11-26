<?php

declare(strict_types=1);

use Laravel\Scout\Builder as ScoutBuilder;
use Modules\Core\Search\DTOs\TextMatchOptions;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Search\Engines\ElasticsearchEngine;
use Modules\Core\Search\Engines\TypesenseEngine;
use Modules\Core\Search\Services\DatabaseTextMatchCompiler;
use Modules\Core\Search\Services\SearchQueryAnalyzer;
use Modules\Core\Search\Services\SearchQuerySyntaxParser;
use Modules\Core\Search\Services\TextMatchOptionsResolver;
use Modules\Core\Tests\Stubs\Search\DatabaseEngineSQLiteVectorSearchUser;

it('normalizes portable text match options without binding callers to named profiles', function (): void {
    $options = TextMatchOptions::fromArray([
        'max_edits' => 9,
        'operator' => 'unsupported',
        'similarity_threshold' => -1,
        'prefix' => false,
    ]);

    expect($options->maxEdits)->toBe(1)
        ->and($options->operator)->toBe('and')
        ->and($options->similarityThreshold)->toBe(0.0)
        ->and($options->prefix)->toBeFalse();
});

it('translates portable text matching to elasticsearch', function (): void {
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();
    $query = $engine->buildTextMatchQuery('fattura', TextMatchOptions::fromArray([
        'max_edits' => 1,
        'prefix' => true,
        'prefix_length' => 2,
        'exact_match_boost' => 3,
    ]));

    expect($query['bool']['should'][0]['multi_match'])
        ->toMatchArray([
            'query' => 'fattura',
            'type' => 'bool_prefix',
            'operator' => 'and',
            'fuzziness' => 1,
            'prefix_length' => 2,
            'fuzzy_transpositions' => true,
        ])
        ->and($query['bool']['should'][1]['multi_match']['boost'])->toBe(3.0);
});

it('does not enable elasticsearch fuzziness below the configured minimum length', function (): void {
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();
    $query = $engine->buildTextMatchQuery('fat', new TextMatchOptions());

    expect($query['bool']['should'][0]['multi_match'])->not->toHaveKey('fuzziness');
});

it('translates portable text matching to typesense', function (): void {
    $engine = (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    $parameters = $engine->buildTextMatchParameters(TextMatchOptions::fromArray([
        'max_edits' => 1,
        'minimum_term_length' => 5,
        'two_edit_minimum_term_length' => 9,
        'prefix' => false,
    ]));

    expect($parameters)->toMatchArray([
        'num_typos' => 1,
        'prefix' => false,
        'min_len_1typo' => 5,
        'min_len_2typo' => 9,
        'prioritize_exact_match' => true,
    ]);
});

it('compiles a portable database fallback for every database driver', function (string $driver): void {
    $compiled = (new DatabaseTextMatchCompiler())->compile(
        $driver,
        '"documents"."title"',
        'fattura',
        new TextMatchOptions(),
    );

    expect($compiled['sql'])->toBe('LOWER("documents"."title") LIKE LOWER(?)')
        ->and($compiled['bindings'])->toBe(['fattura%'])
        ->and($compiled['degraded'])->toBeTrue();
})->with(['mysql', 'mariadb', 'sqlite', 'oracle']);

it('lower-cases the pattern in PHP on Db2, which refuses an untyped marker inside LOWER', function (string $driver): void {
    $compiled = (new DatabaseTextMatchCompiler())->compile(
        $driver,
        '"documents"."title"',
        'Fattura È',
        new TextMatchOptions(),
    );

    expect($compiled['sql'])->toBe('LOWER("documents"."title") LIKE ?')
        ->and($compiled['bindings'])->toBe(['fattura è%'])
        ->and($compiled['degraded'])->toBeTrue();
})->with(Modules\Core\Support\Db2Drivers::DRIVERS);

it('keeps the database case folding of the pattern on every other driver', function (): void {
    $compiled = (new DatabaseTextMatchCompiler())->compile('sqlite', '"documents"."title"', 'Fattura È', new TextMatchOptions());

    expect($compiled['sql'])->toBe('LOWER("documents"."title") LIKE LOWER(?)')
        ->and($compiled['bindings'])->toBe(['Fattura È%']);
});

it('compiles postgres trigram matching when typo tolerance is available', function (): void {
    $compiled = (new DatabaseTextMatchCompiler())->compile(
        'pgsql',
        '"documents"."title"',
        'fattura',
        TextMatchOptions::fromArray(['similarity_threshold' => 0.7]),
    );

    expect($compiled['sql'])
        ->toContain('strict_word_similarity')
        ->and($compiled['bindings'])->toBe(['fattura%', 'fattura', 0.7])
        ->and($compiled['degraded'])->toBeFalse();
});

it('publishes text match capabilities for every core engine', function (string $engine): void {
    $instance = (new ReflectionClass($engine))->newInstanceWithoutConstructor();

    expect($instance->textMatchCapabilities())->not->toBeEmpty();
})->with([
    ElasticsearchEngine::class,
    TypesenseEngine::class,
    DatabaseEngine::class,
]);

it('classifies names acronyms and identifiers without losing original query signals', function (): void {
    $analysis = (new SearchQueryAnalyzer())->analyze('Mario Rossi CRM INV-1042 mario@example.it 2026');

    expect($analysis->tokenKinds())->toBe([
        'word',
        'word',
        'acronym',
        'structured_identifier',
        'email',
        'numeric',
    ])->and($analysis->significantTokenCount)->toBe(6)
        ->and($analysis->protectedTokenCount)->toBe(4)
        ->and($analysis->eligibleTokenCount)->toBe(2);
});

it('parses required terms and required phrases from familiar query syntax', function (): void {
    $parsed = (new SearchQuerySyntaxParser())->parse('consulente +Milano "Mario Rossi" software');

    expect($parsed->freeText)->toBe('consulente software')
        ->and($parsed->requiredTerms)->toBe(['Milano'])
        ->and($parsed->requiredPhrases)->toBe(['Mario Rossi']);
});

it('accepts redundant plus phrases and preserves unicode and escaped quotes', function (): void {
    $parsed = (new SearchQuerySyntaxParser())->parse('+"D’Angiò José" +città \\"citazione\\"');

    expect($parsed->freeText)->toBe('"citazione"')
        ->and($parsed->requiredTerms)->toBe(['città'])
        ->and($parsed->requiredPhrases)->toBe(['D’Angiò José']);
});

it('treats unmatched quotes and standalone plus as ordinary free text', function (): void {
    $parser = new SearchQuerySyntaxParser();
    $unmatched = $parser->parse('manuale "configurazione avanzata');
    $standalone = $parser->parse('manuale + configurazione');

    expect($unmatched->freeText)->toBe('manuale configurazione avanzata')
        ->and($unmatched->requiredPhrases)->toBe([])
        ->and($standalone->freeText)->toBe('manuale + configurazione')
        ->and($standalone->requiredTerms)->toBe([]);
});

it('keeps required syntax out of adaptive fuzziness and exposes counts only', function (): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve(
        'consulente +Milano "Mario Rossi" software',
        'tolerant',
    );
    $meta = $resolved->toMeta();

    expect($resolved->analysis->significantTokenCount)->toBe(2)
        ->and($resolved->options->query)->toBe('consulente software')
        ->and($resolved->options->requiredTerms)->toBe(['Milano'])
        ->and($resolved->options->requiredPhrases)->toBe(['Mario Rossi'])
        ->and($meta['required_term_count'])->toBe(1)
        ->and($meta['required_phrase_count'])->toBe(1)
        ->and($meta['options'])->not->toHaveKeys(['query', 'required_terms', 'required_phrases']);
});

it('translates required syntax to literal elasticsearch must clauses', function (): void {
    $engine = (new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor();
    $query = $engine->buildTextMatchQuery('ignored', TextMatchOptions::fromArray([
        'query' => 'consulente software',
        'required_terms' => ['Milano'],
        'required_phrases' => ['Mario Rossi'],
    ]));

    expect($query['bool']['must'])->toHaveCount(3)
        ->and($query['bool']['must'][1]['multi_match'])->toMatchArray([
            'query' => 'Milano',
            'type' => 'best_fields',
            'operator' => 'and',
        ])->and($query['bool']['must'][1]['multi_match'])->not->toHaveKey('fuzziness')
        ->and($query['bool']['must'][2]['multi_match'])->toMatchArray([
            'query' => 'Mario Rossi',
            'type' => 'phrase',
        ]);
});

it('translates required syntax to bound portable database clauses', function (): void {
    config()->set('database.default', 'sqlite');
    $resolver = new TextMatchOptionsResolver(new SearchQueryAnalyzer());
    $resolved = $resolver->resolve('consulente +Milano "Mario Rossi"', 'tolerant');
    $builder = new ScoutBuilder(new DatabaseEngineSQLiteVectorSearchUser(), 'consulente +Milano "Mario Rossi"');
    $builder->options[TextMatchOptionsResolver::BUILDER_OPTION] = $resolved->options->toEngineArray();
    $method = new ReflectionMethod(DatabaseEngine::class, 'initializeSearchQuery');
    $method->setAccessible(true);
    $query = $method->invoke(new DatabaseEngine(), $builder, ['username', 'email'], [], []);

    expect($query->toSql())->toContain('LOWER("users"."username") LIKE LOWER(?)')
        ->and($query->getBindings())->toContain('consulente%', '%Milano%', '%Mario Rossi%');
});

it('keeps protected-only short queries strict in automatic and tolerant modes', function (string $preference): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve('ACME', $preference);

    expect($resolved->options->typoTolerance)->toBeFalse()
        ->and($resolved->options->maxEdits)->toBe(0)
        ->and($resolved->options->fuzzyTokenLimit)->toBe(0);
})->with(['auto', 'tolerant']);

it('allows explicit identifier typo opt in', function (): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve('INV-1042', 'tolerant', [
        'identifier_typos' => true,
        'max_edits' => 1,
    ]);

    expect($resolved->options->typoTolerance)->toBeTrue()
        ->and($resolved->options->identifierTypos)->toBeTrue()
        ->and($resolved->options->maxEdits)->toBe(1);
});

it('requires complete coverage for mixed protected-token queries', function (): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve(
        'fattura INV-1042 cliente Rossi scaduta giugno',
        'tolerant',
    );

    expect($resolved->options->operator)->toBe('and')
        ->and($resolved->options->minimumShouldMatch)->toBe(100)
        ->and($resolved->options->typoTolerance)->toBeFalse();
});

it('keeps UUIDs exact even with explicit identifier typo opt in', function (): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve(
        '550e8400-e29b-41d4-a716-446655440000',
        'tolerant',
        ['identifier_typos' => true],
    );

    expect($resolved->options->typoTolerance)->toBeFalse()
        ->and($resolved->options->maxEdits)->toBe(0)
        ->and($resolved->options->fuzzyTokenLimit)->toBe(0);
});

it('uses strict two-token matching and relaxes token coverage for longer queries', function (): void {
    $resolver = new TextMatchOptionsResolver(new SearchQueryAnalyzer());
    $name = $resolver->resolve('Mario Rossi');
    $medium = $resolver->resolve('fatture fornitori italiane scadute giugno');
    $long = $resolver->resolve('mostra fatture fornitori italiani scadute giugno anno corrente');

    expect($name->options->operator)->toBe('and')
        ->and($name->options->minimumShouldMatch)->toBe(100)
        ->and($name->options->fuzzyTokenLimit)->toBe(1)
        ->and($medium->options->operator)->toBe('or')
        ->and($medium->options->minimumShouldMatch)->toBe(70)
        ->and($long->options->minimumShouldMatch)->toBe(70);
});

it('lets granular caller options override the preference within portable bounds', function (): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve('fattura giugno', 'balanced', [
        'max_edits' => 1,
        'prefix' => false,
        'minimum_should_match' => 80,
    ]);

    expect($resolved->options->maxEdits)->toBe(1)
        ->and($resolved->options->prefix)->toBeFalse()
        ->and($resolved->options->minimumShouldMatch)->toBe(80);
});

it('keeps strict at full coverage with one controlled fuzzy token', function (): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve('Giuseppe D’Angiò', 'strict');

    expect($resolved->options->typoTolerance)->toBeTrue()
        ->and($resolved->options->maxEdits)->toBe(1)
        ->and($resolved->options->minimumShouldMatch)->toBe(100)
        ->and($resolved->options->fuzzyTokenLimit)->toBe(1);
});

it('makes strict fully literal when typo tolerance is disabled explicitly', function (): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve(
        'Giuseppe Verdi',
        'strict',
        ['typo_tolerance' => false],
    );

    expect($resolved->options->typoTolerance)->toBeFalse()
        ->and($resolved->options->maxEdits)->toBe(0)
        ->and($resolved->options->fuzzyTokenLimit)->toBe(0)
        ->and($resolved->options->minimumShouldMatch)->toBe(100);
});

it('applies the agreed dynamic coverage matrix', function (
    string $preference,
    string $query,
    int $coverage,
    int $fuzzyTokenLimit,
): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve($query, $preference);

    expect($resolved->options->minimumShouldMatch)->toBe($coverage)
        ->and($resolved->options->fuzzyTokenLimit)->toBe($fuzzyTokenLimit)
        ->and($resolved->options->maxEdits)->toBe(1);
})->with([
    'balanced three' => ['balanced', 'alpha bravo charlie', 100, 2],
    'balanced four' => ['balanced', 'alpha bravo charlie delta', 75, 2],
    'balanced eight' => ['balanced', 'alpha bravo charlie delta echo foxtrot golf hotel', 65, 2],
    'balanced nine' => ['balanced', 'alpha bravo charlie delta echo foxtrot golf hotel india', 60, 2],
    'tolerant three' => ['tolerant', 'alpha bravo charlie', 66, 3],
    'tolerant four' => ['tolerant', 'alpha bravo charlie delta', 75, 3],
    'tolerant eight' => ['tolerant', 'alpha bravo charlie delta echo foxtrot golf hotel', 55, 3],
    'tolerant nine' => ['tolerant', 'alpha bravo charlie delta echo foxtrot golf hotel india', 50, 3],
    'strict nine' => ['strict', 'alpha bravo charlie delta echo foxtrot golf hotel india', 100, 1],
]);

it('keeps automatic keyword queries conservative and relaxes natural language', function (): void {
    $resolver = new TextMatchOptionsResolver(new SearchQueryAnalyzer());
    $keywords = $resolver->resolve('fatture clienti scadute giugno corrente');
    $sentence = $resolver->resolve('mostra le fatture dei clienti scadute giugno corrente');

    expect($keywords->options->minimumShouldMatch)->toBe(70)
        ->and($keywords->options->fuzzyTokenLimit)->toBe(1)
        ->and($sentence->options->minimumShouldMatch)->toBe(65)
        ->and($sentence->options->fuzzyTokenLimit)->toBe(2);
});

it('resolves empty and stopword-only queries conservatively', function (string $query): void {
    $resolved = (new TextMatchOptionsResolver(new SearchQueryAnalyzer()))->resolve($query);

    expect($resolved->effectivePreference->value)->toBe('strict')
        ->and($resolved->options->typoTolerance)->toBeFalse();
})->with(['', 'il di e']);
