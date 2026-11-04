<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\AggregateErrorCode;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Http\Controllers\CrudController;
use Modules\Core\Http\Requests\AggregateRequest;
use Modules\Core\Models\License;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Support\DayTruncation;
use Modules\Core\Tests\Stubs\FakeAuthUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * @return array{module: string, entity: string}
 */
function aggregateRouteParams(): array
{
    return ['module' => 'core', 'entity' => 'settings'];
}

function aggregateRouteViewer(): User
{
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::findOrCreate('superadmin', 'web'));

    return $viewer;
}

/**
 * A settings row of the given group, created at the given moment.
 */
function aggregateRouteSetting(string $group, ?string $created_at = null): Setting
{
    return Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'aggregate_' . uniqid(),
        'group_name' => $group,
        ...($created_at !== null ? ['created_at' => $created_at] : []),
    ]);
}

/**
 * Binds a CrudService whose aggregate fails with the given throwable, so the controller maps what the service raises.
 */
function aggregateRouteFailingService(Throwable $failure): void
{
    $service = Mockery::mock(CrudService::class);
    $service->shouldReceive('aggregate')->once()->andThrow($failure);
    app()->instance(CrudService::class, $service);
}

it('mounts the route on the app and api surfaces, both behind the aggregate limiter', function (string $name, string $uri): void {
    $route = app('router')->getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe($uri)
        ->and($route->methods())->toContain('GET', 'POST')
        ->and(collect($route->gatherMiddleware())->contains(static fn (mixed $middleware): bool => is_string($middleware) && str_ends_with($middleware, ':aggregate')))->toBeTrue();
})->with([
    'app' => ['core.crud.aggregate', 'app/crud/aggregate/{module}/{entity}'],
    'api' => ['core.api.aggregate', 'api/v1/aggregate/{module}/{entity}'],
]);

it('counts settings by group_name', function (): void {
    aggregateRouteSetting('agg_route_a');
    aggregateRouteSetting('agg_route_a');
    aggregateRouteSetting('agg_route_b');

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'group_name'],
    ]);

    $response->assertOk();

    $by_key = collect($response->json('data.series'))->keyBy('key');

    expect($by_key['agg_route_a']['values']['count'])->toBe(2)
        ->and($by_key['agg_route_b']['values']['count'])->toBe(1)
        ->and($response->json('data.total.count'))->toBe(Setting::query()->count())
        ->and($response->json('meta.row_count'))->toBe(Setting::query()->count());
});

it('buckets settings by created_at over a period', function (): void {
    aggregateRouteSetting('agg_route_a', '2026-01-05 09:00:00');
    aggregateRouteSetting('agg_route_a', '2026-01-05 18:30:00');
    aggregateRouteSetting('agg_route_a', '2026-01-07 11:00:00');

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'created_at', 'bucket' => 'day'],
        'period' => ['field' => 'created_at', 'from' => '2026-01-05', 'to' => '2026-01-07'],
    ]);

    $response->assertOk();

    expect($response->json('data.series'))->toBe([
        ['key' => '2026-01-05', 'label' => null, 'values' => ['count' => 2]],
        ['key' => '2026-01-06', 'label' => null, 'values' => ['count' => 0]],
        ['key' => '2026-01-07', 'label' => null, 'values' => ['count' => 1]],
    ])
        ->and($response->json('data.total.count'))->toBe(3)
        ->and($response->json('meta.resolved_period.from'))->toBe('2026-01-05');
});

it('accepts the request as a JSON query string', function (): void {
    aggregateRouteSetting('agg_route_a', '2026-01-05 09:00:00');
    aggregateRouteSetting('agg_route_a', '2026-01-06 09:00:00');

    $response = $this->actingAs(aggregateRouteViewer())->getJson(
        route('core.crud.aggregate', aggregateRouteParams())
            . '?' . http_build_query([
                'measures' => json_encode([['fn' => 'count']]),
                'period' => json_encode(['field' => 'created_at', 'from' => '2026-01-05', 'to' => '2026-01-06']),
            ]),
    );

    $response->assertOk();

    expect($response->json('data.total.count'))->toBe(2)
        ->and($response->json('data.series'))->toBe([]);
});

it('denies the aggregate without the select permission on the entity', function (): void {
    $response = $this->actingAs(User::factory()->create())->postJson(
        route('core.crud.aggregate', aggregateRouteParams()),
        ['measures' => [['fn' => 'count']]],
    );

    $response->assertStatus(Response::HTTP_FORBIDDEN);

    expect($response->json('error'))->toBeString()
        ->and($response->json('data'))->toBe([]);
});

it('refuses a guest', function (): void {
    User::factory()->create([
        'name' => config('permission.users.guest'),
        'username' => config('permission.users.guest'),
        'email' => 'aggregate_guest_' . uniqid() . '@example.com',
    ]);

    $this->postJson(route('core.crud.aggregate', aggregateRouteParams()), ['measures' => [['fn' => 'count']]])
        ->assertUnauthorized();
});

