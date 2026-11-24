<?php

declare(strict_types=1);

use Modules\Core\Casts\ListRequestData;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Models\ACL;
use Modules\Core\Services\Crud\QueryBuilder;

/**
 * @param  array<string,mixed>  $validated
 */
function relation_paths_request(array $validated): ListRequestData
{
    return new ListRequestData(ListRequest::create('/app/crud/select/core/ACL', 'GET'), 'ACL', $validated, 'id', 'core');
}

it('puts a loaded relation in the display list only', function (): void {
    $data = relation_paths_request(['relations' => ['role']]);

    expect((new QueryBuilder)->requestedRelationPathsByUse(new ACL, $data))
        ->toBe(['display' => ['role'], 'membership' => []]);
});

it('puts a plain dotted column in the display list', function (): void {
    $data = relation_paths_request(['columns' => ['id', 'permission.name']]);

    expect((new QueryBuilder)->requestedRelationPathsByUse(new ACL, $data))
        ->toBe(['display' => ['permission'], 'membership' => []]);
});

it('puts a filter relation in the membership list only', function (): void {
    $data = relation_paths_request(['filters' => [['property' => 'role.name', 'value' => 'admin', 'operator' => '=']]]);

    expect((new QueryBuilder)->requestedRelationPathsByUse(new ACL, $data))
        ->toBe(['display' => [], 'membership' => ['role']]);
});

it('puts a sort relation in the membership list', function (): void {
    $data = relation_paths_request(['sort' => [['property' => 'permission.name', 'direction' => 'asc']]]);

    expect((new QueryBuilder)->requestedRelationPathsByUse(new ACL, $data)['membership'])->toBe(['permission']);
});

it('lists a relation both displayed and filtered in both lists and merges as before', function (): void {
    $data = relation_paths_request([
        'relations' => ['role'],
        'filters' => [['property' => 'role.name', 'value' => 'admin', 'operator' => '=']],
    ]);
    $builder = new QueryBuilder;
    $by_use = $builder->requestedRelationPathsByUse(new ACL, $data);

    expect($by_use)->toBe(['display' => ['role'], 'membership' => ['role']])
        ->and($builder->requestedRelationPaths(new ACL, $data))->toBe(['role']);
});

it('keeps the merged paths of a mixed request unchanged', function (): void {
    $data = relation_paths_request([
        'relations' => ['permission'],
        'filters' => [['property' => 'role.name', 'value' => 'admin', 'operator' => '=']],
    ]);
    $builder = new QueryBuilder;
    $by_use = $builder->requestedRelationPathsByUse(new ACL, $data);

    expect($builder->requestedRelationPaths(new ACL, $data))
        ->toBe(array_values(array_unique(array_merge($by_use['display'], $by_use['membership']))))
        ->toBe(['permission', 'role']);
});
