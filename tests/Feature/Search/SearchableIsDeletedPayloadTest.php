<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Tests\Stubs\Search\SoftDeletableSearchStubModel;

/**
 * The search mapping declares `is_deleted` as boolean, and Elasticsearch rejects
 * the 0/1 integer a database returns for it with a document_parsing_exception,
 * so every index job for a soft-deletable model failed. The payload must carry a
 * real boolean, as read back from the stored column.
 */
beforeEach(function (): void {
    Schema::create('core_test_soft_deletable_search_stub', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('deleted_at')->nullable();
        $table->boolean('is_deleted')->storedAs('deleted_at IS NOT NULL');
    });
});

it('emits is_deleted as the boolean false for a live row', function (): void {
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('offersVectorSearch')->andReturn(false);

    $id = DB::table('core_test_soft_deletable_search_stub')->insertGetId(['deleted_at' => null]);
    $model = SoftDeletableSearchStubModel::query()->findOrFail($id)->withEngine($engine);

    expect($model->toSearchableArray()['is_deleted'])->toBeFalse();
});

it('emits is_deleted as the boolean true for a soft-deleted row', function (): void {
    $engine = Mockery::mock(ISearchEngine::class);
    $engine->shouldReceive('offersVectorSearch')->andReturn(false);

    $id = DB::table('core_test_soft_deletable_search_stub')->insertGetId(['deleted_at' => now()]);
    $model = SoftDeletableSearchStubModel::withoutGlobalScopes()->findOrFail($id)->withEngine($engine);

    expect($model->toSearchableArray()['is_deleted'])->toBeTrue();
});
