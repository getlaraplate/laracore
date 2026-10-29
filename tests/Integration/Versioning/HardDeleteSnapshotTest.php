<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\RecordOrigin;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * A hard delete of a versioned root leaves one snapshot set of the aggregate as it was, and removes the root's edit
 * history, in the transaction of the delete (D17, D29, D44). Every row of the set is a `deleted` SNAPSHOT with the
 * image in `original_contents` (D55); what the delete itself writes stays out of it (D54).
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
    Schema::create(VersionedRelationRoot::UNREGISTERED_PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedRelationRoot::UNREGISTERED_PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
});

/**
 * @return Illuminate\Database\Eloquent\Collection<int, VersionSet>
 */
function sets_of_root(Model $root): Illuminate\Database\Eloquent\Collection
{
    return VersionSet::query()
        ->where('root_type', $root->getMorphClass())
        ->where('root_id', (string) $root->getKey())
        ->orderBy('id')
        ->get();
}

/**
 * @return list<array{path: string, key: array<string, mixed>|null, original: array<string, mixed>}>
 */
function snapshot_rows_of(VersionSet $set): array
{
    return $set->versions()->reorder('sequence')->get()
        ->map(static function (Version $row): array {
            expect($row->change_type)->toBe(VersionChangeType::Deleted)
                ->and($row->version_strategy)->toBe(VersionStrategy::SNAPSHOT)
                ->and($row->contents)->toBe([]);

            return [
                'path' => $row->relation_path ?? 'root',
                'key' => $row->subject_key,
                'original' => $row->original_contents,
            ];
        })
        ->all();
}

it('keeps the root and one row per locale in the snapshot set and removes the edit history', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->setTranslation('it', ['title' => 'Titolo', 'body' => 'Testo']);
    $document->code = 'changed';
    $document->save();
    $locale = $document->translations()->where('locale', '!=', 'it')->value('locale');

    $document->delete();

    $set = sets_of_root($document)->sole();
    $rows = snapshot_rows_of($set);

    expect($set->kind)->toBe(VersionSetKind::Change)
        ->and($set->reason)->toBe('manual')
        ->and(array_column($rows, 'path'))->toBe(['root', 'translations', 'translations'])
        ->and($rows[0]['key'])->toBeNull()
        ->and($rows[0]['original'])->toMatchArray(['code' => 'changed'])
        ->and(array_column($rows, 'key'))->toContain(['locale' => 'it'], ['locale' => $locale])
        ->and(collect($rows)->firstWhere('key', ['locale' => 'it'])['original'])->toBe(['title' => 'Titolo', 'body' => 'Testo']);
});

it('keeps one row per registered membership with its authoritative pivot columns', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $subjects = collect(['A', 'B', 'C'])->map(fn (string $name): VersionedRelationSubject => VersionedRelationSubject::query()->create(['name' => $name]));
    $subjects->each(fn (VersionedRelationSubject $subject, int $index) => $root->categories()->attach($subject->getKey(), ['position' => $index + 1]));
    $unregistered = VersionedRelationSubject::query()->create(['name' => 'Label']);
    $root->labels()->attach($unregistered->getKey());

    $root->delete();

    $rows = snapshot_rows_of(sets_of_root($root)->sole());

    expect(array_column($rows, 'path'))->toBe(['root', 'categories', 'categories', 'categories'])
        ->and(array_slice($rows, 1))->toBe($subjects->map(fn (VersionedRelationSubject $subject, int $index): array => [
            'path' => 'categories',
            'key' => ['id' => $subject->getKey()],
            'original' => ['position' => $index + 1],
        ])->all());
});

it('keeps the writes of the delete itself out of the snapshot set (D54)', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $subject = VersionedRelationSubject::query()->create(['name' => 'A']);
    $root->categories()->attach($subject->getKey(), ['position' => 1]);
    VersionedRelationRoot::deleted(static function (VersionedRelationRoot $deleted): void {
        $deleted->categories()->detach();
    });

    $root->delete();

    expect(array_column(snapshot_rows_of(sets_of_root($root)->sole()), 'original'))
        ->toBe([['title' => 'First'], ['position' => 1]]);
});

it('keeps the origin of the record in the snapshot set and removes it', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $origin = RecordOrigin::factory()->create([
        'referable_type' => $document->getMorphClass(),
        'referable_id' => $document->getKey(),
        'source_key' => 'feed',
        'external_id' => 'ext-1',
    ]);

    $document->delete();

    $row = collect(snapshot_rows_of(sets_of_root($document)->sole()))->firstWhere('path', 'origin');

    expect($row['key'])->toBe(['id' => $origin->getKey()])
        ->and($row['original'])->toMatchArray(['source_key' => 'feed', 'external_id' => 'ext-1'])
        ->and(RecordOrigin::query()->find($origin->getKey()))->toBeNull();
});

it('removes a revert set of the root with the rest of its history (D58)', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->code = 'changed';
    $document->save();
    $target = sets_of_root($document)->last();
    app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($document),
        function () use ($document): void {
            $document->code = 'doc';
            $document->save();
        },
        new VersionSetOptions(kind: VersionSetKind::Revert, revertedFrom: $target),
    );
    $other = TranslatedDocument::query()->create(['code' => 'other', 'title' => 'Other']);

    $document->delete();

    expect(sets_of_root($document))->toHaveCount(1)
        ->and(sets_of_root($other))->toHaveCount(1);
});

it('keeps the history when the delete is cancelled', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $before = sets_of_root($document)->modelKeys();
    TranslatedDocument::deleting(static fn (): bool => false);

    expect($document->delete())->toBeFalse()
        ->and(sets_of_root($document)->modelKeys())->toBe($before);
});

it('keeps the history when the delete fails after its statement', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $before = sets_of_root($document)->modelKeys();
    TranslatedDocument::deleted(static function (): void {
        throw new RuntimeException('deleted handler failed');
    });

    expect(fn () => $document->delete())->toThrow(RuntimeException::class);

    expect(sets_of_root($document)->modelKeys())->toBe($before)
        ->and(TranslatedDocument::query()->find($document->getKey()))->not->toBeNull();
});