it('answers a shape error with a structured 422', function (): void {
    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'median', 'field' => 'id']],
    ]);

    $response->assertUnprocessable();

    expect($response->json('error.code'))->toBe('UNSUPPORTED_FUNCTION')
        ->and($response->json('error.field'))->toBe('measures.0.fn')
        ->and($response->json('error.message'))->toBeString();
});

it('answers a request the validator refuses with INVALID_FIELD and does not report it', function (): void {
    // The real handler, not the Exceptions fake: the fake records any exception it is not told to skip, while the
    // handler asks the exception itself (its report() method) whether to log it.
    Log::spy();

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'group_by' => ['field' => 'group_name'],
    ]);

    $response->assertUnprocessable();

    expect($response->json('error.code'))->toBe('INVALID_FIELD')
        ->and($response->json('error.field'))->toBe('measures');

    Log::shouldNotHaveReceived('error');
});

it('reports an aggregate refusal only when it is a fault of the deployment', function (bool $reportable): void {
    Log::spy();
    $refusal = new AggregateException(AggregateErrorCode::UnsupportedFunction, 'Time buckets are not supported on the "firebird" database driver.', 'group_by.bucket', $reportable);

    app(ExceptionHandler::class)->report($refusal);

    if ($reportable) {
        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === $refusal->getMessage() && ($context['exception'] ?? null) === $refusal,
        );
    } else {
        Log::shouldNotHaveReceived('error');
    }
})->with([
    'a fault of the deployment' => [true],
    'a fault of the client' => [false],
]);

it('answers an unknown field with INVALID_FIELD', function (): void {
    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'no_such_column'],
    ]);

    $response->assertUnprocessable();

    expect($response->json('error.code'))->toBe('INVALID_FIELD')
        ->and($response->json('error.field'))->toBe('group_by.field');
});

it('answers an unreadable field with UNREADABLE_FIELD and does not report it', function (): void {
    Log::spy();
    aggregateRouteFailingService(new AggregateException(AggregateErrorCode::UnreadableField, '"group_name" cannot be read by this caller.', 'group_by.field'));

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'group_name'],
    ]);

    $response->assertUnprocessable();

    expect($response->json('error'))->toBe([
        'code' => 'UNREADABLE_FIELD',
        'message' => '"group_name" cannot be read by this caller.',
        'field' => 'group_by.field',
    ]);

    Log::shouldNotHaveReceived('error');
});

it('answers a fault of the deployment the engine raises with its 422 and reports it', function (): void {
    Log::spy();
    $fault = DayTruncation::unsupported('firebird');
    aggregateRouteFailingService($fault);

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
        'group_by' => ['field' => 'created_at', 'bucket' => 'month'],
        'period' => ['field' => 'created_at', 'preset' => 'this_year'],
    ]);

    $response->assertUnprocessable();

    expect($response->json('error'))->toBe([
        'code' => 'UNSUPPORTED_FUNCTION',
        'message' => 'Time buckets are not supported on the "firebird" database driver.',
        'field' => 'group_by.bucket',
    ]);

    Log::shouldHaveReceived('error')->once()->withArgs(
        static fn (string $message, array $context): bool => $message === $fault->getMessage() && ($context['exception'] ?? null) === $fault,
    );
});

it('answers a malformed scope filter with INVALID_FIELD on filters', function (): void {
    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
        'filters' => [['scope' => 'no_such_scope', 'parameters' => []]],
    ]);

    $response->assertUnprocessable();

    expect($response->json('error.code'))->toBe('INVALID_FIELD')
        ->and($response->json('error.field'))->toBe('filters');
});

it('refuses a relation filter node at any depth with INVALID_FIELD on filters', function (array $filters): void {
    aggregateRouteSetting('agg_route_a');

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
        'filters' => $filters,
    ]);

    $response->assertUnprocessable();

    expect($response->json('error'))->toBe([
        'code' => 'INVALID_FIELD',
        'message' => 'Relation filters are not supported on the aggregate route: filter on a dotted property such as "customer.country" instead.',
        'field' => 'filters',
    ]);
})->with([
    'at depth 0' => [[
        ['relation' => 'modifications', 'filters' => ['operator' => 'and', 'filters' => [['property' => 'id', 'operator' => '=', 'value' => 5]]]],
    ]],
    'nested in a group' => [[
        ['operator' => 'or', 'filters' => [
            ['property' => 'group_name', 'operator' => '=', 'value' => 'agg_route_a'],
            ['relation' => 'modifications', 'filters' => ['operator' => 'and', 'filters' => [['property' => 'id', 'operator' => '=', 'value' => 5]]]],
        ]],
    ]],
]);

