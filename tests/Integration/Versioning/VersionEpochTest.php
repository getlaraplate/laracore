<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\RelationCoverage;
use Modules\Core\Enums\RelationOwnership;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionEpoch;
use Modules\Core\Tests\Stubs\Versioning\EpochArticle;
use Modules\Core\Tests\Stubs\Versioning\EpochArticleTwin;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\Data\RelationDescriptor;
use Modules\Core\Versioning\EpochFingerprint;
use Modules\Core\Versioning\VersionEpochs;

/**
 * A root class has one schema epoch per versioned shape: its versioned columns, its translation columns and its
 * registered relations. The shape is read at the first write of the class in a process (D13, D32, D33, D57).
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);
    EpochArticle::$descriptors = [];

    Schema::create(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title')->default('untitled');
        $table->timestamps();
    });
    Schema::create(VersionedRelationSubject::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create(EpochArticle::PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('article_id');
        $table->unsignedBigInteger('subject_id');
    });
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
    EpochArticle::$descriptors = [];
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
    Schema::dropIfExists(EpochArticle::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(EpochArticle::TABLE);
});

function create_epoch_article(string $title): EpochArticle
{
    return DB::transaction(static fn (): EpochArticle => EpochArticle::query()->create(['title' => $title]));
}

/**
 * @return list<int|null>
 */
function epochs_of_rows(EpochArticle $article): array
{
    return Version::query()
        ->where('versionable_type', $article->getMorphClass())
        ->where('versionable_id', $article->getKey())
        ->pluck('epoch_id')
        ->map(static fn (mixed $epoch_id): ?int => $epoch_id === null ? null : (int) $epoch_id)
        ->all();
}

function fingerprint_of_epoch_article(): string
{
    $fingerprint = new EpochFingerprint;

    return $fingerprint->fingerprintOf($fingerprint->definitionOf(new EpochArticle));
}

it('creates one epoch for two writes of a class in one process and stamps it on every row', function (): void {
    $first = create_epoch_article('First');
    $second = create_epoch_article('Second');
    $epoch = VersionEpoch::query()->sole();

    expect($epoch->root_type)->toBe((new EpochArticle)->getMorphClass())
        ->and($epoch->fingerprint)->toHaveLength(64)
        ->and(array_column($epoch->definition['columns'], 'name'))->toBe(['id', 'title'])
        ->and(epochs_of_rows($first))->not->toBeEmpty()
        ->and(array_unique([...epochs_of_rows($first), ...epochs_of_rows($second)]))->toBe([$epoch->id]);
});

it('opens a new epoch when a versionable column is added, and the next row carries it', function (): void {
    create_epoch_article('Before');

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->string('subtitle')->nullable();
    });
    app(VersionEpochs::class)->flush();
    $after = create_epoch_article('After');
    $latest = VersionEpoch::query()->latest('id')->firstOrFail();

    expect(VersionEpoch::query()->count())->toBe(2)
        ->and(array_column($latest->definition['columns'], 'name'))->toBe(['id', 'subtitle', 'title'])
        ->and(array_unique(epochs_of_rows($after)))->toBe([$latest->id]);
});

it('keeps the epoch when a column listed as not versionable is added', function (): void {
    create_epoch_article('Before');

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->text('notes')->nullable();
    });
    app(VersionEpochs::class)->flush();
    create_epoch_article('After');

    expect(VersionEpoch::query()->count())->toBe(1);
});

it('keeps the epoch when only a default changes', function (): void {
    $before = fingerprint_of_epoch_article();

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->string('title')->default('another default')->change();
    });

    expect(fingerprint_of_epoch_article())->toBe($before);
});

it('changes the fingerprint when a relation is registered or its coverage changes (D57)', function (): void {
    $without_relations = fingerprint_of_epoch_article();

    EpochArticle::$descriptors = [new RelationDescriptor('subjects', RelationOwnership::Reference)];
    $partial = fingerprint_of_epoch_article();

    EpochArticle::$descriptors = [new RelationDescriptor('subjects', RelationOwnership::Reference, RelationCoverage::Complete)];
    $covered = fingerprint_of_epoch_article();

    expect($partial)->not->toBe($without_relations)
        ->and($covered)->not->toBe($partial);
});

it('reuses the epoch of a fingerprint that comes back', function (): void {
    create_epoch_article('First shape');
    $first = VersionEpoch::query()->sole();

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->string('subtitle')->nullable();
    });
    app(VersionEpochs::class)->flush();
    create_epoch_article('Second shape');

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->dropColumn('subtitle');
    });
    app(VersionEpochs::class)->flush();
    $back = create_epoch_article('First shape again');

    expect(VersionEpoch::query()->count())->toBe(2)
        ->and(array_unique(epochs_of_rows($back)))->toBe([$first->id]);
});

it('gives two classes on one table an epoch each', function (): void {
    create_epoch_article('Article');
    DB::transaction(static fn (): EpochArticleTwin => EpochArticleTwin::query()->create(['title' => 'Twin']));

    expect(VersionEpoch::query()->pluck('root_type')->sort()->values()->all())->toBe([
        (new EpochArticle)->getMorphClass(),
        (new EpochArticleTwin)->getMorphClass(),
    ])
        ->and(app(VersionEpochs::class)->hasSeveral((new EpochArticle)->getMorphClass()))->toBeFalse();
});

it('reads the translation columns that are the state of the owner', function (): void {
    $definition = (new EpochFingerprint)->definitionOf(new TranslatedDocument);

    expect(array_column($definition['columns'], 'name'))->toBe(['code', 'id'])
        ->and(array_column($definition['translation_columns'], 'name'))->toBe(['body', 'title'])
        ->and($definition['relations'])->toBe([]);
});
