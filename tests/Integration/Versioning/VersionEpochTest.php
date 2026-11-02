<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\RelationCoverage;
use Modules\Core\Enums\RelationOwnership;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionEpoch;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\EpochArticle;
use Modules\Core\Tests\Stubs\Versioning\EpochArticleTwin;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\RelationDescriptor;
use Modules\Core\Versioning\Data\VersionSetRoot;
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
    Schema::create(VersionedRelationRoot::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create(VersionedRelationRoot::PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
        $table->unsignedInteger('position')->default(0);
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
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
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

it('anchors a record again at its first save after an epoch change, in the new epoch (D33)', function (): void {
    $article = create_epoch_article('Before');

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->string('subtitle')->nullable();
    });
    app(VersionEpochs::class)->flush();
    DB::transaction(static fn (): bool => $article->update(['title' => 'After']));

    $set = VersionSet::query()->latest('id')->firstOrFail();
    $new_epoch = VersionEpoch::query()->latest('id')->firstOrFail();

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and($set->baseline_from_sequence)->not->toBeNull()
        ->and($set->versions()->pluck('epoch_id')->unique()->values()->all())->toBe([$new_epoch->id]);
});

it('anchors a root at its first pivot change after an epoch change (D45)', function (): void {
    $root = DB::transaction(static fn (): VersionedRelationRoot => VersionedRelationRoot::query()->create(['title' => 'Root']));
    $subject = VersionedRelationSubject::query()->create(['name' => 'A']);
    $older_epoch = VersionEpoch::factory()->create(['root_type' => $root->getMorphClass()]);
    Version::query()->update(['epoch_id' => $older_epoch->id]);
    app(VersionEpochs::class)->flush();

    app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($root),
        fn () => $root->categories()->attach($subject->getKey(), ['position' => 1]),
    );

    expect(VersionSet::query()->latest('id')->firstOrFail()->kind)->toBe(VersionSetKind::Baseline);
});

it('writes no baseline at a save in the epoch the record was last written in', function (): void {
    $article = create_epoch_article('Before');
    VersionEpoch::factory()->create(['root_type' => $article->getMorphClass()]);
    app(VersionEpochs::class)->flush();

    DB::transaction(static fn (): bool => $article->update(['title' => 'After']));

    expect(VersionSet::query()->latest('id')->firstOrFail()->kind)->toBe(VersionSetKind::Change);
});

it('forgets an epoch whose first write rolled back, so the next write of the class succeeds', function (): void {
    create_epoch_article('Before');
    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->string('subtitle')->nullable();
    });
    app(VersionEpochs::class)->flush();

    try {
        DB::transaction(static function (): void {
            EpochArticle::query()->create(['title' => 'Rolled back']);

            throw new RuntimeException('The write fails after its version rows.');
        });
    } catch (RuntimeException) {
    }

    $after = create_epoch_article('After');

    expect(VersionEpoch::query()->count())->toBe(2)
        ->and(array_unique(epochs_of_rows($after)))->toBe([VersionEpoch::query()->latest('id')->firstOrFail()->id]);
});

it('keeps a baseline due after an epoch change until a set that can carry it (D33, D60)', function (): void {
    $article = create_epoch_article('Before');
    $created = Version::query()->where('versionable_id', $article->getKey())->firstOrFail();
    DB::transaction(static fn (): bool => $article->update(['title' => 'Middle']));

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->string('subtitle')->nullable();
    });
    app(VersionEpochs::class)->flush();
    $article->revertToVersion($created->getKey());

    expect(VersionSet::query()->latest('id')->firstOrFail()->kind)->toBe(VersionSetKind::Revert);

    DB::transaction(static fn (): bool => $article->refresh()->update(['title' => 'After']));

    expect(VersionSet::query()->latest('id')->firstOrFail()->kind)->toBe(VersionSetKind::Baseline);
});

it('keeps a baseline due after an epoch change written through another root\'s scope', function (): void {
    $article = create_epoch_article('Before');
    $other = create_epoch_article('Other');

    Schema::table(EpochArticle::TABLE, function (Blueprint $table): void {
        $table->string('subtitle')->nullable();
    });
    app(VersionEpochs::class)->flush();
    app(VersionSetManagerInterface::class)->run(
        VersionSetRoot::forModel($other),
        static fn (): bool => $article->update(['title' => 'Through the other root']),
    );
    DB::transaction(static fn (): bool => $article->update(['title' => 'After']));

    expect(VersionSet::query()->latest('id')->firstOrFail()->kind)->toBe(VersionSetKind::Baseline);
});

it('anchors once a record whose rows predate the epochs, when its class has several', function (): void {
    $article = create_epoch_article('Before');
    Version::query()->update(['epoch_id' => null]);
    VersionEpoch::factory()->create(['root_type' => $article->getMorphClass()]);
    app(VersionEpochs::class)->flush();

    DB::transaction(static fn (): bool => $article->update(['title' => 'First']));
    $first = VersionSet::query()->latest('id')->firstOrFail()->kind;
    DB::transaction(static fn (): bool => $article->update(['title' => 'Second']));

    expect($first)->toBe(VersionSetKind::Baseline)
        ->and(VersionSet::query()->latest('id')->firstOrFail()->kind)->toBe(VersionSetKind::Change);
});
