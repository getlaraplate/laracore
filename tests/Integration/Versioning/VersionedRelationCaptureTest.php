<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionChangeType;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Version;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;

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
    Schema::create(VersionedRelationRoot::UNREGISTERED_PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
    });

    $this->root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $this->subject = VersionedRelationSubject::query()->create(['name' => 'Subject']);
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedRelationRoot::UNREGISTERED_PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
});

/**
 * Membership rows come from the events of the custom pivot, from either side of the relation, so every caller is
 * covered without calling versioning methods (D41, D42).
 */
function membership_rows(VersionedRelationRoot $root): array
{
    return $root->versions()->where('relation_path', 'categories')->reorder('id')->get()
        ->map(static fn (Version $row): array => [$row->change_type, $row->subject_key, $row->contents])
        ->all();
}

it('records an attach as a created membership row with the declared pivot columns', function (): void {
    $this->root->categories()->attach($this->subject->getKey(), ['position' => 2]);

    $row = $this->root->versions()->where('relation_path', 'categories')->sole();

    expect($row->change_type)->toBe(VersionChangeType::Created)
        ->and($row->version_strategy)->toBe(VersionStrategy::SNAPSHOT)
        ->and($row->subject_key)->toBe(['id' => $this->subject->getKey()])
        ->and($row->contents)->toBe(['position' => 2])
        ->and($row->versionable_id)->toBe($this->root->getKey());
});

it('records a detach, also without ids, as deleted membership rows', function (): void {
    $other = VersionedRelationSubject::query()->create(['name' => 'Other']);
    $this->root->categories()->attach([$this->subject->getKey(), $other->getKey()]);

    $this->root->categories()->detach($this->subject->getKey());
    $this->root->categories()->detach();

    expect(collect(membership_rows($this->root))->map(static fn (array $row): string => $row[0]->value . ':' . $row[1]['id'])->all())->toBe([
        'created:' . $this->subject->getKey(),
        'created:' . $other->getKey(),
        'deleted:' . $this->subject->getKey(),
        'deleted:' . $other->getKey(),
    ]);
});

it('records a change of a declared pivot column', function (): void {
    $this->root->categories()->attach($this->subject->getKey(), ['position' => 1]);

    $this->root->categories()->updateExistingPivot($this->subject->getKey(), ['position' => 5]);

    expect(membership_rows($this->root)[1])->toBe([VersionChangeType::Updated, ['id' => $this->subject->getKey()], ['position' => 5]]);
});

it('records a link made from the inverse side on the root', function (): void {
    $this->subject->roots()->attach($this->root->getKey(), ['position' => 3]);

    expect(membership_rows($this->root))->toBe([[VersionChangeType::Created, ['id' => $this->subject->getKey()], ['position' => 3]]]);
});

it('records nothing for a relation the root does not register', function (): void {
    $this->root->labels()->attach($this->subject->getKey());

    expect($this->root->versions()->whereNotNull('relation_path')->count())->toBe(0);
});

it('records nothing when the history of the root is off', function (): void {
    VersionedRelationRoot::disableVersioning();

    try {
        $this->root->categories()->attach($this->subject->getKey());
    } finally {
        VersionedRelationRoot::enableVersioning();
    }

    expect(membership_rows($this->root))->toBe([]);
});

it('records nothing for a raw query on the pivot table', function (): void {
    $this->root->getConnection()->table(VersionedRelationRoot::PIVOT_TABLE)->insert(['root_id' => $this->root->getKey(), 'subject_id' => $this->subject->getKey()]);

    expect(membership_rows($this->root))->toBe([]);
});
