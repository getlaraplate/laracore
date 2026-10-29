<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Locking\Exceptions\LockedModelException;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Tests\Stubs\Versioning\VersionedArticle;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Tests\Stubs\Versioning\VersionedLockableDocument;

/**
 * `delete()` and `forceDelete()` of every versioned model run inside the revision scope of the record: the delete,
 * the events it fires and their history commit together, and a delete refused or failed leaves everything as it was
 * (D44).
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create(VersionedLockableDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamp('locked_at')->nullable();
        $table->unsignedBigInteger('locked_user_id')->nullable();
        $table->timestamp('locked_until')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    app(PerModelSettingResolver::class)->flush();
    User::resetVersionStrategyCache();
    Schema::dropIfExists(VersionedLockableDocument::TABLE);
    Schema::dropIfExists(VersionedArticle::TABLE);
    Schema::dropIfExists(VersionedDocument::TABLE);
});

function sets_after(int $after): Illuminate\Support\Collection
{
    return VersionSet::query()->where('id', '>', $after)->orderBy('id')->get();
}

it('writes a move to the bin in one atomic set', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'First']);
    $before = (int) VersionSet::query()->max('id');

    $document->delete();

    $set = sets_after($before)->sole();

    expect($set->capture)->toBe(VersionCaptureQuality::Atomic)
        ->and($set->versions()->pluck('change_type')->all())->toBe([VersionChangeType::Trashed]);
});

it('writes the hard delete of a model without a bin in one atomic set', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $before = (int) VersionSet::query()->max('id');

    $article->delete();

    expect(sets_after($before)->sole()->capture)->toBe(VersionCaptureQuality::Atomic);
});

it('writes the force delete of a model with a bin in one atomic set', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'First']);
    $before = (int) VersionSet::query()->max('id');

    $document->forceDelete();

    expect(sets_after($before)->sole()->capture)->toBe(VersionCaptureQuality::Atomic)
        ->and(VersionedDocument::withTrashed()->find($document->getKey()))->toBeNull();
});

it('writes the delete of a user, which is not a Core model, in one atomic set', function (): void {
    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => PerModelSettingResolver::nameFor(CoreDatabaseSeeder::VERSIONING_NAME_PREFIX, (new User)->getTable()),
        'value' => 'DIFF',
        'type' => SettingTypeEnum::Json,
        'group_name' => 'versioning',
        'description' => 'Versioning switched on for the test',
    ]);
    app(PerModelSettingResolver::class)->flush();
    User::resetVersionStrategyCache();
    $user = User::factory()->create();
    $before = (int) VersionSet::query()->max('id');

    $user->delete();

    expect($user->trashed())->toBeTrue();

    $user->forceDelete();

    expect(sets_after($before)->pluck('capture')->unique()->all())->toBe([VersionCaptureQuality::Atomic])
        ->and(User::withTrashed()->find($user->getKey()))->toBeNull();
});

it('writes nothing and removes nothing when the lock refuses the delete', function (): void {
    $document = VersionedLockableDocument::query()->create(['title' => 'First']);
    $document->lockBy(User::factory()->create(), now()->addHour());
    $this->actingAs(User::factory()->create());
    $before = (int) VersionSet::query()->max('id');

    expect(fn () => $document->delete())->toThrow(LockedModelException::class);

    expect(sets_after($before))->toHaveCount(0)
        ->and(VersionedLockableDocument::query()->find($document->getKey()))->not->toBeNull();
});

it('rolls back the delete and its history when a deleted handler fails', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $before = (int) VersionSet::query()->max('id');
    VersionedArticle::deleted(static function (): void {
        throw new RuntimeException('deleted handler failed');
    });

    expect(fn () => $article->delete())->toThrow(RuntimeException::class);

    expect(VersionedArticle::query()->find($article->getKey()))->not->toBeNull()
        ->and(sets_after($before))->toHaveCount(0);
});
