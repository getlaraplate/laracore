<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\RevertMode;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Versioning\Exceptions\MissingRevertSubjectException;
use Modules\Core\Versioning\Exceptions\RevertRefusedException;
use Modules\Core\Versioning\Exceptions\RevisionConflictException;

/*
 * The link revert puts back the membership of the registered `reference` relations as of a revision, replayed from
 * the record's latest complete anchor, and never touches the related records (D25, D45 rule 2).
 */

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

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
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
});

it('reverts the membership of the reference relations and leaves the columns and the related records alone', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $b = VersionedRelationSubject::query()->create(['name' => 'B']);
    $c = VersionedRelationSubject::query()->create(['name' => 'C']);

    $root->categories()->attach($a->getKey(), ['position' => 1]);
    $root->categories()->attach($b->getKey(), ['position' => 2]);
    $target = (int) $root->currentRevision();

    $root->update(['title' => 'Second']);
    $root->categories()->detach($a->getKey());
    $root->categories()->updateExistingPivot($b->getKey(), ['position' => 5]);
    $root->categories()->attach($c->getKey(), ['position' => 3]);
    $c->update(['name' => 'C renamed']);

    $report = $root->revertToRevision($target, (int) $root->currentRevision(), RevertMode::Link);

    $root->refresh();
    $revert_set = VersionSet::query()->findOrFail($report->revision);

    expect($root->title)->toBe('Second')
        ->and($root->categories()->orderBy('position')->get()->map(fn (VersionedRelationSubject $subject): array => [$subject->name, (int) $subject->pivot->position])->all())
        ->toBe([['A', 1], ['B', 2]])
        ->and($c->fresh()->name)->toBe('C renamed')
        ->and($revert_set->kind)->toBe(VersionSetKind::Revert)
        ->and((int) $revert_set->reverted_from_set_id)->toBe($target)
        ->and($report->restoredRelations)->toBe(['categories'])
        ->and($report->isPartial())->toBeFalse();
});

it('refuses a link revert when the record changed since the expected revision', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $root->categories()->attach(VersionedRelationSubject::query()->create(['name' => 'A'])->getKey());
    $stale = (int) $root->currentRevision();

    $root->update(['title' => 'Changed']);

    expect(fn () => $root->revertToRevision($stale, $stale, RevertMode::Link))
        ->toThrow(RevisionConflictException::class)
        ->and($root->fresh()->title)->toBe('Changed');
});

it('refuses a missing referenced record with nothing written, and skips and reports it with force', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $b = VersionedRelationSubject::query()->create(['name' => 'B']);
    $root->categories()->attach($a->getKey(), ['position' => 1]);
    $root->categories()->attach($b->getKey(), ['position' => 2]);
    $target = (int) $root->currentRevision();

    $root->categories()->detach($b->getKey());
    $a->delete();
    $revert_sets = VersionSet::query()->where('kind', VersionSetKind::Revert)->count();

    expect(fn () => $root->revertToRevision($target, (int) $root->currentRevision(), RevertMode::Link))
        ->toThrow(MissingRevertSubjectException::class)
        ->and(VersionSet::query()->where('kind', VersionSetKind::Revert)->count())->toBe($revert_sets)
        ->and($root->categories()->pluck('name')->all())->toBe([]);

    $report = $root->revertToRevision($target, (int) $root->currentRevision(), RevertMode::Link, force: true);

    expect($report->isPartial())->toBeTrue()
        ->and($report->skippedSubjects)->toBe(['categories' => [$a->getKey()]])
        ->and($root->categories()->pluck('name')->all())->toBe(['B']);
});

it('refuses a link revert to a revision with no complete anchor at or before it', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $created = (int) $root->currentRevision();
    $root->categories()->attach(VersionedRelationSubject::query()->create(['name' => 'A'])->getKey());
    $target = (int) $root->currentRevision();
    $root->categories()->detach();
    // The creation was the only anchor: labelled as captured outside a transaction, it anchors nothing (D47).
    VersionSet::query()->whereKey($created)->toBase()->update(['capture' => VersionCaptureQuality::BestEffort->value]);

    expect(fn () => $root->revertToRevision($target, (int) $root->currentRevision(), RevertMode::Link))
        ->toThrow(RevertRefusedException::class, 'No complete image of the record exists at or before the revision.')
        ->and($root->categories()->count())->toBe(0);
});
