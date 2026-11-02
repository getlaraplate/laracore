<?php

declare(strict_types=1);

use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Services\SimpleQueryIntentParser;

it('implements IQueryIntentParser contract', function (): void {
    expect(new SimpleQueryIntentParser)->toBeInstanceOf(IQueryIntentParser::class);
});

it('extracts keywords removing stopwords', function (): void {
    $parser = new SimpleQueryIntentParser;
    $result = $parser->parse('the quick brown fox');

    expect($result['keywords'])->toContain('quick', 'brown', 'fox');
    expect($result['keywords'])->not->toContain('the');
});

it('removes Italian stopwords', function (): void {
    $parser = new SimpleQueryIntentParser;
    $result = $parser->parse('il gatto della nonna');

    expect($result['keywords'])->toContain('gatto', 'nonna');
    expect($result['keywords'])->not->toContain('il', 'della');
});

it('returns original query as expanded', function (): void {
    $parser = new SimpleQueryIntentParser;
    $result = $parser->parse('test query');

    expect($result['query']['expanded'])->toBe('test query');
});

it('returns null date_range', function (): void {
    $parser = new SimpleQueryIntentParser;
    $result = $parser->parse('test');

    expect($result['date_range'])->toBeNull();
});

it('deduplicates keywords', function (): void {
    $parser = new SimpleQueryIntentParser;
    $result = $parser->parse('test test test unique');

    expect($result['keywords'])->toBe(['test', 'unique']);
});

it('filters single-character words', function (): void {
    $parser = new SimpleQueryIntentParser;
    $result = $parser->parse('a b testing');

    expect($result['keywords'])->toBe(['testing']);
});

it('reads the date interval the query names, relative to today', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-02 09:00:00'));

    expect((new SimpleQueryIntentParser)->parse('fatture del mese scorso')['date_range'])
        ->toBe(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->and((new SimpleQueryIntentParser)->parse('reports of 2024')['date_range'])
        ->toBe(['from' => '2024-01-01', 'to' => '2024-12-31']);
});

it('says whether the query asks for recent results', function (): void {
    expect((new SimpleQueryIntentParser)->parse('ultime notizie')['recent'])->toBeTrue()
        ->and((new SimpleQueryIntentParser)->parse('notizie')['recent'])->toBeFalse();
});
