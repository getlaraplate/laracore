<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Search\Contracts\ISearchMaintenanceGuard;
use Modules\Core\Search\Jobs\ReindexSearchJob;
use Modules\Core\Tests\Stubs\Search\BlockedSearchMaintenanceGuardStub;
use Modules\Core\Tests\Stubs\Search\RebuildArticleStubModel;
use Modules\Core\Tests\Stubs\Search\RebuildEventStubModel;
use Modules\Core\Tests\Stubs\Search\RebuildRecordingEngineStub;
use Modules\Core\Tests\Stubs\Search\RebuildSearchStubModel;
use Modules\Core\Tests\Stubs\Search\VectorMappingStubModel;
use Symfony\Component\Console\Command\Command as BaseCommand;

const REBUILD_QUESTION = 'Delete, recreate and re-import the indexes listed above?';

/**
 * Binds one engine per stub model and makes them the searchable models of the application.
 *
 * @param  array<class-string<RebuildSearchStubModel>, RebuildRecordingEngineStub>  $engines
 */
function bindRebuildEngines(array $engines): void
{
    foreach ($engines as $model => $engine) {
        $model::bindEngine($engine);
    }

    HelpersCache::setModels('active', array_keys($engines));
}

/**
 * @return array{article: RebuildRecordingEngineStub, event: RebuildRecordingEngineStub}
 */
function bindUnpatchableRebuildEngines(): array
{
    $article = new RebuildRecordingEngineStub(mismatches: ['title: text in the model, object in the index'], sync_result: false);
    $event = new RebuildRecordingEngineStub(mismatches: ['starts_at: date in the model, keyword in the index'], sync_result: false);

    bindRebuildEngines([
        RebuildArticleStubModel::class => $article,
        RebuildEventStubModel::class => $event,
    ]);

    return ['article' => $article, 'event' => $event];
}

