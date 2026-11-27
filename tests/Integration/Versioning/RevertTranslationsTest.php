<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\RevertMode;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\Exceptions\RevertRefusedException;
use Modules\Core\Versioning\RevisionReverter;

/*
 * The owned-part revert puts the translations back by locale as of a revision: a changed one gets its fields back, one
 * added later is removed, one deleted later is recreated. Each change is a row of the revert set on the owner (D28).
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
 * @return array<string, array{title: string|null, body: string|null}>
 */
function translations_of(TranslatedDocument $document): array
{
    return TranslatedDocumentTranslation::query()
        ->where('translated_document_id', $document->getKey())
        ->orderBy('locale')
        ->get()
        ->mapWithKeys(static fn (TranslatedDocumentTranslation $translation): array => [
            $translation->locale => ['title' => $translation->title, 'body' => $translation->body],
        ])
        ->all();
}

it('puts the translations back by locale: changed, added later and deleted later', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'first']);
    $document->setTranslation('en', ['title' => 'Hello', 'body' => 'Body']);
    $document->setTranslation('fr', ['title' => 'Bonjour', 'body' => 'Corps']);
    $target = (int) $document->currentRevision();
    $expected_translations = translations_of($document);

    $document->setTranslation('en', ['title' => 'Hello again']);
    TranslatedDocumentTranslation::query()->where('translated_document_id', $document->getKey())->where('locale', 'fr')->firstOrFail()->delete();
    $document->setTranslation('it', ['title' => 'Ciao', 'body' => 'Corpo']);
    $document->update(['code' => 'second']);
    $document->refresh();
    $lock_version = (int) $document->lock_version;

    $report = $document->revertToRevision($target, (int) $document->currentRevision(), RevertMode::OwnedPart);
    $revert_set = VersionSet::query()->findOrFail($report->revision);

    expect(translations_of($document))->toBe($expected_translations)
        ->and($document->fresh()->code)->toBe('second')
        ->and((int) $document->fresh()->lock_version)->toBe($lock_version + 1)
        ->and($revert_set->versions()->pluck('relation_path')->unique()->values()->all())->toBe(['translations'])
        ->and($revert_set->versions()->get()->map(static fn (Version $row): string => $row->subject_key['locale'])->sort()->values()->all())
        ->toBe(['en', 'fr', 'it']);
});

it('reads the translations as of a revision without writing anything', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'first']);
    $document->setTranslation('en', ['title' => 'Hello', 'body' => 'Body']);
    $target = (int) $document->currentRevision();
    $document->setTranslation('en', ['title' => 'Changed']);

    expect(app(RevisionReverter::class)->translationsAsOf($document, $target))
        ->toMatchArray(['en' => ['title' => 'Hello', 'body' => 'Body']]);
});

it('refuses a translation revert to a revision with no complete anchor at or before it', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'first']);
    $created = (int) $document->currentRevision();
    $document->setTranslation('en', ['title' => 'Hello']);
    $target = (int) $document->currentRevision();
    $document->setTranslation('en', ['title' => 'Changed']);
    VersionSet::query()->whereKey($created)->toBase()->update(['capture' => VersionCaptureQuality::BestEffort->value]);

    expect(fn () => $document->revertToRevision($target, (int) $document->currentRevision(), RevertMode::OwnedPart))
        ->toThrow(RevertRefusedException::class, 'No complete image of the record exists at or before the revision.')
        ->and(translations_of($document)['en']['title'])->toBe('Changed');
});
