<?php

declare(strict_types=1);

use Modules\Core\Enums\RelationOwnership;
use Modules\Core\Tests\Stubs\Versioning\Relations\SymmetricRelationRoot;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationRoot;

it('exposes the declared descriptor for a versioned relation', function (): void {
    $root = new VersionedRelationRoot();

    $descriptor = $root->versionedRelationDescriptor('categories');

    expect($descriptor)->not->toBeNull()
        ->and($descriptor->relation)->toBe('categories')
        ->and($descriptor->ownership)->toBe(RelationOwnership::Reference);
});

it('returns null for an undeclared relation', function (): void {
    expect((new VersionedRelationRoot())->versionedRelationDescriptor('unknown'))->toBeNull();
});

it('refuses to register a relation between records of the same class (D24)', function (): void {
    expect(fn () => (new SymmetricRelationRoot())->versionedRelationDescriptor('related'))
        ->toThrow(InvalidArgumentException::class, 'more than one root');
});
