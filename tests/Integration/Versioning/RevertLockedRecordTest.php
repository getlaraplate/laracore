<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Locking\Exceptions\LockedModelException;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\VersionedLockableArticle;

/**
 * A locked record is being edited by its holder, so a revert is one more write on it: the holder may revert,
 * anybody else, and everybody on a frozen record, is refused until the lock is lifted.
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedLockableArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamp('locked_at')->nullable();
        $table->unsignedBigInteger('locked_user_id')->nullable();
        $table->timestamp('locked_until')->nullable();
        $table->timestamps();
    });

    $this->article = VersionedLockableArticle::query()->create(['title' => 'First']);
    $this->article->update(['title' => 'Second']);
    $this->target = $this->article->versions()->orderBy('id')->firstOrFail();
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedLockableArticle::TABLE);
});

function revert_sets(): int
{
    return VersionSet::query()->where('kind', VersionSetKind::Revert)->count();
}

it('refuses a revert of a record locked by somebody else, before any write', function (): void {
    $this->article->lockBy(User::factory()->create(), now()->addHour());
    $this->actingAs(User::factory()->create());

    expect(fn () => $this->article->revertToVersion($this->target->getKey(), (int) $this->article->currentRevision()))->toThrow(LockedModelException::class)
        ->and($this->article->fresh()->title)->toBe('Second')
        ->and(revert_sets())->toBe(0);
});

it('refuses a revert of a frozen record to everybody', function (): void {
    $this->article->lock();
    $this->actingAs(User::factory()->create());

    expect(fn () => $this->article->revertToVersion($this->target->getKey(), (int) $this->article->currentRevision()))->toThrow(LockedModelException::class)
        ->and($this->article->fresh()->title)->toBe('Second');
});

it('refuses a revert that would leave the record row unchanged, since the rest of the record would change', function (): void {
    $this->article->update(['title' => 'First']);
    $this->article->lockBy(User::factory()->create(), now()->addHour());
    $this->actingAs(User::factory()->create());

    expect(fn () => $this->article->revertToVersion($this->target->getKey(), (int) $this->article->currentRevision()))->toThrow(LockedModelException::class)
        ->and(revert_sets())->toBe(0);
});

it('refuses a revert when the lock was taken after the record was loaded', function (): void {
    VersionedLockableArticle::query()->findOrFail($this->article->getKey())->lockBy(User::factory()->create(), now()->addHour());
    $this->actingAs(User::factory()->create());

    expect(fn () => $this->article->revertToVersion($this->target->getKey(), (int) $this->article->currentRevision()))->toThrow(LockedModelException::class)
        ->and($this->article->fresh()->title)->toBe('Second');
});

it('lets the holder of the lease revert', function (): void {
    $holder = User::factory()->create();
    $this->article->lockBy($holder, now()->addHour());
    $this->actingAs($holder);

    $this->article->revertToVersion($this->target->getKey(), (int) $this->article->currentRevision());

    expect($this->article->fresh()->title)->toBe('First')
        ->and(revert_sets())->toBe(1);
});

it('reverts once the lock has been forcibly lifted', function (): void {
    $this->article->lockBy(User::factory()->create(), now()->addHour());
    $this->article->forceUnlock();
    $this->actingAs(User::factory()->create());

    $this->article->revertToVersion($this->target->getKey(), (int) $this->article->currentRevision());

    expect($this->article->fresh()->title)->toBe('First');
});
