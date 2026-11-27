<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\Core\Search\Jobs\RecordSearchQueryJob;
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
