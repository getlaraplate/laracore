<?php

declare(strict_types=1);

use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\RequestFilterOperator;

it('maps request operator string to filter operator', function (): void {
    expect(FilterOperator::tryFromRequestOperator('eq'))->toBe(FilterOperator::Equals)
        ->and(FilterOperator::tryFromRequestOperator(RequestFilterOperator::Like))->toBe(FilterOperator::Like);
});

it('returns null for unknown operator', function (): void {
    expect(FilterOperator::tryFromRequestOperator('unknown'))->toBeNull();
});

it('maps every symbol and every request name to the same operator', function (): void {
    foreach (FilterOperator::cases() as $operator) {
        $named = RequestFilterOperator::tryFromFilterOperator($operator);

        expect(FilterOperator::tryFromRequestOperator($operator->value))->toBe($operator)
            ->and(FilterOperator::tryFromRequestOperator($named->value))->toBe($operator)
            ->and(FilterOperator::tryFromRequestOperator($named))->toBe($operator);
    }
});

it('reads the operator of whatever a request sent', function (mixed $sent, ?FilterOperator $expected): void {
    expect(FilterOperator::tryFromRequestValue($sent))->toBe($expected);
})->with([
    'a symbol' => ['!=', FilterOperator::NotEquals],
    'a request name' => ['ge', FilterOperator::GreatEquals],
    'a request enum' => [RequestFilterOperator::Less, FilterOperator::Less],
    'an operator already read' => [FilterOperator::In, FilterOperator::In],
    'an unknown word' => ['unknown', null],
    'a name in capitals' => ['EQ', FilterOperator::Equals],
    'a name in mixed case' => ['Not Like', FilterOperator::NotLike],
    'null' => [null, null],
    'a number' => [1, null],
    'a list' => [['eq'], null],
]);

it('describes the vocabulary a request may use', function (): void {
    expect(FilterOperator::requestVocabulary())->toBe('> (gt), >= (ge), < (lt), <= (le), like, not like, = (eq), in, != (ne), between');
});

it('has expected cases', function (): void {
    expect(FilterOperator::Equals->value)->toBe('=')
        ->and(FilterOperator::In->value)->toBe('in');
});
