<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\VersionedArticle;

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedArticle::TABLE);
});

it('marks a revert as a Revert version set pointing at the restored set', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $article->update(['title' => 'Second']);

    $target = $article->versions()->orderBy('id')->firstOrFail();

    $article->revertToVersion($target->getKey(), (int) $article->currentRevision());

    $revert_version = Version::query()->latest('id')->firstOrFail();
    $revert_set = VersionSet::query()->findOrFail($revert_version->version_set_id);

    expect($article->fresh()->title)->toBe('First')
        ->and($revert_set->kind)->toBe(VersionSetKind::Revert)
        ->and((int) $revert_set->reverted_from_set_id)->toBe((int) $target->version_set_id);
});
