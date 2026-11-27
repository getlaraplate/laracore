<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * A set is atomic when a transaction on the root's connection was open before the statement that produced its
 * rows, so the business write and its history commit together; otherwise it is best effort (D47, D52).
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });

    $this->document = VersionedDocument::query()->create(['title' => 'First']);
    $this->sets_before = VersionSet::query()->max('id');
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedDocument::TABLE);
});

/**
 * @return list<VersionCaptureQuality>
 */
function captures_after(int $after): array
{
    return VersionSet::query()->where('id', '>', $after)->orderBy('id')->pluck('capture')->all();
}

it('labels a save outside any transaction best effort, and its scalar revert still works', function (): void {
    $created = $this->document->versions()->firstOrFail();
    $this->document->update(['title' => 'Second']);

    expect(captures_after($this->sets_before))->toBe([VersionCaptureQuality::BestEffort]);

    $this->document->revertToVersion($created->getKey(), (int) $this->document->currentRevision());

    expect($this->document->fresh()->title)->toBe('First');
});

it('labels a save inside a transaction the application opened atomic', function (): void {
    $this->document->getConnection()->transaction(fn () => $this->document->update(['title' => 'Second']));

    expect(captures_after($this->sets_before))->toBe([VersionCaptureQuality::Atomic]);
});

it('labels a write inside a scope atomic, for its root and for another root', function (): void {
    $other = VersionedDocument::query()->create(['title' => 'Other']);
    $before = VersionSet::query()->max('id');

    app(VersionSetManagerInterface::class)->run(VersionSetRoot::forModel($this->document), function () use ($other): void {
        $this->document->update(['title' => 'Second']);
        $other->update(['title' => 'Other edited']);
    });

    expect(captures_after($before))->toBe([VersionCaptureQuality::Atomic, VersionCaptureQuality::Atomic]);
});

it('labels a revert atomic', function (): void {
    $created = $this->document->versions()->firstOrFail();
    $this->document->update(['title' => 'Second']);
    $before = VersionSet::query()->max('id');

    $this->document->revertToVersion($created->getKey(), (int) $this->document->currentRevision());

    expect(captures_after($before))->toBe([VersionCaptureQuality::Atomic]);
});

it('never writes a set as incomplete in milestone 1', function (): void {
    $this->document->update(['title' => 'Second']);
    $this->document->getConnection()->transaction(fn () => $this->document->update(['title' => 'Third']));

    expect(VersionSet::query()->where('capture', VersionCaptureQuality::Incomplete)->count())->toBe(0);
});
