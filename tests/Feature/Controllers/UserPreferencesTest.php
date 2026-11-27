<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Modules\Core\Models\User;
use Modules\Core\Rules\PreferencesBag;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

/**
 * A value nested in $levels arrays, so the bag holding it is $levels deep.
 *
 * @return array<string, mixed>
 */
function preferencesNestedBag(int $levels): array
{
    $value = 'leaf';

    for ($i = 1; $i < $levels; $i++) {
        $value = ['next' => $value];
    }

    return ['ui' => $value];
}

test('update preferences rejects a bag larger than the limit', function (): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), [
        'preferences' => ['ui' => ['blob' => str_repeat('a', PreferencesBag::MAX_BYTES)]],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferences']);

    expect($this->user->refresh()->preferences)->toBeNull();
});

test('update preferences rejects a bag deeper than the limit and accepts one at the limit', function (): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), [
        'preferences' => preferencesNestedBag(PreferencesBag::MAX_DEPTH + 1),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferences']);

    $this->patchJson(route('core.auth.updatePreferences'), [
        'preferences' => preferencesNestedBag(PreferencesBag::MAX_DEPTH),
    ])->assertOk();
});

test('update preferences rejects a top-level key that is not a valid namespace', function (string $key): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => [$key => ['a' => 1]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferences']);
})->with([
    'upper case' => ['Theme'],
    'leading digit' => ['1ui'],
    'space' => ['my ui'],
    'too long' => [str_repeat('a', 41)],
    'numeric key' => ['0'],
]);

test('update preferences accepts valid namespaces', function (): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), [
        'preferences' => ['ui' => ['theme' => 'dark'], 'laraplate-ui.v2' => ['density' => 'compact']],
    ])->assertOk();

    expect($this->user->refresh()->preferences)->toBe([
        'ui' => ['theme' => 'dark'],
        'laraplate-ui.v2' => ['density' => 'compact'],
    ]);
});

test('the preferences rule accepts only JSON-compatible values', function (mixed $value, bool $valid): void {
    $validator = Validator::make(['preferences' => ['ui' => $value]], ['preferences' => [new PreferencesBag]]);

    expect($validator->passes())->toBe($valid);
})->with([
    'string' => ['text', true],
    'int' => [1, true],
    'float' => [1.5, true],
    'bool' => [false, true],
    'null' => [null, true],
    'nested list' => [[1, 'two', ['three' => null]], true],
    'object' => [new stdClass, false],
    'closure' => [fn (): int => 1, false],
    'infinite float' => [INF, false],
    'invalid utf-8' => ["\xB1\x31", false],
]);

test('update preferences keeps the namespaces it does not receive', function (): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['theme' => 'dark']]])->assertOk();
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['other' => ['a' => 1]]])
        ->assertOk()
        ->assertJsonPath('data.preferences.ui.theme', 'dark')
        ->assertJsonPath('data.preferences.other.a', 1);

    expect($this->user->refresh()->preferences)->toBe(['ui' => ['theme' => 'dark'], 'other' => ['a' => 1]]);
});

test('update preferences replaces a received namespace as a whole', function (): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['theme' => 'dark', 'density' => 'compact']]])->assertOk();
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['theme' => 'light']]])->assertOk();

    expect($this->user->refresh()->preferences)->toBe(['ui' => ['theme' => 'light']]);
});

test('update preferences removes a namespace sent as null', function (): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['a' => 1], 'other' => ['b' => 2]]])->assertOk();
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => null]])->assertOk();

    expect($this->user->refresh()->preferences)->toBe(['other' => ['b' => 2]]);
});

test('update preferences rejects a write that makes the merged bag exceed the limit', function (): void {
    $this->actingAs($this->user);
    $half = intdiv(PreferencesBag::MAX_BYTES, 2) + 100;

    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['one' => ['blob' => str_repeat('a', $half)]]])->assertOk();
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['two' => ['blob' => str_repeat('b', $half)]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferences']);

    expect(array_keys($this->user->refresh()->preferences))->toBe(['one']);
});

