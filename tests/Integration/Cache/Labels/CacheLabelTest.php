<?php

declare(strict_types=1);

use Modules\Core\Cache\Labels\CacheLabel;

it('builds the label strings', function (): void {
    expect(CacheLabel::table('core_users'))->toBe('table:core_users')
        ->and(CacheLabel::row('core_users', 5))->toBe('row:core_users:5')
        ->and(CacheLabel::user(3))->toBe('user:3')
        ->and(CacheLabel::role('9'))->toBe('role:9')
        ->and(CacheLabel::all())->toBe('crud')
        ->and(CacheLabel::acl())->toBe('acl');
});

it('recognises row labels and maps them to their table', function (): void {
    expect(CacheLabel::isRow('row:core_users:5'))->toBeTrue()
        ->and(CacheLabel::isRow('table:core_users'))->toBeFalse()
        ->and(CacheLabel::tableOfRow('row:core_users:5'))->toBe('table:core_users');
});

it('adds labels to dependencies', function (): void {
    $dependencies = new Modules\Core\Cache\Labels\CacheDependencies(['a'], ['t']);
    $more = $dependencies->withLabels('b');

    expect($more->labels)->toBe(['a', 'b'])
        ->and($more->row_tables)->toBe(['t'])
        ->and($more->complete)->toBeTrue()
        ->and($dependencies->labels)->toBe(['a']);
});

it('stores an entry under the labels found after the query, adding the rows label of row labels only', function (): void {
    $dependencies = new Modules\Core\Cache\Labels\CacheDependencies(['crud'], ['core_roles']);

    expect(CacheLabel::storedUnder($dependencies, ['row:core_users:5', 'table:cms_contents']))->toEqualCanonicalizing([
        'crud',
        'row:core_users:5',
        'table:cms_contents',
        'rows:core_roles',
        'rows:core_users',
    ]);
});
