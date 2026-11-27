<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Locking\Exceptions\StaleModelLockingException;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\ApplicationTransactions;

/**
 * The save of a versioned root with translations opens its revision scope before its SQL, so the root and its
 * translations are one revision, committed together (D28, D34).
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

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
});

afterEach(function (): void {
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
});

/**
 * @return list<string> the rows of a set, as change type and stream
 */
function rows_of_set(VersionSet $set): array
{
    return $set->versions()->get()
        ->map(static fn (Version $row): string => $row->change_type->value . ':' . ($row->relation_path ?? 'root'))
        ->all();
}

it('creates a root and its translations in one baseline set', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);

    $set = VersionSet::query()->sole();

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and($set->capture)->toBe(VersionCaptureQuality::Atomic)
        ->and(rows_of_set($set))->toBe(['created:root', 'created:translations'])
        ->and($set->root_id)->toBe((string) $document->getKey());
});

it('saves a root with a pending translation in one atomic set', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $before = VersionSet::query()->max('id');

    $document->code = 'changed';
    $document->title = 'New title';
    $document->save();

    $set = VersionSet::query()->where('id', '>', $before)->sole();

    expect(rows_of_set($set))->toBe(['updated:root', 'updated:translations'])
        ->and($set->capture)->toBe(VersionCaptureQuality::Atomic);
});

it('opens no scope and writes no set for a save with nothing to write', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $before = VersionSet::query()->count();
    $in_transaction = [];
    $document->getConnection()->listen(function () use (&$in_transaction, $document): void {
        $in_transaction[] = ApplicationTransactions::isOpenOn($document->getConnection());
    });

    $document->save();

    expect(VersionSet::query()->count())->toBe($before)
        ->and($in_transaction)->not->toContain(true);
});

it('rolls back the version rows when a saved handler fails', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $before = VersionSet::query()->count();
    TranslatedDocument::saved(static function (TranslatedDocument $saved): void {
        if ($saved->code === 'boom') {
            throw new RuntimeException('saved handler failed');
        }
    });

    $document->code = 'boom';

    expect(fn () => $document->save())->toThrow(RuntimeException::class);

    expect(TranslatedDocument::query()->withoutGlobalScopes()->findOrFail($document->getKey())->code)->toBe('doc')
        ->and(VersionSet::query()->count())->toBe($before);
});

it('refuses a stale save inside the scope', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $stale = TranslatedDocument::query()->withoutGlobalScopes()->findOrFail($document->getKey());
    $document->update(['code' => 'first writer']);

    $stale->code = 'second writer';

    expect(fn () => $stale->save())->toThrow(StaleModelLockingException::class);
    expect(TranslatedDocument::query()->withoutGlobalScopes()->findOrFail($document->getKey())->code)->toBe('first writer');
});

it('writes a change type per row as the event of each stream', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);

    expect($document->versions()->pluck('change_type')->all())->toBe([VersionChangeType::Created, VersionChangeType::Created]);
});

it('lets the save of a revert join the revert scope instead of opening its own', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $created = $document->versions()->whereNull('relation_path')->firstOrFail();
    $document->update(['code' => 'changed']);
    $before = VersionSet::query()->max('id');

    $document->revertToVersion($created->getKey(), (int) $document->currentRevision());

    $set = VersionSet::query()->where('id', '>', $before)->sole();

    expect($set->kind)->toBe(VersionSetKind::Revert)
        ->and($document->fresh()->code)->toBe('doc');
});
