<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Versioning\ApplicationTransactions;

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
    Schema::create(VersionedRelationRoot::UNREGISTERED_PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
    });
    Schema::create(VersionedRelationRoot::PIVOT_TABLE, function (Blueprint $table): void {
        $table->unsignedBigInteger('root_id');
        $table->unsignedBigInteger('subject_id');
        $table->unsignedInteger('position')->default(0);
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedRelationRoot::UNREGISTERED_PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationRoot::PIVOT_TABLE);
    Schema::dropIfExists(VersionedRelationSubject::TABLE);
    Schema::dropIfExists(VersionedRelationRoot::TABLE);
});

it('syncs membership to a target set as one revision', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $b = VersionedRelationSubject::query()->create(['name' => 'B']);
    $c = VersionedRelationSubject::query()->create(['name' => 'C']);

    $root->categories()->attach($a->getKey(), ['position' => 1]);
    $root->categories()->attach($b->getKey(), ['position' => 2]);

    $root->categories()->sync([
        $b->getKey() => ['position' => 9],
        $c->getKey() => ['position' => 3],
    ]);

    $membership = collect($root->versionedRelationMembership('categories'))->keyBy('id');

    expect($root->categories()->count())->toBe(2)
        ->and($membership->keys()->sort()->values()->all())
        ->toBe(collect([$b->getKey(), $c->getKey()])->sort()->values()->all())
        ->and($membership[$b->getKey()]['pivot'])->toBe(['position' => 9])
        ->and($membership[$c->getKey()]['pivot'])->toBe(['position' => 3]);

    // The whole sync is one version set (one revision): detach A + upsert B + attach C = 3 rows.
    $last_set_id = Version::query()->where('relation_path', 'categories')->max('version_set_id');

    expect(Version::query()->where('version_set_id', $last_set_id)->where('relation_path', 'categories')->count())->toBe(3);
});

it('syncing to an empty target detaches every member', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $a = VersionedRelationSubject::query()->create(['name' => 'A']);
    $root->categories()->attach($a->getKey(), ['position' => 1]);

    $root->categories()->sync([]);

    expect($root->categories()->count())->toBe(0)
        ->and($root->versionedRelationMembership('categories'))->toBe([]);
});

it('makes a sync of three subjects from the root one atomic set (D42)', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $subjects = collect(['A', 'B', 'C'])->map(static fn (string $name): VersionedRelationSubject => VersionedRelationSubject::query()->create(['name' => $name]));
    $before = VersionSet::query()->max('id');

    $root->categories()->sync($subjects->map(static fn (VersionedRelationSubject $subject): int => $subject->getKey())->all());

    $set = VersionSet::query()->where('id', '>', $before)->sole();

    expect($set->capture)->toBe(VersionCaptureQuality::Atomic)
        ->and($set->versions()->count())->toBe(3);
});

it('makes a sync from the inverse side one atomic set per root it touches (D42, D43)', function (): void {
    $first = VersionedRelationRoot::query()->create(['title' => 'First']);
    $second = VersionedRelationRoot::query()->create(['title' => 'Second']);
    $subject = VersionedRelationSubject::query()->create(['name' => 'Shared']);
    $before = VersionSet::query()->max('id');

    $subject->roots()->sync([$first->getKey(), $second->getKey()]);

    $sets = VersionSet::query()->where('id', '>', $before)->orderBy('id')->get();

    expect($sets->pluck('root_id')->all())->toBe([(string) $first->getKey(), (string) $second->getKey()])
        ->and($sets->pluck('capture')->unique()->all())->toBe([VersionCaptureQuality::Atomic])
        ->and($sets->map(static fn (VersionSet $set): int => $set->versions()->count())->all())->toBe([1, 1]);
});

it('opens no scope for the mutators of a relation the root does not register', function (): void {
    $root = VersionedRelationRoot::query()->create(['title' => 'First']);
    $subject = VersionedRelationSubject::query()->create(['name' => 'A']);
    $before = VersionSet::query()->count();
    $in_transaction = [];
    $root->getConnection()->listen(function () use (&$in_transaction, $root): void {
        $in_transaction[] = ApplicationTransactions::isOpenOn($root->getConnection());
    });

    $root->labels()->sync([$subject->getKey()]);

    expect(VersionSet::query()->count())->toBe($before)
        ->and($in_transaction)->not->toContain(true);
});
