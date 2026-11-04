<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Authorization\PartOwnerResolver;
use Modules\Core\Tests\Stubs\Crud\RelationCyclicPartStub;
use Modules\Core\Tests\Stubs\Crud\RelationMisdeclaredPartStub;
use Modules\Core\Tests\Stubs\Crud\RelationOwnerStub;
use Modules\Core\Tests\Stubs\Crud\RelationPartStub;
use Modules\Core\Tests\Stubs\Crud\RelationSubPartStub;

/*
 * The chain of a part is every model up its parent relations, nearest first, ending with the first one that is not
 * a part. A chain that does not resolve answers null, so the hydration check keeps the part's own permission instead
 * of allowing.
 */

it('resolves the chain of a part and of a part of a part', function (): void {
    expect(PartOwnerResolver::chainClassesOf(new RelationPartStub))->toBe([RelationOwnerStub::class])
        ->and(PartOwnerResolver::chainClassesOf(new RelationSubPartStub))->toBe([RelationPartStub::class, RelationOwnerStub::class]);
});

it('resolves no chain for a model that is not a part', function (): void {
    expect(PartOwnerResolver::chainClassesOf(new RelationOwnerStub))->toBeNull();
});

it('resolves no chain when it does not reach an owner', function (): void {
    expect(PartOwnerResolver::chainClassesOf(new RelationMisdeclaredPartStub))->toBeNull()
        ->and(PartOwnerResolver::chainClassesOf(new RelationCyclicPartStub))->toBeNull();
});

it('resolves the chain of a part from a record retrieved without its foreign key', function (): void {
    new ReflectionProperty(PartOwnerResolver::class, 'chains')->setValue(null, []);
    $strict = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();

    try {
        $retrieved_without_key = new RelationPartStub()->newFromBuilder(['id' => 1]);

        expect(PartOwnerResolver::chainClassesOf($retrieved_without_key))->toBe([RelationOwnerStub::class])
            ->and(PartOwnerResolver::chainClassesOf(new RelationPartStub))->toBe([RelationOwnerStub::class]);
    } finally {
        Model::preventAccessingMissingAttributes($strict);
    }
});
