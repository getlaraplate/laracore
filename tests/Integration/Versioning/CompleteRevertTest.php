<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\RevertMode;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\CoveredRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\OwnedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Versioning\Exceptions\RevertRefusedException;
use Modules\Core\Versioning\VersionEpochs;

/*
 * A complete revert puts back the columns, the translations and the links in one revert set, and is offered only when
 * every relation is covered, every set from the anchor to the revision was captured atomically and the revision is
 * in the current schema epoch (spec "Complete revert", D57). A refusal writes nothing.
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
    Schema::create(VersionedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('subtitle')->nullable();
        $table->string('status')->default('draft');
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    app(VersionEpochs::class)->flush();
    Schema::dropIfExists(VersionedDocument::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
});

function complete_revert_sets(): int
{
    return VersionSet::query()->where('kind', VersionSetKind::Revert)->count();
}

/**
 * A root with two links at its target revision, then a new title and a changed membership.
 *
 * @template TRoot of CoveredRelationRoot|OwnedRelationRoot|VersionedRelationRoot
 *
 * @param  class-string<TRoot>  $class
 * @return array{TRoot, int}
 */
function complete_revert_root(string $class): array
{
    $root = $class::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $b = VersionedRelationSubject::query()->create(['name' => 'B']);
    $root->categories()->attach($a->getKey(), ['position' => 1]);
    $root->categories()->attach($b->getKey(), ['position' => 2]);
    $target = (int) $root->currentRevision();

    $root->update(['title' => 'Second']);
    $root->categories()->detach($a->getKey());

    return [$root, $target];
}

it('reverts the columns and the links of a covered record in one revert set', function (): void {
    [$root, $target] = complete_revert_root(CoveredRelationRoot::class);

    $report = $root->revertToRevision($target, (int) $root->currentRevision());

    expect($report->mode)->toBe(RevertMode::Complete)
        ->and($root->fresh()->title)->toBe('First')
        ->and($root->categories()->orderBy('position')->pluck('name')->all())->toBe(['A', 'B'])
        ->and(complete_revert_sets())->toBe(1)
        ->and(VersionSet::query()->findOrFail($report->revision)->versions()->pluck('relation_path')->all())->toContain(null, 'categories');
});

it('refuses a complete revert of a record with a partially covered relation and allows its link revert', function (): void {
    [$root, $target] = complete_revert_root(VersionedRelationRoot::class);

    expect(fn () => $root->revertToRevision($target, (int) $root->currentRevision()))
        ->toThrow(RevertRefusedException::class, 'Not every relation of the record supports a complete revert.')
        ->and(complete_revert_sets())->toBe(0);

    $root->revertToRevision($target, (int) $root->currentRevision(), RevertMode::Link);

    expect($root->categories()->orderBy('position')->pluck('name')->all())->toBe(['A', 'B']);
});

it('refuses a complete revert across a set captured less than atomically', function (): void {
    $root = CoveredRelationRoot::query()->create(['title' => 'First']);
    $root->categories()->attach(VersionedRelationSubject::query()->create(['name' => 'A'])->getKey());
    $gap = (int) $root->currentRevision();
    $root->update(['title' => 'Second']);
    $target = (int) $root->currentRevision();
    $root->update(['title' => 'Third']);
    VersionSet::query()->whereKey($gap)->toBase()->update(['capture' => VersionCaptureQuality::BestEffort->value]);

    expect(fn () => $root->revertToRevision($target, (int) $root->currentRevision()))
        ->toThrow(RevertRefusedException::class, 'was not captured atomically')
        ->and($root->fresh()->title)->toBe('Third')
        ->and(complete_revert_sets())->toBe(0);
});

it('refuses a complete revert to a revision written in another schema epoch', function (): void {
    [$root, $target] = complete_revert_root(CoveredRelationRoot::class);
    Schema::table(VersionedRelationRoot::TABLE, function (Blueprint $table): void {
        $table->string('subtitle')->nullable();
    });
    app(VersionEpochs::class)->flush();
    $root = CoveredRelationRoot::query()->findOrFail($root->getKey());
    $root->update(['title' => 'Third']);

    expect(fn () => $root->revertToRevision($target, (int) $root->currentRevision()))
        ->toThrow(RevertRefusedException::class, 'The schema of the record changed since the revision.')
        ->and(complete_revert_sets())->toBe(0);
});

it('refuses a complete revert of a record that owns a relation a revert cannot put back', function (): void {
    [$root, $target] = complete_revert_root(OwnedRelationRoot::class);

    expect(fn () => $root->revertToRevision($target, (int) $root->currentRevision()))
        ->toThrow(RevertRefusedException::class, 'The record owns a relation a revert cannot put back.')
        ->and(complete_revert_sets())->toBe(0);
});

it('reverts a record with neither translations nor relations completely as its columns alone', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'First']);
    $created = (int) $document->currentRevision();
    $document->update(['title' => 'Second']);

    $report = $document->revertToRevision($created, (int) $document->currentRevision());

    expect($report->mode)->toBe(RevertMode::Complete)
        ->and($document->fresh()->title)->toBe('First');
});
