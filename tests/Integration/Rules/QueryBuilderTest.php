<?php

declare(strict_types=1);

use Modules\Core\Rules\QueryBuilder;

it('fails when value is not array', function (): void {
    $rule = new QueryBuilder;
    $message = null;
    $rule->validate('filters', 'invalid', function (string $m) use (&$message): void {
        $message = $m;
    });

    expect($message)->toBe("filters doesn't have a correct format");
});

it('passes for empty list', function (): void {
    $rule = new QueryBuilder;
    $fail_called = false;
    $rule->validate('filters', [], function (string $m) use (&$fail_called): void {
        $fail_called = true;
    });

    expect($fail_called)->toBeFalse();
});

it('passes for associative array with property operator value', function (): void {
    $rule = new QueryBuilder;
    $fail_called = false;
    $rule->validate('filter', [
        'property' => 'name',
        'operator' => '=',
        'value' => 'test',
    ], function (string $m) use (&$fail_called): void {
        $fail_called = true;
    });

    expect($fail_called)->toBeFalse();
});

it('fails when associative array has neither property nor filters', function (): void {
    $rule = new QueryBuilder;
    $message = null;
    $rule->validate('filter', ['foo' => 'bar'], function (string $m) use (&$message): void {
        $message = $m;
    });

    expect($message)->toBe("filter doesn't have a correct format");
});

it('fails when property is present but operator missing', function (): void {
    $rule = new QueryBuilder;
    $message = null;
    $rule->validate('filter', [
        'property' => 'name',
        'value' => 'test',
    ], function (string $m) use (&$message): void {
        $message = $m;
    });

    expect($message)->toBe('filter "operator" is required');
});

it('fails when property is present but value missing', function (): void {
    $rule = new QueryBuilder;
    $message = null;
    $rule->validate('filter', [
        'property' => 'name',
        'operator' => '=',
    ], function (string $m) use (&$message): void {
        $message = $m;
    });

    expect($message)->toBe('filter "value" is required');
});

it('accepts every operator in either spelling', function (string $operator): void {
    $failures = [];

    (new QueryBuilder)->validate('filter', ['property' => 'name', 'operator' => $operator, 'value' => 'test'], function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->toBe([]);
})->with(['=', '!=', '>', '>=', '<', '<=', 'eq', 'ne', 'gt', 'ge', 'lt', 'le', 'like', 'not like', 'in', 'between']);

it('fails with the allowed operators when the operator is in neither spelling', function (mixed $operator): void {
    $failures = [];

    (new QueryBuilder)->validate('filters', [
        ['property' => 'name', 'operator' => '=', 'value' => 'a'],
        ['operator' => 'or', 'filters' => [['property' => 'name', 'operator' => $operator, 'value' => 'b']]],
    ], function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->toBe(['filters.1.filters.0 "operator" must be one of: > (gt), >= (ge), < (lt), <= (le), like, not like, = (eq), in, != (ne), between']);
})->with(['an unknown word' => 'contains', 'a different case' => 'EQ', 'an empty string' => '', 'null' => null, 'a number' => 1, 'a list' => [['eq']]]);

it('passes for nested filters list', function (): void {
    $rule = new QueryBuilder;
    $fail_called = false;
    $rule->validate('filters', [
        'filters' => [
            ['property' => 'a', 'operator' => '=', 'value' => 1],
        ],
    ], function (string $m) use (&$fail_called): void {
        $fail_called = true;
    });

    expect($fail_called)->toBeFalse();
});

it('fails when filters key is not a list', function (): void {
    $rule = new QueryBuilder;
    $message = null;
    $rule->validate('filter', [
        'filters' => ['property' => 'a', 'operator' => '=', 'value' => 1],
    ], function (string $m) use (&$message): void {
        $message = $m;
    });

    expect($message)->toBe('filter filters doesn\'t have a correct format');
});

it('keeps accepting a request filter list that nests a bare list of conditions', function (): void {
    $failures = [];

    (new QueryBuilder)->validate('filters', [
        'filters' => [[['property' => 'id', 'operator' => '=', 'value' => 1]]],
        'operator' => 'and',
    ], function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->toBe([]);
});

it('accepts a scope node with or without parameters, alone, in a group and inside a relation filter', function (array $value): void {
    $failures = [];

    (new QueryBuilder)->validate('filters', $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect($failures)->toBe([]);
})->with([
    'alone' => [['scope' => 'valid']],
    'with parameters' => [['scope' => 'validAt', 'parameters' => ['2026-10-01', '@now', 5, 1.5, true, null]]],
    'in a group' => [['operator' => 'or', 'filters' => [['scope' => 'valid'], ['property' => 'id', 'operator' => '=', 'value' => 1]]]],
    'inside a relation filter' => [['filters' => [['relation' => 'model', 'morph_types' => ['user'], 'filters' => ['scope' => 'valid']]]]],
]);

it('refuses a malformed scope node', function (array $node, string $reason): void {
    $failures = [];

    (new QueryBuilder)->validate('filters', ['filters' => [$node]], function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    expect(implode(' | ', $failures))->toContain($reason);
})->with([
    'an extra key' => [['scope' => 'valid', 'note' => 'x'], 'takes only "scope" and "parameters"'],
    'keys of a filter too' => [['scope' => 'valid', 'property' => 'id', 'operator' => '=', 'value' => 1], 'takes only "scope" and "parameters"'],
    'an empty name' => [['scope' => ''], '"scope" must be a scope name'],
    'a name that is not a string' => [['scope' => 5], '"scope" must be a scope name'],
    'parameters keyed by name' => [['scope' => 'validAt', 'parameters' => ['date' => '2026-10-01']], '"parameters" must be a list'],
    'parameters that are a scalar' => [['scope' => 'expiring', 'parameters' => 24], '"parameters" must be a list'],
    'an array parameter' => [['scope' => 'named', 'parameters' => [['a']]], '"parameters" must be a list of scalar values'],
]);
