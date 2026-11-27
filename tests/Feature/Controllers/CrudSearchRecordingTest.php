<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Core\Search\Jobs\RecordSearchQueryJob;
use Modules\Core\Search\Services\SearchQueryRecorder;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\CrudMeta;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Support\CrudApiExposure;

it('records the search of a guest on both surfaces', function (string $uri): void {
    CrudApiExposure::enable();

    $service = Mockery::mock(CrudService::class);
    $service->shouldReceive('search')->once()->andReturn(new CrudResult(collect(), new CrudMeta(totalRecords: 3)));
    app()->instance(CrudService::class, $service);

    config()->set('core.search.registry.enabled', true);
    Bus::fake();

    $this->getJson($uri . '/search/core/cron_jobs?' . http_build_query(['qs' => 'job']))->assertOk();

    Bus::assertDispatchedAfterResponse(RecordSearchQueryJob::class, 1);
})->with([
    'api' => '/api/v1',
    'app' => '/app/crud',
]);

it('still answers the search when the recorder cannot be resolved or fails', function (string $failure): void {
    CrudApiExposure::enable();
    config()->set('core.search.registry.enabled', true);
    Bus::fake();

    $service = Mockery::mock(CrudService::class);
    $service->shouldReceive('search')->once()->andReturn(new CrudResult(collect(), new CrudMeta(totalRecords: 3)));
    app()->instance(CrudService::class, $service);

    if ($failure === 'resolution') {
        app()->bind(SearchQueryRecorder::class, static fn () => throw new RuntimeException('cannot resolve'));
    } else {
        $authorization = Mockery::mock(AuthorizationService::class)->makePartial();
        $authorization->shouldReceive('userHolds')->andThrow(new QueryException('sqlite', 'select 1', [], new RuntimeException('db down')));
        app()->instance(AuthorizationService::class, $authorization);
        $this->actingAs(User::factory()->create());
    }

    $this->getJson('/api/v1/search/core/cron_jobs?' . http_build_query(['qs' => 'job']))->assertOk();

    Bus::assertNotDispatched(RecordSearchQueryJob::class);
})->with(['resolution', 'decision']);
