<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\VersionedDocument;
use Modules\Core\Versioning\ActiveVersionSet;
use Modules\Core\Versioning\Contracts\VersionSetManagerInterface;
use Modules\Core\Versioning\Data\VersionSetOptions;
use Modules\Core\Versioning\Data\VersionSetRoot;
use Modules\Core\Versioning\Exceptions\DistinctVersionSetRootInstanceException;

/**
 * Inside one transaction the manager keeps one version set per root: a write of another root goes to that root's
 * own set, never to the set of the operation that caused it (D43).
 */
beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });

    // Created inside a transaction, so their creation is an atomic baseline and no write of the tests adds one (D47).
    [$this->first, $this->second] = (new VersionedDocument)->getConnection()->transaction(static fn (): array => [
        VersionedDocument::query()->create(['title' => 'First']),
        VersionedDocument::query()->create(['title' => 'Second']),
    ]);
    $this->manager = app(VersionSetManagerInterface::class);
    $this->sets_before = VersionSet::query()->max('id') ?? 0;
});

afterEach(function (): void {
    Schema::dropIfExists(VersionedDocument::TABLE);
});

/**
 * @return array<string, list<int>> the sequences of each set written by the test, keyed by its root id
 */
function sequences_per_root(int $after): array
{
    return VersionSet::query()->where('id', '>', $after)->orderBy('id')->get()
        ->mapWithKeys(static fn (VersionSet $set): array => [
            $set->root_id => $set->versions()->pluck('sequence')->map(static fn (mixed $sequence): int => (int) $sequence)->all(),
        ])->all();
}

it('gives a nested run for another root a set of its own', function (): void {
    $this->manager->run(VersionSetRoot::forModel($this->first), function (): void {
        $this->first->update(['title' => 'First edited']);
        $this->manager->run(VersionSetRoot::forModel($this->second), fn () => $this->second->update(['title' => 'Second edited']));
    });

    expect(sequences_per_root($this->sets_before))->toBe([
        (string) $this->first->getKey() => [1],
        (string) $this->second->getKey() => [1],
    ]);
});

it('rolls back both roots and both sets together', function (): void {
    expect(fn () => $this->manager->run(VersionSetRoot::forModel($this->first), function (): void {
        $this->first->update(['title' => 'First edited']);
        $this->manager->run(VersionSetRoot::forModel($this->second), function (): void {
            $this->second->update(['title' => 'Second edited']);

            throw new RuntimeException('boom');
        });
    }))->toThrow(RuntimeException::class);

    expect($this->first->fresh()->title)->toBe('First')
        ->and($this->second->fresh()->title)->toBe('Second')
        ->and(VersionSet::query()->where('id', '>', $this->sets_before)->count())->toBe(0);
});

it('writes a change of another root into that root set, without a run for it', function (): void {
    $this->manager->run(VersionSetRoot::forModel($this->first), function (): void {
        $this->first->update(['title' => 'First edited']);
        $this->second->update(['title' => 'Second edited']);
    });

    expect(sequences_per_root($this->sets_before))->toBe([
        (string) $this->first->getKey() => [1],
        (string) $this->second->getKey() => [1],
    ]);
});

it('opens the other root set with the ordinary kind and the actor of the outer operation', function (): void {
    $created = $this->first->versions()->firstOrFail();
    $this->first->update(['title' => 'First edited']);
    $this->sets_before = VersionSet::query()->max('id');

    $this->manager->run(
        VersionSetRoot::forModel($this->first),
        function (): void {
            $this->first->setRawAttributes(array_merge($this->first->getAttributes(), ['title' => 'First']));
            $this->first->save();
            $this->second->update(['title' => 'Second edited']);
        },
        new VersionSetOptions(kind: VersionSetKind::Revert, actor: 7, revertedFrom: (int) $created->version_set_id),
    );

    $sets = VersionSet::query()->where('id', '>', $this->sets_before)->orderBy('id')->get();

    expect($sets->pluck('kind')->all())->toBe([VersionSetKind::Revert, VersionSetKind::Change])
        ->and($sets->pluck('user_id')->all())->toBe([7, 7]);
});

it('opens a nested revert of another root with its own options', function (): void {
    $target = $this->second->versions()->firstOrFail();

    $this->manager->run(VersionSetRoot::forModel($this->first), function () use ($target): void {
        $this->manager->run(
            VersionSetRoot::forModel($this->second),
            fn () => $this->second->update(['title' => 'Second reverted']),
            new VersionSetOptions(kind: VersionSetKind::Revert, revertedFrom: (int) $target->version_set_id),
        );
    });

    $set = VersionSet::query()->where('id', '>', $this->sets_before)->sole();

    expect($set->kind)->toBe(VersionSetKind::Revert)
        ->and($set->root_id)->toBe((string) $this->second->getKey())
        ->and($set->reverted_from_set_id)->toBe($target->version_set_id);
});

it('joins the set of a root named twice in the same scope', function (): void {
    $this->manager->run(VersionSetRoot::forModel($this->first), function (): void {
        $this->manager->run(VersionSetRoot::forModel($this->second), fn () => $this->second->update(['title' => 'One']));
        $this->manager->run(VersionSetRoot::forModel($this->second), fn () => $this->second->update(['title' => 'Two']));
    });

    expect(sequences_per_root($this->sets_before))->toBe([(string) $this->second->getKey() => [1, 2]]);
});

it('still refuses a nested run for the same root on another instance', function (): void {
    $other_instance = VersionedDocument::query()->findOrFail($this->first->getKey());

    expect(fn () => $this->manager->run(
        VersionSetRoot::forModel($this->first),
        fn () => $this->manager->run(VersionSetRoot::forModel($other_instance), fn () => null),
    ))->toThrow(DistinctVersionSetRootInstanceException::class);
});

it('returns the set of the root named by the innermost run as the current one', function (): void {
    $seen = [];

    $this->manager->run(VersionSetRoot::forModel($this->first), function (ActiveVersionSet $outer) use (&$seen): void {
        $seen[] = $this->manager->current()?->root()->id();
        $this->manager->run(VersionSetRoot::forModel($this->second), function (ActiveVersionSet $inner) use (&$seen): void {
            $seen[] = $this->manager->current()?->root()->id();
            $seen[] = $inner->root()->id();
        });
        $seen[] = $this->manager->current()?->root()->id();
        $seen[] = $outer->root()->id();
    });

    $first = (string) $this->first->getKey();
    $second = (string) $this->second->getKey();

    expect($seen)->toBe([$first, $second, $second, $first, $first])
        ->and($this->manager->current())->toBeNull();
});

it('leaves no set for a root named in a scope that wrote nothing for it', function (): void {
    $this->manager->run(VersionSetRoot::forModel($this->first), function (): void {
        $this->first->update(['title' => 'First edited']);
        $this->manager->run(VersionSetRoot::forModel($this->second), fn () => null);
    });

    expect(array_keys(sequences_per_root($this->sets_before)))->toBe([$this->first->getKey()]);
});
