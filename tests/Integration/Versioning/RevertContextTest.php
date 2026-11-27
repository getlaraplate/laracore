<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Models\Version;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;

/*
 * The revert context is the version set of kind `Revert` in progress (D36): the translation event emitted during it
 * says so, set at emission, and the context ends with the revert, also when the revert fails.
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

/**
 * Runs the operation in a set of the given kind on the document.
 */
function in_set_of_kind(VersionedDocument $document, VersionSetKind $kind, Closure $operation): mixed
{
    $target = (int) $document->currentRevision();

    return app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($document),
        $operation,
        new VersionSetOptions(kind: $kind, revertedFrom: $kind === VersionSetKind::Revert ? $target : null),
    );
}

it('marks the translation event as part of a revert only inside a revert set', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'First']);

    $in_revert = in_set_of_kind($document, VersionSetKind::Revert, fn (): TranslatedModelSaved => TranslatedModelSaved::ofCurrentSave($document));
    $in_change = in_set_of_kind($document, VersionSetKind::Change, fn (): TranslatedModelSaved => TranslatedModelSaved::ofCurrentSave($document));

    expect($in_revert->fromRevert)->toBeTrue()
        ->and(app(VersionSetManagerInterface::class)->current())->toBeNull()
        ->and($in_change->fromRevert)->toBeFalse()
        ->and(TranslatedModelSaved::ofCurrentSave($document)->fromRevert)->toBeFalse();
});

it('ends the revert context with a failed revert', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'First']);

    expect(fn () => in_set_of_kind($document, VersionSetKind::Revert, function (): never {
        throw new RuntimeException('revert failed');
    }))->toThrow(RuntimeException::class, 'revert failed');

    expect(TranslatedModelSaved::ofCurrentSave($document)->fromRevert)->toBeFalse()
        ->and(app(VersionSetManagerInterface::class)->current())->toBeNull();
});

it('keeps the event of an ordinary save, the table action included, outside a revert', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'First']);

    expect((new TranslatedModelSaved($document))->fromRevert)->toBeFalse()
        ->and(TranslatedModelSaved::ofCurrentSave($document, ['it'], true))
        ->toMatchObject(['locales' => ['it'], 'force' => true, 'fromRevert' => false]);
});
