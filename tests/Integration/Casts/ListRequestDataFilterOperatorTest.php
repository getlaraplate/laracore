<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Http\Requests\SearchRequest;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\QueryBuilder;

/**
 * Every operator in both spellings: the symbols (`=`, `!=`, `>`, `>=`, `<`, `<=`) and the request names (`eq`, `ne`,
 * `gt`, `ge`, `lt`, `le`). `like`, `not like`, `in` and `between` are spelled the same in both.
 *
 * @return array<string, array{string, FilterOperator, mixed, list<string>}>
 */
function opspell_cases(): array
{
    $names = static fn (int ...$numbers): array => array_map(static fn (int $number): string => 'opspell_' . $number, $numbers);

    $definitions = [
        [FilterOperator::Equals, 'eq', 'opspell_3', $names(3)],
        [FilterOperator::NotEquals, 'ne', 'opspell_3', $names(1, 2, 4, 5)],
        [FilterOperator::Great, 'gt', 'opspell_3', $names(4, 5)],
        [FilterOperator::GreatEquals, 'ge', 'opspell_3', $names(3, 4, 5)],
        [FilterOperator::Less, 'lt', 'opspell_3', $names(1, 2)],
        [FilterOperator::LessEquals, 'le', 'opspell_3', $names(1, 2, 3)],
        [FilterOperator::Like, 'like', '3', $names(3)],
        [FilterOperator::NotLike, 'not like', '3', $names(1, 2, 4, 5)],
        [FilterOperator::In, 'in', ['opspell_1', 'opspell_4'], $names(1, 4)],
        [FilterOperator::Between, 'between', ['opspell_2', 'opspell_4'], $names(2, 3, 4)],
    ];

    $cases = [];

    foreach ($definitions as [$operator, $named, $value, $expected]) {
        foreach (array_unique([$operator->value, $named]) as $spelling) {
            $cases[$spelling . ' as ' . $operator->name] = [$spelling, $operator, $value, $expected];
        }
    }

    return $cases;
}

function opspell_rows(): void
{
    foreach (range(1, 5) as $number) {
        Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'opspell_' . $number, 'group_name' => 'opspell']);
    }
}

function opspell_viewer(): User
{
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::findOrCreate('superadmin', 'web'));

    return $viewer;
}

/**
 * The request tree of the rows under test: the five seeded names AND the condition under test.
 *
 * @return array{operator: string, filters: list<array<string, mixed>>}
 */
function opspell_tree(mixed $operator, mixed $value): array
{
    return [
        'operator' => 'and',
        'filters' => [
            ['property' => 'name', 'operator' => 'like', 'value' => 'opspell_'],
            ['property' => 'name', 'operator' => $operator, 'value' => $value],
        ],
    ];
}

/**
 * @param  array<mixed>  $filters
 */
function opspell_parse(array $filters): ListRequestData
{
    return new ListRequestData(new ListRequest(), 'setting', ['sort' => [], 'filters' => $filters], 'id');
}

/**
 * The names the parsed filters keep among the seeded rows.
 *
 * @return list<string>
 */
function opspell_names(ListRequestData $data): array
{
    $query = Setting::query()->where('name', 'like', 'opspell%');

    (new QueryBuilder())->applyFilters($query, $data->filters);

    return $query->orderBy('name')->pluck('name')->all();
}

/**
 * @param  array<mixed>  $tree
 */
function opspell_query(array $tree): string
{
    return http_build_query(['filters' => json_encode($tree, JSON_THROW_ON_ERROR)]);
}

it('reads the operator in either spelling and keeps the same real rows', function (string $spelling, FilterOperator $operator, mixed $value, array $expected): void {
    opspell_rows();

    $data = opspell_parse([['property' => 'name', 'operator' => $spelling, 'value' => $value]]);

    expect($data->filters->filters[0])->toBeInstanceOf(Filter::class)
        ->and($data->filters->filters[0]->operator)->toBe($operator)
        ->and(opspell_names($data))->toBe($expected);
})->with(opspell_cases());

it('reads the operator of a search request in either spelling', function (string $spelling, FilterOperator $operator, mixed $value): void {
    $data = new SearchRequestData(new SearchRequest(), 'setting', ['qs' => 'opspell', 'sort' => [], 'filters' => [['property' => 'name', 'operator' => $spelling, 'value' => $value]]], 'id');

    expect($data->filters->filters[0]->operator)->toBe($operator);
})->with(array_map(static fn (array $case): array => array_slice($case, 0, 3), opspell_cases()));

it('reads an omitted operator as equals', function (): void {
    opspell_rows();

    $data = opspell_parse([['property' => 'name', 'value' => 'opspell_3']]);

    expect($data->filters->filters[0]->operator)->toBe(FilterOperator::Equals)
        ->and(opspell_names($data))->toBe(['opspell_3']);
});

