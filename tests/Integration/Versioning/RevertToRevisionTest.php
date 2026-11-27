<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\RevertMode;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Locking\Exceptions\LockedModelException;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Tests\Stubs\Versioning\VersionedLockableArticle;
use Modules\Core\Versioning\Exceptions\RevertRefusedException;
use Modules\Core\Versioning\Exceptions\RevisionConflictException;
use Modules\Core\Versioning\Exceptions\VersionSetOptionsMismatchException;
use Modules\Core\Versioning\VersionStrategyCache;

/*
 * One revert implementation for both entry points (D56), every check under the root's lock (D48): the lock of D39,
 * the expected revision, the revision belonging to the record; a scalar revert skips the columns the table lost.
 */

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('subtitle')->nullable();
        $table->string('status')->default('draft');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create(VersionedLockableArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamp('locked_at')->nullable();
        $table->unsignedBigInteger('locked_user_id')->nullable();
        $table->timestamp('locked_until')->nullable();
        $table->timestamps();
    });
    Schema::create(TranslatedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('code')->nullable();
        $table->unsignedInteger('lock_version')->default(1);
        $table->timestamps();
    });
    Schema::create(TranslatedDocumentTranslation::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('translated_document_id')->constrained(TranslatedDocument::TABLE)->cascadeOnDelete();
        $table->string('locale');
        $table->string('title')->nullable();
        $table->string('body')->nullable();
        $table->timestamps();
    });
    Schema::create(VersionedRelationRoot::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create(VersionedRelationSubject::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create(VersionedRelationRoot::PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
        $table->unsignedInteger('position')->default(0);
    });
});

afterEach(function (): void {
    VersionStrategyCache::flush();
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
    Schema::dropIfExists(VersionedLockableArticle::TABLE);
    Schema::dropIfExists(VersionedDocument::TABLE);
});

function revert_set_count(): int
{
    return VersionSet::query()->where('kind', VersionSetKind::Revert)->count();
}

/**
 * A document edited twice: the revision of its creation and the current one.
 *
 * @return array{VersionedDocument, int, int}
 */
function revert_document(): array
{
    $document = VersionedDocument::query()->create(['title' => 'First', 'subtitle' => 'One']);
    $created = (int) $document->currentRevision();
    $document->update(['title' => 'Second', 'subtitle' => 'Two']);

    return [$document, $created, (int) $document->currentRevision()];
}

it('reverts the columns to a revision as one Revert set pointing at it', function (): void {
    [$document, $created, $current] = revert_document();

    $report = $document->revertToRevision($created, $current, RevertMode::Scalar);
    $set = VersionSet::query()->findOrFail($report->revision);

    expect($document->fresh()->only(['title', 'subtitle']))->toBe(['title' => 'First', 'subtitle' => 'One'])
        ->and($set->kind)->toBe(VersionSetKind::Revert)
        ->and((int) $set->reverted_from_set_id)->toBe($created)
        ->and($report->targetRevision)->toBe($created)
        ->and($report->mode)->toBe(RevertMode::Scalar)
        ->and($report->isPartial())->toBeFalse()
        ->and($report->isPending())->toBeFalse();
});

it('refuses a stale expected revision on both entry points, with nothing written', function (): void {
    [$document, $created, $current] = revert_document();
    $row = $document->versions()->where('version_set_id', $created)->whereNull('relation_path')->firstOrFail();
    $document->update(['title' => 'Third']);

    expect(fn () => $document->revertToRevision($created, $current, RevertMode::Scalar))->toThrow(RevisionConflictException::class)
        ->and(fn () => $document->revertToVersion($row->getKey(), $current))->toThrow(RevisionConflictException::class)
        ->and($document->fresh()->title)->toBe('Third')
        ->and(revert_set_count())->toBe(0);
});

it('reads the expected revision under the lock, so a write committed after the caller read it is not overwritten', function (): void {
    [$document, $created, $current] = revert_document();

    VersionedDocument::query()->findOrFail($document->getKey())->update(['title' => 'Somebody else']);

    expect(fn () => $document->revertToRevision($created, $current, RevertMode::Scalar))->toThrow(RevisionConflictException::class)
        ->and($document->fresh()->title)->toBe('Somebody else')
        ->and(revert_set_count())->toBe(0);
});