it('still narrows the figures with a dotted relation property and with a model scope', function (array $filters): void {
    $valid = License::factory()->create(['valid_from' => now()->subDay(), 'valid_to' => null]);
    License::factory()->create(['valid_from' => now()->subWeeks(2), 'valid_to' => now()->subDay()]);
    User::factory()->create(['name' => 'aggregate_license_holder', 'license_id' => $valid->id]);

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', ['module' => 'core', 'entity' => 'licenses']), [
        'measures' => [['fn' => 'count']],
        'filters' => $filters,
    ]);

    $response->assertOk();

    expect($response->json('data.total.count'))->toBe(1);
})->with([
    'a dotted relation property' => [[['property' => 'user.name', 'operator' => '=', 'value' => 'aggregate_license_holder']]],
    'a model scope' => [[['scope' => 'valid']]],
]);

it('answers a scope filter the list data refuses with INVALID_FIELD on filters', function (): void {
    $request = Mockery::mock(AggregateRequest::class);
    $request->shouldReceive('parsed')->once()->andThrow(ValidationException::withMessages(['filters' => 'A scope filter names a scope and lists its parameters.']));

    $response = (new CrudController(Mockery::mock(CrudService::class)))->aggregate($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and(json_decode((string) $response->getContent(), true))->toBe([
            'error' => [
                'code' => 'INVALID_FIELD',
                'message' => 'A scope filter names a scope and lists its parameters.',
                'field' => 'filters',
            ],
        ]);
});

it('answers an invalid argument with INVALID_FIELD and its message, and logs a notice', function (): void {
    Log::spy();
    aggregateRouteFailingService(new InvalidArgumentException('"no_such_column" cannot be read: filters name only columns of the entity.'));

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
    ]);

    $response->assertUnprocessable();

    expect($response->json('error'))->toBe([
        'code' => 'INVALID_FIELD',
        'message' => '"no_such_column" cannot be read: filters name only columns of the entity.',
    ]);

    Log::shouldHaveReceived('notice')->once()->with('Aggregate request refused', [
        'exception' => InvalidArgumentException::class,
        'message' => '"no_such_column" cannot be read: filters name only columns of the entity.',
        'module' => 'core',
        'entity' => 'settings',
    ]);
    Log::shouldNotHaveReceived('error');
});

it('answers a query failure with a generic message and reports it', function (): void {
    Exceptions::fake();
    aggregateRouteFailingService(new QueryException(
        'sqlite',
        'select count(*) from "core_settings" where "secret_column" = ?',
        ['secret-binding'],
        new PDOException('SQLSTATE secret'),
    ));

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
    ]);

    $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

    expect($response->json('error'))->toBe(['message' => 'The aggregate could not be computed.'])
        ->and($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->getContent())->not->toContain('secret');

    Exceptions::assertReported(QueryException::class);
});

it('answers any other failure with the same generic message and reports it', function (): void {
    Exceptions::fake();
    aggregateRouteFailingService(new RuntimeException('failure in /srv/laraplate/internal.php'));

    $response = $this->actingAs(aggregateRouteViewer())->postJson(route('core.crud.aggregate', aggregateRouteParams()), [
        'measures' => [['fn' => 'count']],
    ]);

    $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

    expect($response->json('error'))->toBe(['message' => 'The aggregate could not be computed.'])
        ->and($response->getContent())->not->toContain('/srv/laraplate');

    Exceptions::assertReported(RuntimeException::class);
});

it('limits the rate', function (): void {
    config(['core.aggregate.rate_per_minute' => 2]);
    RateLimiter::clear('aggregate');

    $viewer = aggregateRouteViewer();
    $url = route('core.crud.aggregate', aggregateRouteParams());
    $body = ['measures' => [['fn' => 'count']]];

    $this->actingAs($viewer)->postJson($url, $body)->assertOk();
    $this->actingAs($viewer)->postJson($url, $body)->assertOk();
    $this->actingAs($viewer)->postJson($url, $body)->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
});

it('keys the limiter by user, and by address for a guest or a user of another kind', function (): void {
    config(['core.aggregate.rate_per_minute' => 7]);

    $limiter = RateLimiter::limiter('aggregate');
    $viewer = aggregateRouteViewer();
    $anonymous = User::factory()->create([
        'name' => config('permission.users.guest'),
        'username' => config('permission.users.guest'),
        'email' => 'aggregate_anonymous_' . uniqid() . '@example.com',
    ]);
    $other_kind = new FakeAuthUser(['name' => 'aggregate_other_kind', 'email' => 'aggregate_other_kind@example.com']);
    $other_kind->forceFill(['id' => 4242]);

    $requestOf = static function (?Authenticatable $user): Request {
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->setUserResolver(static fn (): ?Authenticatable => $user);

        return $request;
    };

    expect($limiter($requestOf($viewer))->key)->toBe($viewer->id)
        ->and($limiter($requestOf($anonymous))->key)->toBe('203.0.113.7')
        ->and($limiter($requestOf(null))->key)->toBe('203.0.113.7')
        ->and($limiter($requestOf($other_kind))->key)->toBe('203.0.113.7')
        ->and($limiter($requestOf($viewer))->maxAttempts)->toBe(7);

    config(['core.aggregate.rate_per_minute' => 9]);

    expect($limiter($requestOf($viewer))->maxAttempts)->toBe(9);
});
