<?php

declare(strict_types=1);

use Modules\Core\Search\Services\SearchQueryAnalyzer;

beforeEach(function (): void {
    config()->set('core.search.request_words', [
        'en' => ['find me', 'show me', 'articles about', 'news', 'are there'],
        'it' => ['trovami', 'mostrami', 'articoli su', 'articoli relativi a', 'documenti', "c'è", 'qualcosa su', 'ci sono'],
    ]);
});

it('drops the request words of the locale from the keyword terms', function (): void {
    $analyzer = new SearchQueryAnalyzer();

    expect($analyzer->withoutRequestWords('trovami articoli su Mario Rossi', 'it'))->toBe('Mario Rossi')
        ->and($analyzer->withoutRequestWords('Find me articles about the harbour', 'en'))->toBe('the harbour')
        ->and($analyzer->withoutRequestWords('articoli relativi a Verdi', 'it_IT'))->toBe('Verdi');
});

it('a query made only of request words keeps its terms', function (): void {
    $analyzer = new SearchQueryAnalyzer();

    expect($analyzer->withoutRequestWords('mostrami i documenti', 'it'))->toBe('mostrami i documenti')
        ->and($analyzer->withoutRequestWords('news', 'en'))->toBe('news')
        ->and($analyzer->withoutRequestWords('', 'en'))->toBe('');
});

it('matches request words case-insensitively and as whole words only', function (): void {
    $analyzer = new SearchQueryAnalyzer();

    expect($analyzer->withoutRequestWords('SHOW ME Rossi', 'en'))->toBe('Rossi')
        ->and($analyzer->withoutRequestWords('newsletter archive', 'en'))->toBe('newsletter archive')
        ->and($analyzer->withoutRequestWords('documentiamo Rossi', 'it'))->toBe('documentiamo Rossi');
});

it('matches a request word written with or without its accent and with either apostrophe', function (): void {
    $analyzer = new SearchQueryAnalyzer();

    expect($analyzer->withoutRequestWords("C'e qualcosa su Verdi?", 'it'))->toBe('Verdi?')
        ->and($analyzer->withoutRequestWords('c’è qualcosa su Verdi', 'it'))->toBe('Verdi');
});

it('applies only the list of the requested locale', function (): void {
    $analyzer = new SearchQueryAnalyzer();

    expect($analyzer->withoutRequestWords('show me Rossi', 'it'))->toBe('show me Rossi')
        ->and($analyzer->withoutRequestWords('trovami Rossi', 'en'))->toBe('trovami Rossi')
        ->and($analyzer->withoutRequestWords('show me Rossi', 'de'))->toBe('show me Rossi');
});

it('removes nothing when no request words are configured', function (): void {
    config()->set('core.search.request_words', null);

    expect((new SearchQueryAnalyzer())->withoutRequestWords('show me Rossi', 'en'))->toBe('show me Rossi');
});
