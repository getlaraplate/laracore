<?php

declare(strict_types=1);

use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\FiltersGroupCast;
use Modules\Core\Casts\ScopeFilter;
use Modules\Core\Casts\WhereClause;
use Modules\Core\Models\ACL;

it('hydrates group from json string and dehydrates back to json', function (): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();
    $json = json_encode([
        'operator' => 'or',
        'filters' => [
            ['property' => 'name', 'value' => 'john', 'operator' => '='],
            ['filters' => [['property' => 'active', 'value' => true, 'operator' => '=']], 'operator' => 'and'],
        ],
    ], JSON_THROW_ON_ERROR);

    $group = $cast->get($model, 'filters', $json, []);
    $encoded = $cast->set($model, 'filters', $group, []);

    expect($group)->toBeInstanceOf(FiltersGroup::class)
        ->and($group->operator)->toBe(WhereClause::Or)
        ->and($group->filters[0])->toBeInstanceOf(Filter::class)
        ->and($group->filters[1])->toBeInstanceOf(FiltersGroup::class)
        ->and($encoded)->toBeString();
});

it('throws on unreadable get payloads and supports list payload hydration', function (): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();

    $from_list = $cast->get($model, 'filters', [
        ['property' => 'name', 'value' => 'john', 'operator' => '='],
    ], []);

    expect(fn () => $cast->get($model, 'filters', '{invalid', []))->toThrow(InvalidArgumentException::class);
    expect(fn () => $cast->get($model, 'filters', 123, []))->toThrow(InvalidArgumentException::class);
    expect($from_list)->toBeInstanceOf(FiltersGroup::class);
});

it('accepts array payload in set and rejects invalid set values', function (): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();

    $encoded = $cast->set($model, 'filters', [
        'filters' => [
            ['property' => 'id', 'value' => 1, 'operator' => '='],
        ],
        'operator' => 'and',
    ], []);

    expect($encoded)->toBeString();

    expect(fn () => $cast->set($model, 'filters', 'not-valid', []))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for invalid node/group structures and handles null set', function (): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();

    expect($cast->set($model, 'filters', null, []))->toBeNull();
    expect($cast->get($model, 'filters', '', []))->toBeNull();
    expect($cast->get($model, 'filters', ['property' => 'id', 'value' => 1, 'operator' => Modules\Core\Casts\FilterOperator::Equals], []))
        ->toBeInstanceOf(FiltersGroup::class);
    expect(fn () => $cast->get($model, 'filters', ['filters' => [[['property' => 'id', 'value' => 1, 'operator' => '=']]]], []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $cast->get($model, 'filters', ['foo' => 'bar'], []))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for a nested filter item that is not an array and for invalid nested nodes, never skipping a condition', function (): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();

    expect(fn () => $cast->get($model, 'filters', [
        'filters' => [
            'not-an-array',
            ['property' => 'name', 'value' => 'john', 'operator' => '='],
        ],
    ], []))->toThrow(InvalidArgumentException::class);

    expect(fn () => $cast->get($model, 'filters', [
        'filters' => [
            ['invalid' => 'node'],
        ],
    ], []))->toThrow(InvalidArgumentException::class);
});

it('round-trips a scope node with and without parameters, alone, in groups and inside a relation filter', function (array $stored): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();

    $group = $cast->get($model, 'filters', json_encode($stored, JSON_THROW_ON_ERROR), []);
    $encoded = $cast->set($model, 'filters', $group, []);

    expect($group)->toBeInstanceOf(FiltersGroup::class)
        ->and($cast->get($model, 'filters', $encoded, []))->toEqual($group);
})->with([
    'alone, no parameters' => [['scope' => 'valid']],
    'alone, empty parameters' => [['scope' => 'valid', 'parameters' => []]],
    'alone, with parameters' => [['scope' => 'validAt', 'parameters' => ['2026-10-01', '@now', 5, 1.5, true, null]]],
    'in a list' => [[['scope' => 'valid'], ['property' => 'name', 'operator' => '=', 'value' => 'a']]],
    'in an OR group' => [['operator' => 'or', 'filters' => [['scope' => 'valid'], ['filters' => [['scope' => 'expiring', 'parameters' => [24]]], 'operator' => 'and']]]],
    'inside a relation filter' => [['operator' => 'and', 'filters' => [['relation' => 'model', 'morph_types' => ['user'], 'filters' => ['scope' => 'valid']]]]],
]);

