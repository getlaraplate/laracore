<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Helpers\HelpersCache;
use Modules\Core\Models\RecordOrigin;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\SoftDeletedVersionedArticle;
use Modules\Core\Tests\Stubs\Versioning\VersionedArticle;

/**
 * `model:clear-expired` deletes each expired record of the bin through the coordinator, like any hard delete (D29):
 * the record keeps one snapshot set, with reason `expired`, and its origin leaves with it.
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);
    config()->set('core.soft_deletes.expiration_days', 5);

    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });

    HelpersCache::setModels('active', [SoftDeletedVersionedArticle::class]);
});

afterEach(function (): void {
    HelpersCache::clearModels();
    Schema::dropIfExists(VersionedArticle::TABLE);
});

it('leaves an expired record one snapshot set with reason expired, its origin kept only there', function (): void {
    $expired = SoftDeletedVersionedArticle::query()->create(['title' => 'Expired']);
    $origin = RecordOrigin::factory()->create([
        'referable_type' => $expired->getMorphClass(),
        'referable_id' => $expired->getKey(),
    ]);
    $expired->title = 'Edited';
    $expired->save();
    $expired->delete();
    SoftDeletedVersionedArticle::withTrashed()->whereKey($expired->getKey())->update(['deleted_at' => now()->subDays(10)]);
    $recent = SoftDeletedVersionedArticle::query()->create(['title' => 'Recent']);
    $recent->delete();

    $this->artisan('model:clear-expired')->assertSuccessful();

    $sets = VersionSet::query()
        ->where('root_type', $expired->getMorphClass())
        ->where('root_id', (string) $expired->getKey())
        ->get();
    $rows = $sets->sole()->versions()->reorder('sequence')->get();
    $snapshot = $rows->first();

    expect(SoftDeletedVersionedArticle::withTrashed()->whereKey($expired->getKey())->exists())->toBeFalse()
        ->and($sets->sole()->reason)->toBe('expired')
        ->and($rows->pluck('relation_path')->all())->toBe([null, 'origin'])
        ->and($snapshot->change_type)->toBe(VersionChangeType::Deleted)
        ->and($snapshot->original_contents)->toMatchArray(['title' => 'Edited'])
        ->and(RecordOrigin::query()->find($origin->getKey()))->toBeNull()
        ->and(SoftDeletedVersionedArticle::withTrashed()->whereKey($recent->getKey())->exists())->toBeTrue();
});