beforeEach(function (): void {
    Schema::create(RebuildSearchStubModel::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
    DB::table(RebuildSearchStubModel::TABLE)->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

    RebuildSearchStubModel::forgetEngines();
    HelpersCache::clearModels();
});

afterEach(function (): void {
    RebuildSearchStubModel::forgetEngines();
    HelpersCache::clearModels();
});

it('skips an index already in sync without touching it', function (): void {
    $engine = new RebuildRecordingEngineStub;
    bindRebuildEngines([RebuildArticleStubModel::class => $engine]);

    $this->artisan('scout:rebuild')
        ->expectsOutputToContain('Skipped ' . RebuildArticleStubModel::class . ': its index is already in sync.')
        ->expectsTable(['Model', 'Outcome'], [[RebuildArticleStubModel::class, 'skipped']])
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($engine->calls)->toBe([]);
});

it('syncs the mapping and reindexes without asking when that brings the index in sync', function (): void {
    $engine = new RebuildRecordingEngineStub(mismatches: ["sub-field 'title.it.exact' is missing"]);
    bindRebuildEngines([RebuildArticleStubModel::class => $engine]);

    $this->artisan('scout:rebuild')
        ->doesntExpectOutputToContain('must be rebuilt')
        ->expectsTable(['Model', 'Outcome'], [[RebuildArticleStubModel::class, 'synced']])
        ->expectsOutputToContain('Embeddings are not touched')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($engine->calls)->toBe(['syncMapping', 'reindex']);
});

it('asks once for every model whose mapping cannot be patched and deletes nothing when declined', function (): void {
    ['article' => $article, 'event' => $event] = bindUnpatchableRebuildEngines();

    $this->artisan('scout:rebuild')
        ->expectsOutputToContain('index "core_test_rebuild_articles", 3 records to import')
        ->expectsOutputToContain('index "core_test_rebuild_events", 3 records to import')
        ->expectsOutputToContain('returns nothing from the delete of their index until their import ends')
        ->expectsConfirmation(REBUILD_QUESTION, 'no')
        ->expectsTable(['Model', 'Outcome'], [
            [RebuildArticleStubModel::class, 'failed'],
            [RebuildEventStubModel::class, 'failed'],
        ])
        ->assertExitCode(BaseCommand::FAILURE);

    expect($article->calls)->toBe(['syncMapping'])
        ->and($event->calls)->toBe(['syncMapping']);
});

it('deletes, creates and imports each failed index in order once confirmed', function (): void {
    ['article' => $article, 'event' => $event] = bindUnpatchableRebuildEngines();

    $this->artisan('scout:rebuild')
        ->expectsConfirmation(REBUILD_QUESTION, 'yes')
        ->expectsTable(['Model', 'Outcome'], [
            [RebuildArticleStubModel::class, 'rebuilt'],
            [RebuildEventStubModel::class, 'rebuilt'],
        ])
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($article->calls)->toBe(['syncMapping', 'deleteIndex:core_test_rebuild_articles', 'createIndex:core_test_rebuild_articles', 'reindex'])
        ->and($event->calls)->toBe(['syncMapping', 'deleteIndex:core_test_rebuild_events', 'createIndex:core_test_rebuild_events', 'reindex']);
});

it('rebuilds without asking with --force', function (): void {
    ['article' => $article] = bindUnpatchableRebuildEngines();

    $this->artisan('scout:rebuild', ['--force' => true])
        ->expectsTable(['Model', 'Outcome'], [
            [RebuildArticleStubModel::class, 'rebuilt'],
            [RebuildEventStubModel::class, 'rebuilt'],
        ])
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($article->calls)->toContain('deleteIndex:core_test_rebuild_articles');
});

it('does not rebuild in a non-interactive run without --force', function (): void {
    ['article' => $article, 'event' => $event] = bindUnpatchableRebuildEngines();

    $this->artisan('scout:rebuild', ['--no-interaction' => true])
        ->expectsOutputToContain('Not rebuilding without confirmation in a non-interactive run: pass --force to rebuild.')
        ->assertExitCode(BaseCommand::FAILURE);

    expect($article->calls)->toBe(['syncMapping'])
        ->and($event->calls)->toBe(['syncMapping']);
});

it('changes nothing on a dry run', function (): void {
    ['article' => $article, 'event' => $event] = bindUnpatchableRebuildEngines();

    $this->artisan('scout:rebuild', ['--dry-run' => true])
        ->expectsOutputToContain('Would sync the mapping, reindex ' . RebuildArticleStubModel::class)
        ->expectsOutputToContain('index "core_test_rebuild_articles", 3 records to import')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertExitCode(BaseCommand::SUCCESS);

    expect($article->calls)->toBe([])
        ->and($event->calls)->toBe([]);
});

it('counts a model still out of sync after the reindex as failed', function (): void {
    $engine = new RebuildRecordingEngineStub(mismatches: ['title: text in the model, object in the index'], sync_fixes: false);
    bindRebuildEngines([RebuildArticleStubModel::class => $engine]);

    $this->artisan('scout:rebuild')
        ->expectsOutputToContain('is still out of sync after the reindex')
        ->expectsConfirmation(REBUILD_QUESTION, 'no')
        ->assertExitCode(BaseCommand::FAILURE);

    expect($engine->calls)->toBe(['syncMapping', 'reindex']);
});

it('counts a reindex that throws as failed', function (): void {
    $engine = new RebuildRecordingEngineStub(mismatches: ['title: missing'], reindex_failure: new RuntimeException('engine refused the write'));
    bindRebuildEngines([RebuildArticleStubModel::class => $engine]);

    $this->artisan('scout:rebuild', ['--no-interaction' => true])
        ->expectsOutputToContain('Reindex of ' . RebuildArticleStubModel::class . ' failed (RuntimeException)')
        ->assertExitCode(BaseCommand::FAILURE);

    expect($engine->calls)->toBe(['syncMapping', 'reindex']);
});

it('only queues the reindex with --queue and says the result is unverified', function (): void {
    Queue::fake();
    $engine = new RebuildRecordingEngineStub(mismatches: ["sub-field 'title.it.exact' is missing"]);
    bindRebuildEngines([RebuildArticleStubModel::class => $engine]);

    $this->artisan('scout:rebuild', ['--queue' => true])
        ->expectsOutputToContain('Reindex of ' . RebuildArticleStubModel::class . ' queued; its result is not verified.')
        ->expectsTable(['Model', 'Outcome'], [[RebuildArticleStubModel::class, 'queued (unverified)']])
        ->assertExitCode(BaseCommand::SUCCESS);

    Queue::assertPushed(ReindexSearchJob::class, 1);
    expect($engine->calls)->toBe(['syncMapping']);
});

it('refuses to start while the maintenance guard blocks it', function (): void {
    $engine = new RebuildRecordingEngineStub(mismatches: ['title: missing'], sync_result: false);
    bindRebuildEngines([RebuildArticleStubModel::class => $engine]);
    app()->instance(ISearchMaintenanceGuard::class, new BlockedSearchMaintenanceGuardStub('an embedding model switch is in progress'));

    $this->artisan('scout:rebuild', ['--force' => true])
        ->expectsOutputToContain('Search index maintenance is blocked: an embedding model switch is in progress')
        ->assertExitCode(BaseCommand::FAILURE);

    expect($engine->calls)->toBe([]);
});

it('skips a model whose engine keeps no index', function (): void {
    config(['scout.driver' => 'database']);

    $this->artisan('scout:rebuild', ['model' => VectorMappingStubModel::class])
        ->expectsOutputToContain('Skipped ' . VectorMappingStubModel::class . ': its search engine keeps no index of its own.')
        ->assertExitCode(BaseCommand::SUCCESS);
});
