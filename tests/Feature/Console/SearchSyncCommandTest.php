<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Tests\Stubs\Search\SyncSearchStubModel;
use Symfony\Component\Console\Command\Command as BaseCommand;

beforeEach(function (): void {
    SyncSearchStubModel::createTable();
    config(['scout.queue' => true, 'scout.soft_delete' => false]);
});

afterEach(function (): void {
    SyncSearchStubModel::dropTable();
});

it('queues every record for indexing when the index reports no last indexing time', function (): void {
    SyncSearchStubModel::withoutSyncingToSearch(static function (): void {
        SyncSearchStubModel::query()->create(['name' => 'first']);
        SyncSearchStubModel::query()->create(['name' => 'second']);
    });
    Event::fake([ModelRequiresIndexing::class]);

    $this->artisan('scout:sync', ['model' => SyncSearchStubModel::class])
        ->expectsOutputToContain('Found 2 documents to sync.')
        ->assertExitCode(BaseCommand::SUCCESS);

    Event::assertDispatchedTimes(ModelRequiresIndexing::class, 2);
});

it('removes a soft-deleted record from the index', function (): void {
    $record = SyncSearchStubModel::withoutSyncingToSearch(static function (): SyncSearchStubModel {
        $record = SyncSearchStubModel::query()->create(['name' => 'gone']);
        $record->delete();

        return $record;
    });
    Event::fake([ModelRequiresIndexing::class]);
    Queue::fake();

    $this->artisan('scout:sync', ['model' => SyncSearchStubModel::class, '--id' => $record->getKey()])
        ->assertExitCode(BaseCommand::SUCCESS);

    Queue::assertPushed(RemoveFromSearch::class);
    Event::assertNotDispatched(ModelRequiresIndexing::class);
});
