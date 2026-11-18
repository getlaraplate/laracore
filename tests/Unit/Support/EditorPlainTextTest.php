<?php

declare(strict_types=1);

use Modules\Core\Support\EditorPlainText;

/*
 * The plain text of an Editor field (`FieldType::Editor`, an Editor.js document of `blocks`): what the search index,
 * the reranker and the reading statistics read. Spec 2026-11-17-extended-entity-search-design.md, XS15.
 */

it('flattens the text of every text-bearing block into one plain string', function (): void {
    $blocks = [
        ['type' => 'header', 'data' => ['text' => 'Main <b>title</b>']],
        ['type' => 'paragraph', 'data' => ['text' => 'Caf&eacute; opens at 8.30,<br>every day.']],
        ['type' => 'image', 'data' => ['file' => ['url' => 'https://example.test/a.jpg'], 'caption' => 'The square']],
        ['type' => 'list', 'data' => ['items' => ['First', ['text' => 'Second']]]],
        ['type' => 'quote', 'data' => ['text' => 'Be brief', 'caption' => 'Anonymous', 'source' => ['url' => null]]],
        ['type' => 'embed', 'data' => ['source' => 'https://example.test/video', 'embed' => 'https://example.test/embed']],
    ];

    expect(EditorPlainText::fromBlocks($blocks))
        ->toBe('Main title Café opens at 8.30, every day. The square First Second Be brief Anonymous');
});

it('reads table cells and raw html', function (): void {
    $blocks = [
        ['type' => 'table', 'data' => ['content' => [['Name', 'Age'], ['Ada', '36'], 'not a row']]],
        ['type' => 'raw', 'data' => ['html' => '<p>Raw <i>markup</i></p>']],
    ];

    expect(EditorPlainText::fromBlocks($blocks))->toBe('Name Age Ada 36 Raw markup');
});

it('returns an empty string for blocks without text', function (): void {
    expect(EditorPlainText::fromBlocks([['type' => 'delimiter', 'data' => []]]))->toBe('')
        ->and(EditorPlainText::fromBlocks([]))->toBe('');
});

it('keeps the text of editor find marks and drops their markup', function (): void {
    $html = '<span data-editor-find-mark="1">visible</span> text <span data-editor-find-mark="1"><del class="editor-find-strike">old</del>new</span>';

    expect(EditorPlainText::stripFindMarks($html))->toBe('visible text old')
        ->and(EditorPlainText::stripFindMarks(''))->toBe('');
});

it('accepts stdClass blocks from json decode', function (): void {
    $payload = json_decode((string) json_encode([
        'blocks' => [
            (object) ['type' => 'paragraph', 'data' => (object) ['text' => 'One <b>two</b> three.']],
        ],
    ]));

    expect(EditorPlainText::fromBlocks($payload->blocks))->toBe('One two three.');
});

it('normalizes blocks with a default type and string-keyed data', function (): void {
    $blocks = EditorPlainText::blocks([
        ['data' => ['text' => 'a', 0 => 'dropped']],
        (object) ['type' => 'header', 'data' => (object) ['text' => 'b']],
        'not a block',
    ]);

    expect($blocks)->toBe([
        ['type' => 'paragraph', 'data' => ['text' => 'a']],
        ['type' => 'header', 'data' => ['text' => 'b']],
        ['type' => 'paragraph', 'data' => []],
    ]);
});

it('lists the text-bearing segments of a block', function (): void {
    expect(EditorPlainText::segments(['type' => 'image', 'data' => ['caption' => 'Cap', 'text' => 'Txt']]))->toBe(['Txt', 'Cap'])
        ->and(EditorPlainText::segments(['type' => 'delimiter', 'data' => []]))->toBe([]);
});

it('turns a stored field value into its searchable text', function (mixed $value, mixed $expected): void {
    expect(EditorPlainText::fromValue($value))->toBe($expected);
})->with([
    'a plain string loses its control characters' => ["Line one\nline\ttwo\r", 'Line onelinetwo'],
    'an Editor document as an array' => [['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hello <b>world</b>']]]], 'Hello world'],
    'an Editor document as an object' => [(object) ['blocks' => [(object) ['type' => 'paragraph', 'data' => (object) ['text' => 'Hi']]]], 'Hi'],
    'an array that is not an Editor document' => [['a' => 1], ['a' => 1]],
    'a number' => [42, 42],
    'null' => [null, null],
]);