it('refuses a revert to the revision of a record locked by somebody else, with nothing written', function (): void {
    $article = VersionedLockableArticle::query()->create(['title' => 'First']);
    $created = (int) $article->currentRevision();
    $article->update(['title' => 'Second']);
    $article->lockBy(User::factory()->create(), now()->addHour());
    $this->actingAs(User::factory()->create());

    expect(fn () => $article->revertToRevision($created, (int) $article->currentRevision(), RevertMode::Scalar))->toThrow(LockedModelException::class)
        ->and($article->fresh()->title)->toBe('Second')
        ->and(revert_set_count())->toBe(0);
});

it('refuses a revert started inside another revision of the same record', function (): void {
    [$document, $created, $current] = revert_document();

    expect(fn () => $document->withinRevision(fn () => $document->revertToRevision($created, $current, RevertMode::Scalar)))
        ->toThrow(VersionSetOptionsMismatchException::class)
        ->and($document->fresh()->title)->toBe('Second');
});

it('reverts through a row of the record the same as through its revision', function (): void {
    [$document, $created, $current] = revert_document();
    $row = $document->versions()->where('version_set_id', $created)->whereNull('relation_path')->firstOrFail();
    [$twin, $twin_created, $twin_current] = revert_document();

    $document->revertToVersion($row->getKey(), $current);
    $twin->revertToRevision($twin_created, $twin_current, RevertMode::Scalar);

    expect($document->fresh()->only(['title', 'subtitle']))->toBe($twin->fresh()->only(['title', 'subtitle']));
});

it('refuses a revert through a translation row', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'first', 'title' => 'Title']);
    $row = $document->versions()->where('relation_path', 'translations')->firstOrFail();

    expect(fn () => $document->revertToVersion($row->getKey(), (int) $document->currentRevision()))
        ->toThrow(RevertRefusedException::class, 'The version is a translation or a link');
});

it('refuses a revert through a membership row', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $root->categories()->attach(VersionedRelationSubject::query()->create(['name' => 'A'])->getKey());
    $row = $root->versions()->where('relation_path', 'categories')->firstOrFail();

    expect(fn () => $root->revertToVersion($row->getKey(), (int) $root->currentRevision()))
        ->toThrow(RevertRefusedException::class, 'The version is a translation or a link');
});

it('refuses a revision of another record', function (): void {
    [$document, , $current] = revert_document();
    [, $other_created] = revert_document();

    expect(fn () => $document->revertToRevision($other_created, $current, RevertMode::Scalar))
        ->toThrow(RevertRefusedException::class, 'The revision is not one of this record.');
});

it('writes nothing for a revert to the current revision', function (): void {
    [$document, , $current] = revert_document();

    $report = $document->revertToRevision($current, $current, RevertMode::Scalar);

    expect($report->revision)->toBeNull()
        ->and(revert_set_count())->toBe(0)
        ->and($document->fresh()->title)->toBe('Second');
});

it('refuses a revert while versioning is off for the table, before any write', function (): void {
    [$document, $created, $current] = revert_document();
    $document->useVersionStrategy(null);

    expect(fn () => $document->revertToRevision($created, $current, RevertMode::Scalar))
        ->toThrow(RevertRefusedException::class, 'Versioning is off for this table')
        ->and($document->fresh()->title)->toBe('Second');
});

it('skips a column the table lost since the revision and restores the others', function (): void {
    [$document, $created, $current] = revert_document();
    Schema::table(VersionedDocument::TABLE, function (Blueprint $table): void {
        $table->dropColumn('subtitle');
    });
    $document = VersionedDocument::query()->findOrFail($document->getKey());

    $report = $document->revertToRevision($created, $current, RevertMode::Scalar);

    expect($document->fresh()->title)->toBe('First')
        ->and($report->skippedColumns)->toBe(['subtitle'])
        ->and($report->isPartial())->toBeTrue();
});
