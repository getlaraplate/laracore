<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Tests\Stubs\Versioning\SoftDeletedVersionedArticle;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Tests\Stubs\Versioning\VersionedArticle;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Contracts\VersionWriterInterface;
use Modules\Core\Versioning\Data\VersionChange;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * Queries of an atomic update of a plain versioned model whose baseline is not due, before this slice.
 */
const BASELINE_FREE_ATOMIC_UPDATE_QUERIES = 5;

/**
 * The first atomic write of a root with no baseline, or whose latest best_effort set is newer than its latest
 * baseline, appends the whole aggregate to its set and stores the set as a baseline (D45 rule 1, D52, D53). A revert
 * never carries it (D60).
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
    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedArticle::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
});

/**
 * Writes one root row through a scope opened after the fact with no transaction open: a best_effort set (D52).
 *
 * @param  array<string, mixed>  $replacements
 */
function write_best_effort(Model $root, array $replacements): VersionSet
{
    app(VersionSetManagerInterface::class)->runAfterTheFact(
        VersionSetRoot::forModel($root),
        fn (): Version => app(VersionWriterInterface::class)->write(VersionChange::forModel($root, $replacements)),
        new VersionSetOptions,
    );

    $set = VersionSet::query()->latest('id')->firstOrFail();
    expect($set->capture)->toBe(VersionCaptureQuality::BestEffort);

    return $set;
}

function latest_set_of(Model $root): VersionSet
{
    return VersionSet::query()
        ->where('root_type', $root->getMorphClass())
        ->where('root_id', (string) $root->getKey())
        ->latest('id')
        ->firstOrFail();
}

/**
 * @return list<array{type: VersionChangeType, strategy: VersionStrategy, path: string, key: array<string, mixed>|null, contents: array<string, mixed>}>
 */
function rows_of(VersionSet $set): array
{
    return $set->versions()->reorder('sequence')->get()
        ->map(static fn (Version $row): array => [
            'type' => $row->change_type,
            'strategy' => $row->version_strategy,
            'path' => $row->relation_path ?? 'root',
            'key' => $row->subject_key,
            'contents' => $row->contents,
        ])
        ->all();
}

it('writes a baseline with the root and every locale at the first atomic write after a best_effort set', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->setTranslation('it', ['title' => 'Titolo', 'body' => 'Testo']);
    $document->save();
    write_best_effort($document, ['code' => 'doc']);

    $document->code = 'changed';
    $document->save();

    $set = latest_set_of($document);
    $rows = rows_of($set);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and($set->capture)->toBe(VersionCaptureQuality::Atomic)
        ->and(array_column($rows, 'path'))->toBe(['root', 'root', 'translations', 'translations'])
        ->and($rows[0]['strategy'])->toBe(VersionStrategy::DIFF)
        ->and($rows[1])->toMatchArray(['type' => VersionChangeType::Updated, 'strategy' => VersionStrategy::SNAPSHOT, 'key' => null])
        ->and($rows[1]['contents'])->toMatchArray(['code' => 'changed'])
        ->and(array_column(array_slice($rows, 2), 'type'))->toBe([VersionChangeType::Updated, VersionChangeType::Updated])
        ->and(array_column(array_slice($rows, 2), 'strategy'))->toBe([VersionStrategy::SNAPSHOT, VersionStrategy::SNAPSHOT])
        ->and(collect($rows)->firstWhere('key', ['locale' => 'it'])['contents'])->toBe(['title' => 'Titolo', 'body' => 'Testo']);
});

it('writes a baseline with every registered link at the first atomic write after a best_effort set', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $subjects = collect(['A', 'B'])->map(fn (string $name): VersionedRelationSubject => VersionedRelationSubject::query()->create(['name' => $name]));
    $subjects->each(fn (VersionedRelationSubject $subject, int $index) => $root->categories()->attach($subject->getKey(), ['position' => $index + 1]));
    write_best_effort($root, ['title' => 'First']);

    $root->update(['title' => 'Second']);

    $set = latest_set_of($root);
    $rows = rows_of($set);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column($rows, 'path'))->toBe(['root', 'root', 'categories', 'categories'])
        ->and($rows[1]['contents'])->toBe(['title' => 'Second'])
        ->and(array_slice($rows, 2))->toBe($subjects->map(fn (VersionedRelationSubject $subject, int $index): array => [
            'type' => VersionChangeType::Created,
            'strategy' => VersionStrategy::SNAPSHOT,
            'path' => 'categories',
            'key' => ['id' => $subject->getKey()],
            'contents' => ['position' => $index + 1],
        ])->all());
});

it('writes the baseline once', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    write_best_effort($document, ['code' => 'doc']);
    $document->update(['code' => 'second']);

    $document->update(['code' => 'third']);

    $set = latest_set_of($document);

    expect($set->kind)->toBe(VersionSetKind::Change)
        ->and(array_column(rows_of($set), 'path'))->toBe(['root']);
});

it('writes a baseline at the first write of a record that has sets but no baseline', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->update(['code' => 'second']);
    VersionSet::query()->where('kind', VersionSetKind::Baseline)->delete();

    $document->update(['code' => 'third']);

    expect(latest_set_of($document)->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column(rows_of(latest_set_of($document)), 'path'))->toBe(['root', 'root', 'translations']);
});

it('never writes the baseline in a revert set and writes it at the next save (D60)', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->update(['code' => 'second']);
    $target = $document->versions()->where('relation_path', null)->oldest('id')->firstOrFail();
    write_best_effort($document, ['code' => 'second']);

    $document->revertToVersion($target->getKey());
    $revert = latest_set_of($document);

    expect($revert->kind)->toBe(VersionSetKind::Revert)
        ->and(collect(rows_of($revert))->pluck('strategy')->unique()->all())->not->toContain(VersionStrategy::SNAPSHOT);

    $document->refresh()->update(['code' => 'after revert']);

    expect(latest_set_of($document)->kind)->toBe(VersionSetKind::Baseline);
});

