<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\SoftDeletedVersionedArticle;
use Modules\Core\Tests\Stubs\Versioning\VersionedArticle;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetRoot;

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedArticle::TABLE);
});

/**
 * Current production persistence entrypoints to migrate to the sole writer:
 *
 * - [x] HasVersions::createVersion()
 * - [x] HasVersions::createInitialVersion()
 * - [x] VersioningService::createVersion()
 * - [x] Version::createForModel()
 * - [x] the lifecycle callbacks of the absorbed library trait
 */
it('uses the Core writer for the initial lifecycle version', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);

    $versions = $article->versions()->orderBy('id')->get();
    $initial_version = $versions->first();

    expect($versions)->toHaveCount(1)
        ->and($initial_version)->toBeInstanceOf(Version::class)
        ->and($initial_version->contents)->toMatchArray(['title' => 'First'])
        ->and($initial_version->original_contents)->toBe([])
        ->and($initial_version->change_type)->toBe(VersionChangeType::Created)
        ->and($initial_version->version_strategy)->toBe(VersionStrategy::SNAPSHOT);
});

it('writes an unwrapped lifecycle change synchronously in an implicit singleton set', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $version_count_before_update = Version::query()->count();
    $set_count_before_update = VersionSet::query()->count();

    Bus::fake();
    $article->update(['title' => 'Second']);
    Bus::assertNothingDispatched();

    $updated_version = Version::query()->latest('id')->firstOrFail();

    expect(Version::query()->count() - $version_count_before_update)->toBe(1)
        ->and(VersionSet::query()->count() - $set_count_before_update)->toBe(1)
        ->and($updated_version->sequence)->toBe(1)
        ->and($updated_version->change_type)->toBe(VersionChangeType::Updated)
        ->and($updated_version->original_contents)->toBe(['title' => 'First'])
        ->and($updated_version->contents)->toBe(['title' => 'Second'])
        ->and(app(VersionSetManagerInterface::class)->current())->toBeNull();
});

it('orders multiple writes in one explicit version set', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $version_count_before_scope = Version::query()->count();

    app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($article),
        function () use ($article): void {
            $article->updateOrFail(['title' => 'Second']);
            $article->updateOrFail(['title' => 'Third']);
        },
    );

    $versions = Version::query()->latest('id')->take(2)->get()->sortBy('sequence')->values();

    expect(Version::query()->count() - $version_count_before_scope)->toBe(2)
        ->and($versions->pluck('version_set_id')->unique())->toHaveCount(1)
        ->and($versions->pluck('sequence')->all())->toBe([1, 2])
        ->and($versions->pluck('versionSet.uuid')->unique())->toHaveCount(1)
        ->and($versions[0]->original_contents)->toBe(['title' => 'First'])
        ->and($versions[0]->contents)->toBe(['title' => 'Second'])
        ->and($versions[1]->original_contents)->toBe(['title' => 'Second'])
        ->and($versions[1]->contents)->toBe(['title' => 'Third']);
});

it('rolls back a lifecycle write with its explicit business transaction', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $version_count = Version::query()->count();
    $set_count = VersionSet::query()->count();

    expect(fn () => app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($article),
        function () use ($article): void {
            $article->updateOrFail(['title' => 'Second']);

            throw new RuntimeException('abort');
        },
    ))->toThrow(RuntimeException::class);

    expect($article->fresh()->title)->toBe('First')
        ->and(Version::query()->count())->toBe($version_count)
        ->and(VersionSet::query()->count())->toBe($set_count);
});

it('captures full before and after images for snapshot updates', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $article->useVersionStrategy(VersionStrategy::SNAPSHOT);

    $article->updateOrFail(['title' => 'Second']);

    $version = Version::query()->latest('id')->firstOrFail();

    expect($version->version_strategy)->toBe(VersionStrategy::SNAPSHOT)
        ->and($version->original_contents)->toBe(['title' => 'First'])
        ->and($version->contents)->toBe(['title' => 'Second']);
});

it('encrypts sensitive values in both sides of an update', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'Before secret']);
    $article->encryptVersionAttributes(['title']);

    $article->updateOrFail(['title' => 'After secret']);

    $version = Version::query()->latest('id')->firstOrFail();

    expect($version->original_contents['title'])->not->toBe('Before secret')
        ->and($version->contents['title'])->not->toBe('After secret');
});

it('captures deletion before the row disappears and keeps only its snapshot on force delete (D17)', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);
    $article->updateOrFail(['title' => 'Second']);

    $article->forceDelete();

    $deletion = Version::query()->sole();

    expect($deletion->change_type)->toBe(VersionChangeType::Deleted)
        ->and($deletion->original_contents)->toBe(['title' => 'Second'])
        ->and($deletion->contents)->toBe([]);
});

it('records restore as a restored row of deleted_at without a duplicate lifecycle row', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);
    $article->delete();
    $version_count = Version::query()->count();

    $article->restore();

    $restore = Version::query()->latest('id')->firstOrFail();

    expect(Version::query()->count())->toBe($version_count + 1)
        ->and($restore->change_type)->toBe(VersionChangeType::Restored)
        ->and($restore->original_contents)->toHaveKey('deleted_at')
        ->and($restore->contents)->toHaveKey('deleted_at', null);
});
