<?php

declare(strict_types=1);

use Modules\Core\Enums\RelationCoverage;
use Modules\Core\Enums\RelationOwnership;
use Modules\Core\Versioning\Data\RelationDescriptor;

it('describes a reference relation as not owned', function (): void {
    $descriptor = new RelationDescriptor('categories', RelationOwnership::Reference);

    expect($descriptor->relation)->toBe('categories')
        ->and($descriptor->ownership)->toBe(RelationOwnership::Reference)
        ->and($descriptor->isOwned())->toBeFalse();
});

it('describes an owned relation as owned', function (): void {
    $descriptor = new RelationDescriptor('blocks', RelationOwnership::Owned);

    expect($descriptor->isOwned())->toBeTrue();
});

it('declares a relation partially covered unless its module says otherwise (D57)', function (): void {
    expect((new RelationDescriptor('categories', RelationOwnership::Reference))->coverage)->toBe(RelationCoverage::Partial)
        ->and((new RelationDescriptor('categories', RelationOwnership::Reference, RelationCoverage::Complete))->coverage)->toBe(RelationCoverage::Complete);
});