it('keeps an operator that a caller already read', function (): void {
    opspell_rows();

    $data = opspell_parse([['property' => 'name', 'operator' => FilterOperator::NotEquals, 'value' => 'opspell_3']]);

    expect($data->filters->filters[0]->operator)->toBe(FilterOperator::NotEquals)
        ->and(opspell_names($data))->toBe(['opspell_1', 'opspell_2', 'opspell_4', 'opspell_5']);
});

it('reads a symbol operator inside a nested group', function (): void {
    opspell_rows();

    $data = opspell_parse(['operator' => 'or', 'filters' => [
        ['property' => 'name', 'operator' => '<', 'value' => 'opspell_2'],
        ['property' => 'name', 'operator' => '>=', 'value' => 'opspell_5'],
    ]]);

    expect(opspell_names($data))->toBe(['opspell_1', 'opspell_5']);
});

it('refuses an operator that is in neither spelling instead of reading it as equals', function (mixed $operator): void {
    try {
        opspell_parse([['property' => 'name', 'operator' => $operator, 'value' => 'opspell_3']]);
        $this->fail('The operator should have been refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('filters');
    }
})->with([
    'an unknown word' => 'contains',
    'a different case of a known name' => 'EQ',
    'an empty string' => '',
    'a sql fragment' => '= 1 or 1 = 1',
    'null' => null,
    'a number' => 1,
    'a list' => [['eq']],
]);

it('refuses an unknown operator nested in a group', function (): void {
    expect(fn () => opspell_parse(['operator' => 'or', 'filters' => [
        ['property' => 'name', 'operator' => 'eq', 'value' => 'opspell_1'],
        ['operator' => 'and', 'filters' => [['property' => 'name', 'operator' => 'nope', 'value' => 'opspell_2']]],
    ]]))->toThrow(ValidationException::class);
});

it('names both spellings of every operator in the refusal', function (): void {
    try {
        opspell_parse([['property' => 'name', 'operator' => 'nope', 'value' => 'x']]);
        $this->fail('The operator should have been refused.');
    } catch (ValidationException $exception) {
        $message = $exception->errors()['filters'][0];

        foreach (['=', '!=', '>', '>=', '<', '<=', 'eq', 'ne', 'gt', 'ge', 'lt', 'le', 'like', 'not like', 'in', 'between'] as $operator) {
            expect($message)->toContain($operator);
        }

        expect($message)->not->toContain('nope');
    }
});

it('lists the rows of the list route alike with either spelling', function (string $spelling, FilterOperator $operator, mixed $value, array $expected): void {
    opspell_rows();

    $response = $this->actingAs(opspell_viewer())->getJson('/app/crud/select/core/settings?' . opspell_query(opspell_tree($spelling, $value)));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe($expected);
})->with(opspell_cases());

it('counts the rows of the aggregate route alike with either spelling', function (string $spelling, FilterOperator $operator, mixed $value, array $expected): void {
    opspell_rows();

    $response = $this->actingAs(opspell_viewer())->postJson('/app/crud/aggregate/core/settings', [
        'measures' => [['fn' => 'count']],
        'filters' => opspell_tree($spelling, $value),
    ]);

    $response->assertOk();

    expect($response->json('data.total.count'))->toBe(count($expected));
})->with(opspell_cases());

it('counts the rows of the facets route alike with either spelling', function (string $spelling, FilterOperator $operator, mixed $value, array $expected): void {
    opspell_rows();

    $response = $this->actingAs(opspell_viewer())->getJson('/app/crud/facets/core/settings?' . http_build_query([
        'columns' => ['group_name'],
        'filters' => json_encode(opspell_tree($spelling, $value), JSON_THROW_ON_ERROR),
    ]));

    $response->assertOk();
    $by_value = collect($response->json('data.group_name'))->keyBy('value');

    expect($by_value['opspell']['count'])->toBe(count($expected));
})->with(opspell_cases());

it('refuses with 422 an unknown operator on the list and facets routes, naming the allowed operators', function (string $uri): void {
    opspell_rows();

    $response = $this->actingAs(opspell_viewer())->getJson($uri . '?' . opspell_query(opspell_tree('nope', 'opspell_3')));

    $response->assertUnprocessable()->assertJsonValidationErrors('filters');
    expect($response->json('errors.filters.0'))->toBe('filters.filters.1 "operator" must be one of: ' . FilterOperator::requestVocabulary());
})->with([
    'list' => '/app/crud/select/core/settings',
    'facets' => '/app/crud/facets/core/settings',
]);

it('refuses with 422 an unknown operator on the aggregate route, naming the allowed operators', function (): void {
    opspell_rows();

    $response = $this->actingAs(opspell_viewer())->postJson('/app/crud/aggregate/core/settings', [
        'measures' => [['fn' => 'count']],
        'filters' => opspell_tree('nope', 'opspell_3'),
    ]);

    $response->assertUnprocessable();

    expect($response->json('error.code'))->toBe('INVALID_FIELD')
        ->and($response->json('error.field'))->toBe('filters')
        ->and($response->json('error.message'))->toBe('filters.filters.1 "operator" must be one of: ' . FilterOperator::requestVocabulary());
});
