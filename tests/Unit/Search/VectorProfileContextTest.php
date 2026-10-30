<?php

declare(strict_types=1);

use Modules\Core\Search\DTOs\VectorProfile;
use Modules\Core\Search\Services\NullVectorProfileProvider;
use Modules\Core\Search\Support\VectorProfileContext;
use Modules\Core\Tests\Support\FakeVectorProfile;

it('returns null when the provider has no profile', function (): void {
    FakeVectorProfile::none();

    expect(VectorProfileContext::get())->toBeNull()
        ->and(VectorProfileContext::modelKey())->toBeNull()
        ->and((new NullVectorProfileProvider())->current())->toBeNull();
});

it('returns the provider profile outside using()', function (): void {
    $profile = new VectorProfile('a:one', 384, 'cosine');
    FakeVectorProfile::use($profile);

    expect(VectorProfileContext::get())->toBe($profile);
});

it('overrides the whole profile inside using() and returns the callback result', function (): void {
    FakeVectorProfile::use();
    $override = new VectorProfile('b:two', 1024, 'dot_product');

    $seen = VectorProfileContext::using($override, static fn (): ?VectorProfile => VectorProfileContext::get());

    expect($seen)->toBe($override)
        ->and($seen->modelKey)->toBe('b:two')
        ->and($seen->dimensions)->toBe(1024)
        ->and($seen->similarity)->toBe('dot_product')
        ->and(VectorProfileContext::modelKey())->toBe('a:one');
});

it('supports nested overrides', function (): void {
    FakeVectorProfile::use();
    $b = new VectorProfile('b:two', 1024, 'dot_product');
    $c = new VectorProfile('c:three', 768, 'l2_norm');

    $seen = VectorProfileContext::using($b, static function () use ($c): array {
        $outer = VectorProfileContext::modelKey();
        $inner = VectorProfileContext::using($c, static fn (): ?string => VectorProfileContext::modelKey());

        return [$outer, $inner, VectorProfileContext::modelKey()];
    });

    expect($seen)->toBe(['b:two', 'c:three', 'b:two']);
});

it('restores the previous profile when the callback throws', function (): void {
    FakeVectorProfile::use();

    try {
        VectorProfileContext::using(new VectorProfile('b:two', 1024, 'dot_product'), static function (): never {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(VectorProfileContext::get()?->modelKey)->toBe('a:one');
});

it('returns the key of the profile or null from modelKey()', function (): void {
    FakeVectorProfile::use(new VectorProfile('a:one', 384, 'cosine'));
    expect(VectorProfileContext::modelKey())->toBe('a:one');

    FakeVectorProfile::none();
    expect(VectorProfileContext::modelKey())->toBeNull();
});
