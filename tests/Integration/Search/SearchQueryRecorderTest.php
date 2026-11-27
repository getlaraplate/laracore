<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Models\License;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Search\Jobs\RecordSearchQueryJob;
use Modules\Core\Search\Services\SearchQueryRecorder;
use Modules\Core\Services\Crud\DTOs\CrudMeta;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Stubs\Search\ValidatedSearchRequestStub;

beforeEach(function (): void {
    config()->set('core.search.registry.enabled', true);
    config()->set('permission.roles.superadmin', 'superadmin');
});

function recorder_data(?User $user, string $qs = 'blue shoes'): SearchRequestData
{
    $request = new ValidatedSearchRequestStub(['qs' => $qs]);
    $request->setUserResolver(static fn (): ?User => $user);

    $data = (new ReflectionClass(SearchRequestData::class))->newInstanceWithoutConstructor();

    foreach (['request' => $request, 'model' => new License, 'qs' => $qs] as $property => $value) {
        (new ReflectionProperty($data, $property))->setValue($data, $value);
    }

    return $data;
}

function recorder_result(?string $error = null): CrudResult
{
    return new CrudResult([], new CrudMeta(totalRecords: 3), $error);
}

it('records the search of a guest', function (): void {
    Bus::fake();

    app(SearchQueryRecorder::class)->recordAfterResponse(recorder_data(null), recorder_result());

    Bus::assertDispatchedAfterResponse(RecordSearchQueryJob::class, static fn (RecordSearchQueryJob $job): bool => $job->hits === 3
        && $job->model === (new License)->getTable()
        && $job->query === 'blue shoes'
        && $job->day === now()->toDateString());
});

it('records the search of a user without the update permission of the model', function (): void {
    Bus::fake();

    app(SearchQueryRecorder::class)->recordAfterResponse(recorder_data(User::factory()->create()), recorder_result());

    Bus::assertDispatchedAfterResponse(RecordSearchQueryJob::class);
});

it('does not record the search of a user holding the update permission of the model', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findOrCreate(PermissionName::forModel(new License, 'update'), 'web'));

    app(SearchQueryRecorder::class)->recordAfterResponse(recorder_data($user), recorder_result());

    Bus::assertNotDispatched(RecordSearchQueryJob::class);
});

it('does not record the search of a superadmin', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('superadmin', 'web'));

    app(SearchQueryRecorder::class)->recordAfterResponse(recorder_data($user), recorder_result());

    Bus::assertNotDispatched(RecordSearchQueryJob::class);
});

it('records nothing while the registry is off', function (): void {
    Bus::fake();
    config()->set('core.search.registry.enabled', false);

    app(SearchQueryRecorder::class)->recordAfterResponse(recorder_data(null), recorder_result());

    Bus::assertNotDispatched(RecordSearchQueryJob::class);
});

it('records nothing for an errored result', function (): void {
    Bus::fake();

    app(SearchQueryRecorder::class)->recordAfterResponse(recorder_data(null), recorder_result('boom'));

    Bus::assertNotDispatched(RecordSearchQueryJob::class);
});

it('records nothing for a query that must not be stored', function (): void {
    Bus::fake();

    app(SearchQueryRecorder::class)->recordAfterResponse(recorder_data(null, 'jane@example.com'), recorder_result());

    Bus::assertNotDispatched(RecordSearchQueryJob::class);
});
