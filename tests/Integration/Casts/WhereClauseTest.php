<?php

declare(strict_types=1);

use Modules\Core\Casts\WhereClause;

it('reads the group operator of whatever a request sent', function (mixed $sent, ?WhereClause $expected): void {
    expect(WhereClause::fromInput($sent))->toBe($expected);
})->with([
    'and' => ['and', WhereClause::And],
    'or' => ['or', WhereClause::Or],
    'in capitals' => ['OR', WhereClause::Or],
    'in mixed case' => ['And', WhereClause::And],
    'an operator already read' => [WhereClause::Or, WhereClause::Or],
    'an unknown word' => ['xor', null],
    'an empty string' => ['', null],
    'null' => [null, null],
    'a number' => [1, null],
    'a list' => [['or'], null],
]);

it('describes the group operators a request may use', function (): void {
    expect(WhereClause::requestVocabulary())->toBe('and, or');
});