test('delete preferences clears the whole bag', function (): void {
    $this->actingAs($this->user);
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['a' => 1], 'other' => ['b' => 2]]])->assertOk();

    $this->deleteJson(route('core.auth.deletePreferences'))
        ->assertOk()
        ->assertJsonPath('data.preferences', []);

    expect($this->user->refresh()->preferences)->toBe([]);
});

test('delete preferences namespace clears only that namespace', function (): void {
    $this->actingAs($this->user);
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['a' => 1], 'other' => ['b' => 2]]])->assertOk();

    $this->deleteJson(route('core.auth.deletePreferencesNamespace', ['namespace' => 'ui']))->assertOk();

    expect($this->user->refresh()->preferences)->toBe(['other' => ['b' => 2]]);
});

test('delete preferences namespace refuses a name that is not a namespace', function (): void {
    $this->actingAs($this->user);

    $this->deleteJson('/app/auth/user/preferences/Not%20Valid')->assertNotFound();
});

test('delete preferences requires authentication', function (): void {
    $this->deleteJson(route('core.auth.deletePreferences'))->assertStatus(401);
    $this->deleteJson(route('core.auth.deletePreferencesNamespace', ['namespace' => 'ui']))->assertStatus(401);
});

test('a user reaches only their own preferences', function (): void {
    $other = User::factory()->create();

    $this->actingAs($this->user);
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['a' => 1]]])->assertOk();

    $this->flushSession();
    $this->actingAs($other);
    $this->deleteJson(route('core.auth.deletePreferences'))->assertOk();
    $this->deleteJson(route('core.auth.deletePreferencesNamespace', ['namespace' => 'ui']))->assertOk();
    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => null], 'id' => $this->user->id, 'user_id' => $this->user->id])->assertOk();

    expect($this->user->refresh()->preferences)->toBe(['ui' => ['a' => 1]]);
});

test('update preferences saves the language and the timezone of the caller', function (): void {
    config(['app.available_locales' => ['en', 'it']]);
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['lang' => 'it', 'timezone' => 'Europe/Rome'])
        ->assertOk()
        ->assertJsonPath('data.lang', 'it')
        ->assertJsonPath('data.timezone', 'Europe/Rome');

    expect($this->user->refresh()->lang)->toBe('it')
        ->and($this->user->timezone)->toBe('Europe/Rome')
        ->and($this->user->preferences)->toBeNull();
});

test('update preferences leaves the columns it does not receive as they are', function (): void {
    $this->user->forceFill(['lang' => 'it', 'timezone' => 'Asia/Tokyo'])->save();
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['preferences' => ['ui' => ['a' => 1]]])->assertOk();

    expect($this->user->refresh()->lang)->toBe('it')
        ->and($this->user->timezone)->toBe('Asia/Tokyo')
        ->and($this->user->preferences)->toBe(['ui' => ['a' => 1]]);
});

test('update preferences clears the timezone when it is null', function (): void {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['timezone' => null])
        ->assertOk()
        ->assertJsonPath('data.timezone', null);

    expect($this->user->refresh()->timezone)->toBeNull();
});

test('update preferences refuses a timezone that is not an IANA identifier', function (string $timezone): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['timezone' => $timezone])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['timezone']);

    expect($this->user->refresh()->timezone)->toBeNull();
})->with([
    'offset' => '+02:00',
    'abbreviation' => 'CET',
    'unknown name' => 'Mars/Olympus',
]);

test('update preferences refuses a language that is not available', function (): void {
    config(['app.available_locales' => ['en', 'it']]);
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), ['lang' => 'xx'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lang']);
});

test('update preferences refuses a request that carries nothing to update', function (): void {
    $this->actingAs($this->user);

    $this->patchJson(route('core.auth.updatePreferences'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['preferences']);
});
