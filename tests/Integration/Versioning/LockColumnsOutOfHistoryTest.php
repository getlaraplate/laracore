<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Tests\Stubs\Versioning\VersionedLockableDocument;

/**
 * The lock columns are coordination state, not part of the record: they stay out of every version row, so a
 * revert neither brings back a stale lock nor drops a live one (D21).
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

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
    Schema::dropIfExists(VersionedLockableDocument::TABLE);
});

it('keeps the lock columns out of every version row', function (): void {
    $holder = User::factory()->create();
    $document = VersionedLockableDocument::query()->create(['title' => 'First']);
    $document->lockBy($holder, now()->addHour());
    $this->actingAs($holder);

    $document->update(['title' => 'Second']);

    $columns = $document->versions()->get()->flatMap(static fn (Version $version): array => array_keys($version->contents ?? []))->unique()->all();

    expect($columns)->not->toContain('locked_at')
        ->and($columns)->not->toContain('locked_user_id')
        ->and($columns)->not->toContain('locked_until');
});

it('leaves a live lock in place when the holder reverts', function (): void {
    $holder = User::factory()->create();
    $document = VersionedLockableDocument::query()->create(['title' => 'First']);
    $created = $document->versions()->firstOrFail();
    $document->lockBy($holder, now()->addHour());
    $this->actingAs($holder);
    $document->update(['title' => 'Second']);

    $document->revertToVersion($created->getKey(), (int) $document->currentRevision());

    $live = $document->fresh();

    expect($live->title)->toBe('First')
        ->and($live->locked_user_id)->toBe($holder->getKey())
        ->and($live->locked_at)->not->toBeNull();
});
