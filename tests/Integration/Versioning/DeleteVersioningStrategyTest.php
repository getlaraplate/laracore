<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Version;
use Modules\Core\Tests\Stubs\Versioning\HardDeletedVersionedArticle;
use Modules\Core\Tests\Stubs\Versioning\SoftDeletedVersionedArticle;
use Modules\Core\Tests\Stubs\Versioning\VersionedArticle;

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
        $table->boolean('is_deleted')->default(false);
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedArticle::TABLE);
});

it('records a hard delete as a self-contained snapshot tombstone', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);

    $article->forceDelete();

    $tombstone = Version::query()->latest('id')->firstOrFail();

    expect($tombstone->change_type)->toBe(VersionChangeType::Deleted)
        ->and($tombstone->version_strategy)->toBe(VersionStrategy::SNAPSHOT)
        ->and($tombstone->original_contents)->toBe(['title' => 'First'])
        ->and($tombstone->contents)->toBe([]);
});

it('records a delete as a snapshot tombstone when soft deletes are switched off', function (): void {
    $article = HardDeletedVersionedArticle::query()->create(['title' => 'First']);

    $article->delete();

    $tombstone = Version::query()->latest('id')->firstOrFail();

    expect(HardDeletedVersionedArticle::query()->withoutGlobalScopes()->whereKey($article->getKey())->exists())->toBeFalse()
        ->and($tombstone->change_type)->toBe(VersionChangeType::Deleted)
        ->and($tombstone->version_strategy)->toBe(VersionStrategy::SNAPSHOT)
        ->and($tombstone->original_contents)->toBe(['title' => 'First'])
        ->and($tombstone->contents)->toBe([]);
});

it('records a soft delete as a trashed row of deleted_at, not a Deleted row', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);

    $article->delete();

    $trashing = Version::query()->where('change_type', VersionChangeType::Trashed)->latest('id')->firstOrFail();

    expect($trashing->change_type)->toBe(VersionChangeType::Trashed)
        ->and($trashing->original_contents)->toBe(['deleted_at' => null])
        ->and($trashing->contents)->toHaveKey('deleted_at')
        ->and($trashing->contents['deleted_at'])->not->toBeNull();
});

it('exposes trashing versions and excludes the restore that clears deleted_at', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);
    $article->delete();
    $trashing_version_id = Version::query()->where('change_type', VersionChangeType::Trashed)->latest('id')->firstOrFail()->getKey();
    $article->restore();

    $trashing_versions = $article->trashingVersions()->get();

    expect($trashing_versions)->toHaveCount(1)
        ->and($trashing_versions->first()->getKey())->toBe($trashing_version_id);
});

it('records a soft delete of a SNAPSHOT model as a DIFF of deleted_at', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);
    $article->useVersionStrategy(VersionStrategy::SNAPSHOT);

    $article->delete();

    $trashing = Version::query()->where('change_type', VersionChangeType::Trashed)->latest('id')->firstOrFail();

    expect($trashing->change_type)->toBe(VersionChangeType::Trashed)
        ->and($trashing->version_strategy)->toBe(VersionStrategy::DIFF)
        ->and(array_keys($trashing->contents))->toBe(['deleted_at']);
});
