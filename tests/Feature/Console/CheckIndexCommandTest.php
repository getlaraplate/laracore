<?php

declare(strict_types=1);

use Modules\Core\Tests\Stubs\Search\RebuildArticleStubModel;
use Modules\Core\Tests\Stubs\Search\RebuildRecordingEngineStub;
use Modules\Core\Tests\Stubs\Search\VectorMappingStubModel;
use Symfony\Component\Console\Command\Command as BaseCommand;

it('checks a searchable model index via the engine without a fatal undefined-method error', function (): void {
    // Regression: the command used to call $model->checkIndex() on the model,
    // which does not exist (that method lives on the engine, e.g.
    // ElasticsearchEngine::checkIndex), producing a fatal "Call to undefined
    // method ...::checkIndex()". It now delegates to the engine when the method
    // is callable, and treats engines without it (the database engine used here)
    // as valid.
    config(['scout.driver' => 'database']);

    $this->artisan('scout:check-index', ['model' => VectorMappingStubModel::class])
        ->assertExitCode(BaseCommand::SUCCESS);
});

it('lists the mapping mismatches the engine reports and fails', function (): void {
    RebuildArticleStubModel::bindEngine(new RebuildRecordingEngineStub(mismatches: ['title: text in the model, object in the index']));

    try {
        $this->artisan('scout:check-index', ['model' => RebuildArticleStubModel::class])
            ->expectsOutputToContain('Model ' . RebuildArticleStubModel::class . ' has a stale index structure')
            ->expectsOutputToContain('  - title: text in the model, object in the index')
            ->assertExitCode(BaseCommand::FAILURE);
    } finally {
        RebuildArticleStubModel::forgetEngines();
    }
});
