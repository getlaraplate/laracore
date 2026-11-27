<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Version;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Versioning\Exceptions\MissingVersionAnchorException;

/**
 * The state of a record as of a revision is rebuilt from the nearest snapshot at or before it, the later rows
 * applied forward (C08); a revert never moves the record into or out of the bin (D61).
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
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedDocument::TABLE);
});

function last_version_of(VersionedDocument $document): Version
{
    return $document->versions()->reorder('id')->get()->last();
}

it('rebuilds the state at a revision from the nearest snapshot, not from the live row', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1', 'subtitle' => 'S1']);
    $document->update(['title' => 'T2']);
    $document->update(['subtitle' => 'S2']);
    $third = last_version_of($document);
    $document->update(['title' => 'T3']);

    expect($third->stateAsOf())->toMatchArray(['title' => 'T2', 'subtitle' => 'S2']);
});

it('follows the strategy stored on each row, not the model current one', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1', 'subtitle' => 'S1']);
    $document->update(['title' => 'T2']);
    $second = last_version_of($document);
    $document->useVersionStrategy(VersionStrategy::SNAPSHOT);
    $document->update(['subtitle' => 'S2']);

    expect($second->stateAsOf())->toMatchArray(['title' => 'T2', 'subtitle' => 'S1'])
        ->and(last_version_of($document)->stateAsOf())->toMatchArray(['title' => 'T2', 'subtitle' => 'S2']);
});

it('refuses to rebuild a revision with no snapshot at or before it', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1']);
    $document->versions()->where('change_type', VersionChangeType::Created)->delete();
    $document->update(['title' => 'T2']);

    expect(fn () => last_version_of($document)->stateAsOf())->toThrow(MissingVersionAnchorException::class);
});

it('reverts to the rebuilt state of a revision', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1', 'subtitle' => 'S1']);
    $document->update(['title' => 'T2']);
    $document->update(['subtitle' => 'S2']);
    $third = last_version_of($document);
    $document->update(['title' => 'T3']);

    $document->revertToVersion($third->getKey(), (int) $document->currentRevision());

    expect($document->fresh()->only(['title', 'subtitle']))->toBe(['title' => 'T2', 'subtitle' => 'S2']);
});

it('leaves a live record out of the bin when the target revision was taken while it was in it', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1']);
    $document->delete();
    $document->update(['title' => 'T2']);
    $in_the_bin = last_version_of($document);
    $document->restore();

    $document->revertToVersion($in_the_bin->getKey(), (int) $document->currentRevision());

    $live = VersionedDocument::withTrashed()->findOrFail($document->getKey());

    expect($live->title)->toBe('T2')
        ->and($live->deleted_at)->toBeNull();
});

it('leaves a record in the bin when reverted to a revision taken before it was trashed', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1']);
    $before = last_version_of($document);
    $document->update(['title' => 'T2']);
    $document->delete();

    $document->revertToVersion($before->getKey(), (int) $document->currentRevision());

    $live = VersionedDocument::withTrashed()->findOrFail($document->getKey());

    expect($live->title)->toBe('T1')
        ->and($live->deleted_at)->not->toBeNull();
});

it('writes the creation row as a complete image, database defaults included (C09)', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1']);

    expect($document->versions()->where('change_type', VersionChangeType::Created)->firstOrFail()->contents)
        ->toMatchArray(['title' => 'T1', 'status' => 'draft']);
});

it('rebuilds a column that took its database default at creation (C09)', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'T1']);
    $created = last_version_of($document);
    $document->update(['status' => 'published']);

    $document->revertToVersion($created->getKey(), (int) $document->currentRevision());

    expect($document->fresh()->status)->toBe('draft');
});
