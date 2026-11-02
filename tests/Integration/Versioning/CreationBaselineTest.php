<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;

/**
 * The set that holds the creation of a root is stored as a baseline, a complete anchor (D53); the scope keeps
 * its ordinary options, and a revert set never becomes one (D60).
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedDocument::TABLE);
});

/**
 * @return list<VersionSetKind>
 */
function stored_set_kinds(): array
{
    return VersionSet::query()->orderBy('id')->pluck('kind')->all();
}

it('stores the creation set of a root as a baseline and later sets as changes', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'Before']);
    $document->update(['title' => 'After']);

    expect(stored_set_kinds())->toBe([VersionSetKind::Baseline, VersionSetKind::Change]);
});

it('lets a nested run with ordinary options join a set stored as a baseline', function (): void {
    $document = new VersionedDocument(['title' => 'Before']);
    $manager = app(VersionSetManagerInterface::class);

    $manager->run(VersionSetRoot::forModel($document), function () use ($document, $manager): void {
        $document->save();
        $manager->run(
            VersionSetRoot::forModel($document),
            fn () => $document->update(['title' => 'After']),
            new VersionSetOptions,
        );
    });

    expect(stored_set_kinds())->toBe([VersionSetKind::Baseline])
        ->and(Version::query()->count())->toBe(2);
});

it('never stores a revert set as a baseline', function (): void {
    $document = VersionedDocument::query()->create(['title' => 'Before']);
    $created = $document->versions()->firstOrFail();
    $document->update(['title' => 'After']);

    $document->revertToVersion($created->getKey());

    expect(stored_set_kinds())->toBe([VersionSetKind::Baseline, VersionSetKind::Change, VersionSetKind::Revert]);
});

it('stores the creation of a root inside another root scope as that root own baseline', function (): void {
    $outer = VersionedDocument::query()->create(['title' => 'Outer']);
    $outer->getConnection()->transaction(fn () => $outer->update(['title' => 'Outer anchored']));
    $before = VersionSet::query()->max('id');
    $manager = app(VersionSetManagerInterface::class);

    $manager->run(VersionSetRoot::forModel($outer), function () use ($outer): void {
        $outer->update(['title' => 'Outer edited']);
        VersionedDocument::query()->create(['title' => 'Inner']);
    });

    $sets = VersionSet::query()->where('id', '>', $before)->orderBy('id')->get();

    expect($sets->pluck('kind')->all())->toBe([VersionSetKind::Change, VersionSetKind::Baseline])
        ->and($sets->pluck('root_id')->all())->toBe([(string) $outer->getKey(), (string) VersionedDocument::query()->where('title', 'Inner')->value('id')]);
});

it('lets a nested run with ordinary options join the baseline set of a root created inside another scope', function (): void {
    $outer = VersionedDocument::query()->create(['title' => 'Outer']);
    $inner = new VersionedDocument(['title' => 'Inner']);
    $before = VersionSet::query()->max('id');
    $manager = app(VersionSetManagerInterface::class);

    $manager->run(VersionSetRoot::forModel($outer), function () use ($inner, $manager): void {
        $manager->run(VersionSetRoot::forModel($inner), function () use ($inner, $manager): void {
            $inner->save();
            $manager->run(VersionSetRoot::forModel($inner), fn () => $inner->update(['title' => 'Inner edited']), new VersionSetOptions);
        });
    });

    $set = VersionSet::query()->where('id', '>', $before)->sole();

    expect($set->kind)->toBe(VersionSetKind::Baseline)
        ->and($set->versions()->count())->toBe(2);
});