it('joins a set stored as baseline from a nested run with ordinary options', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    write_best_effort($document, ['code' => 'doc']);
    $before = VersionSet::query()->max('id');
    $manager = app(VersionSetManagerInterface::class);

    $manager->run(VersionSetRoot::forModel($document), function () use ($document, $manager): void {
        $document->update(['code' => 'second']);
        $manager->run(VersionSetRoot::forModel($document), fn () => $document->update(['code' => 'third']), new VersionSetOptions);
    });

    $set = VersionSet::query()->where('id', '>', $before)->sole();

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column(rows_of($set), 'path'))->toBe(['root', 'root', 'root', 'translations']);
});

it('adds at most one query to the atomic write of a plain versioned model', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $article->getConnection()->transaction(fn () => $article->update(['title' => 'Second']));

    DB::enableQueryLog();
    $article->getConnection()->transaction(fn () => $article->update(['title' => 'Third']));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(BASELINE_FREE_ATOMIC_UPDATE_QUERIES + 1)
        ->and(latest_set_of($article)->kind)->toBe(VersionSetKind::Change);
});

it('writes a baseline at the first atomic write after a best_effort set that changed only a translation', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    write_best_effort($document, ['code' => 'doc']);

    $document->translations()->firstOrFail()->update(['title' => 'Edited']);

    $set = latest_set_of($document);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column(rows_of($set), 'path'))->toBe(['translations', 'root', 'translations']);
});

it('writes a baseline at the first atomic write after a best_effort set that changed only a link', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $subject = VersionedRelationSubject::query()->create(['name' => 'A']);
    write_best_effort($root, ['title' => 'First']);

    app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($root),
        fn () => $root->categories()->attach($subject->getKey(), ['position' => 1]),
    );

    $set = latest_set_of($root);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column(rows_of($set), 'path'))->toBe(['categories', 'root', 'categories']);
});

it('writes no baseline for a root whose row is gone by the end of its set', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    write_best_effort($document, ['code' => 'doc']);
    $before = VersionSet::query()->max('id');

    app(VersionSetManagerInterface::class)->run(VersionSetRoot::forModel($document), function () use ($document): void {
        $document->update(['code' => 'second']);
        TranslatedDocument::query()->whereKey($document->getKey())->delete();
    });

    $set = VersionSet::query()->where('id', '>', $before)->sole();

    expect($set->kind)->toBe(VersionSetKind::Change)
        ->and(array_column(rows_of($set), 'path'))->toBe(['root']);
});

it('reads the sets of a root by its table, not only by its class and key', function (): void {
    Schema::create('core_test_versioned_articles_copy', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $article->getConnection()->transaction(fn () => $article->update(['title' => 'Anchored']));
    $copy = (new VersionedArticle)->setTable('core_test_versioned_articles_copy');
    $copy->fill(['title' => 'Copy'])->save();
    write_best_effort($copy, ['title' => 'Copy']);

    expect($copy->getKey())->toBe($article->getKey());

    $article->getConnection()->transaction(fn () => $article->update(['title' => 'Again']));

    expect(latest_set_of($article)->kind)->toBe(VersionSetKind::Change);

    Schema::dropIfExists('core_test_versioned_articles_copy');
});

it('writes a baseline at the first atomic write of a record whose creation was best_effort (D47)', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);

    expect(latest_set_of($article)->capture)->toBe(VersionCaptureQuality::BestEffort);

    $article->getConnection()->transaction(fn () => $article->update(['title' => 'Second']));

    $set = latest_set_of($article);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and(array_column(rows_of($set), 'path'))->toBe(['root', 'root'])
        ->and(rows_of($set)[1]['contents'])->toBe(['title' => 'Second']);
});

it('writes a baseline holding the trashed row when a soft delete is the first atomic write after a best_effort creation', function (): void {
    $article = SoftDeletedVersionedArticle::query()->create(['title' => 'First']);

    $article->delete();

    $set = latest_set_of($article);
    $rows = rows_of($set);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and($rows[0]['type'])->toBe(VersionChangeType::Trashed)
        ->and(array_column($rows, 'path'))->toBe(['root', 'root']);
});

it('leaves the baseline of a root whose set was opened by another root write to its next own scope', function (): void {
    $article = VersionedArticle::query()->create(['title' => 'First']);
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);

    app(VersionSetManagerInterface::class)->run(VersionSetRoot::forModel($document), function () use ($document, $article): void {
        $document->update(['code' => 'second']);
        $article->update(['title' => 'Second']);
    });

    expect(latest_set_of($article)->kind)->toBe(VersionSetKind::Change);

    $article->getConnection()->transaction(fn () => $article->update(['title' => 'Third']));

    expect(latest_set_of($article)->kind)->toBe(VersionSetKind::Baseline);
});

it('leaves the baseline image out of the history listing and keeps the rows of the set itself', function (): void {
    $document = TranslatedDocument::query()->create(['code' => 'doc', 'title' => 'Title']);
    $document->setTranslation('it', ['title' => 'Titolo']);
    $document->save();
    write_best_effort($document, ['code' => 'doc']);

    $document->update(['code' => 'changed']);

    $set = latest_set_of($document);

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and($set->baseline_from_sequence)->toBe(2)
        ->and($document->history()->where('version_set_id', $set->getKey())->get()->pluck('sequence')->all())->toBe([1])
        ->and($document->history()->where('relation_path', null)->get()->last()->change_type)->toBe(VersionChangeType::Created);
});
