<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Versioning\Exceptions\AggregateRestoreConflictException;
use Modules\Core\Versioning\Exceptions\MissingRestoreSubjectException;

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

it('restores scalars and reference membership to a revision in one Revert set', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $b = VersionedRelationSubject::query()->create(['name' => 'B']);
    $c = VersionedRelationSubject::query()->create(['name' => 'C']);

    $root->categories()->attach($a->getKey(), ['position' => 1]);
    $root->categories()->attach($b->getKey(), ['position' => 2]);
    $target = $root->currentRevision();

    $root->update(['title' => 'Second']);
    $root->categories()->detach($a->getKey());
    $root->categories()->attach($c->getKey(), ['position' => 3]);
    $expected = $root->currentRevision();

    $report = $root->restoreToRevision($target, $expected);

    $root->refresh();
    $membership = collect($root->versionedRelationMembership('categories'))->pluck('id')->sort()->values()->all();
    $restore_set = VersionSet::query()->findOrFail($root->currentRevision());

    expect($root->title)->toBe('First')
        ->and($root->categories()->count())->toBe(2)
        ->and($membership)->toBe(collect([$a->getKey(), $b->getKey()])->sort()->values()->all())
        ->and($restore_set->kind)->toBe(VersionSetKind::Revert)
        ->and((int) $restore_set->reverted_from_set_id)->toBe($target)
        ->and($report->restoredRelations)->toBe(['categories'])
        ->and($report->isComplete())->toBeTrue();
});

it('rejects a restore when the aggregate changed since the expected revision', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $root->categories()->attach($a->getKey());
    $stale = $root->currentRevision();

    $root->update(['title' => 'Changed']);

    expect(fn () => $root->restoreToRevision($stale, $stale))
        ->toThrow(AggregateRestoreConflictException::class);

    expect($root->fresh()->title)->toBe('Changed');
});

it('fails on a missing reference subject unless forced', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $b = VersionedRelationSubject::query()->create(['name' => 'B']);
    $root->categories()->attach($a->getKey(), ['position' => 1]);
    $root->categories()->attach($b->getKey(), ['position' => 2]);
    $target = $root->currentRevision();

    $a->delete();

    expect(fn () => $root->restoreToRevision($target, $root->currentRevision()))
        ->toThrow(MissingRestoreSubjectException::class);

    $report = $root->restoreToRevision($target, $root->currentRevision(), force: true);

    expect($report->isComplete())->toBeFalse()
        ->and($report->skippedSubjects)->toBe(['categories' => [$a->getKey()]])
        ->and(collect($root->fresh()->versionedRelationMembership('categories'))->pluck('id')->all())
        ->toBe([$b->getKey()]);
});
