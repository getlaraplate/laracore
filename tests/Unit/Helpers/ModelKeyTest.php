<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Core\Helpers\ModelKey;
use Modules\Core\Models\User;

it('reads an integer or a string key as stored', function (): void {
    expect(ModelKey::of(new User()->forceFill(['id' => 7])))->toBe(7)
        ->and(ModelKey::of(new Pivot()->forceFill(['id' => 'a1'])))->toBe('a1');
});

it('reads no key from a model that has none yet', function (): void {
    expect(ModelKey::of(new User()))->toBeNull();
});

it('reads no key from a value no column can hold as a key', function (): void {
    expect(ModelKey::of(new Pivot()->forceFill(['id' => ['a', 'b']])))->toBeNull();
});