it('hydrates a scope node into a ScopeFilter and writes exactly its keys', function (): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();

    $group = $cast->get($model, 'filters', ['operator' => 'and', 'filters' => [['scope' => 'expiring', 'parameters' => [24]], ['scope' => 'valid']]], []);

    expect($group->filters[0])->toEqual(new ScopeFilter('expiring', [24]))
        ->and($group->filters[1])->toEqual(new ScopeFilter('valid'))
        ->and(json_decode($cast->set($model, 'filters', $group, []), true))->toBe([
            'filters' => [
                ['scope' => 'expiring', 'parameters' => [24]],
                ['scope' => 'valid', 'parameters' => []],
            ],
            'operator' => 'and',
        ]);
});

it('counts a scope node as a condition, at the top level and inside a relation filter', function (): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();

    expect($cast->get($model, 'filters', json_encode(['operator' => 'and', 'filters' => [['scope' => 'valid']]]), []))->toBeInstanceOf(FiltersGroup::class)
        ->and($cast->get($model, 'filters', json_encode(['operator' => 'and', 'filters' => [['relation' => 'model', 'morph_types' => ['user'], 'filters' => ['operator' => 'and', 'filters' => [['scope' => 'valid']]]]]]), []))
        ->toBeInstanceOf(FiltersGroup::class);
});

it('refuses a malformed scope node on read and on write', function (array $node): void {
    $cast = new FiltersGroupCast();
    $model = new ACL();
    $stored = ['operator' => 'and', 'filters' => [$node]];

    expect(fn () => $cast->get($model, 'filters', json_encode($stored), []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $cast->set($model, 'filters', $stored, []))->toThrow(InvalidArgumentException::class);
})->with([
    'an extra key' => [['scope' => 'valid', 'note' => 'x']],
    'keys of a filter too' => [['scope' => 'valid', 'property' => 'id', 'operator' => '=', 'value' => 1]],
    'keys of a group too' => [['scope' => 'valid', 'filters' => [['property' => 'id', 'operator' => '=', 'value' => 1]]]],
    'parameters keyed by name' => [['scope' => 'validAt', 'parameters' => ['date' => '2026-10-01']]],
    'parameters that are a scalar' => [['scope' => 'expiring', 'parameters' => 24]],
    'parameters that are null' => [['scope' => 'valid', 'parameters' => null]],
    'an array parameter' => [['scope' => 'named', 'parameters' => [['a', 'b']]]],
    'an object parameter' => [['scope' => 'named', 'parameters' => [['name' => 'a']]]],
    'an empty name' => [['scope' => '', 'parameters' => []]],
    'a missing name' => [['scope' => null]],
    'a name that is not a string' => [['scope' => ['valid']]],
]);

it('stores the operators in the syntax of the builder whatever spelling they were written in', function (string $written, string $stored): void {
    $cast = new FiltersGroupCast();

    $encoded = $cast->set(new ACL(), 'filters', [
        'operator' => 'AND',
        'filters' => [['property' => 'name', 'operator' => $written, 'value' => 'x']],
    ], []);

    $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['operator'])->toBe('and')
        ->and($decoded['filters'][0]['operator'])->toBe($stored);
})->with([
    'the symbol' => ['!=', '!='],
    'eq' => ['eq', '='],
    'ne' => ['ne', '!='],
    'gt' => ['gt', '>'],
    'ge' => ['GE', '>='],
    'lt' => ['lt', '<'],
    'le' => ['Le', '<='],
    'like' => ['LIKE', 'like'],
    'not like' => ['Not Like', 'not like'],
    'in' => ['in', 'in'],
    'between' => ['BETWEEN', 'between'],
]);

it('refuses to store an operator that is in no spelling instead of reading it as equals', function (mixed $operator): void {
    $cast = new FiltersGroupCast();

    expect(fn () => $cast->set(new ACL(), 'filters', [
        'operator' => 'and',
        'filters' => [['property' => 'name', 'operator' => $operator, 'value' => 'x']],
    ], []))->toThrow(InvalidArgumentException::class, 'Unknown operator of a filter.');
})->with(['an unknown word' => 'contains', 'an empty string' => '', 'a number' => 1, 'a list' => [['eq']]]);
