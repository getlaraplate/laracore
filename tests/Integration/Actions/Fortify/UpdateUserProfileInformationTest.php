<?php

declare(strict_types=1);

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\Fortify\UpdateUserProfileInformation;
use Modules\Core\Models\Concerns\HasValidations;
use Modules\Core\Models\User;

it('updates user name and email when email unchanged', function (): void {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'user@example.com',
        'email_verified_at' => now(),
    ]);

    $action = new UpdateUserProfileInformation;
    $action->update($user, [
        'id' => $user->id,
        'name' => 'New Name',
        'email' => 'user@example.com',
    ]);

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('user@example.com')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('nulls email_verified_at and updates when email changed for MustVerifyEmail user', function (): void {
    $base_user = User::factory()->create([
        'name' => 'User',
        'email' => 'old@example.com',
        'email_verified_at' => now(),
    ]);

    $user = new class extends User
    {
        public bool $verification_sent = false;

        public function sendEmailVerificationNotification(): void
        {
            $this->verification_sent = true;
        }
    };
    $user->setTable('users');
    $user->setConnection(config('database.default'));
    $user->exists = true;
    $user->setRawAttributes(array_merge($base_user->getAttributes(), ['deleted_at' => null]), true);

    $action = new UpdateUserProfileInformation;
    $action->update($user, [
        'id' => (string) $user->getKey(),
        'name' => 'User',
        'email' => 'new@example.com',
    ]);

    $fresh = User::find($user->getKey());
    expect($fresh->email)->toBe('new@example.com')
        ->and($fresh->email_verified_at)->toBeNull()
        ->and($user->verification_sent)->toBeTrue();
});

it('updates only name when input has no email change', function (): void {
    $user = User::factory()->create([
        'name' => 'Original',
        'email' => 'original@example.com',
    ]);

    $action = new UpdateUserProfileInformation;
    $action->update($user, [
        'id' => $user->id,
        'name' => 'Updated Name',
        'email' => 'original@example.com',
    ]);

    $user->refresh();
    expect($user->name)->toBe('Updated Name')
        ->and($user->email)->toBe('original@example.com');
});

it('throws validation exception when email is invalid', function (): void {
    $user = User::factory()->create(['email' => 'valid@example.com']);
    $action = new UpdateUserProfileInformation;

    expect(fn () => $action->update($user, [
        'id' => $user->id,
        'name' => 'User',
        'email' => 'not-an-email',
    ]))->toThrow(ValidationException::class);
});

it('throws validation exception when name exceeds max length', function (): void {
    $user = User::factory()->create();
    $action = new UpdateUserProfileInformation;

    expect(fn () => $action->update($user, [
        'id' => $user->id,
        'name' => str_repeat('a', 256),
        'email' => $user->email,
    ]))->toThrow(ValidationException::class);
});

it('removes password_confirmation and current_password before force fill', function (): void {
    $base_user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old-pass@example.com',
    ]);

    $user = new class extends User
    {
        public array $captured_payload = [];

        public function forceFill(array $attributes): static
        {
            $this->captured_payload = $attributes;

            return parent::forceFill($attributes);
        }
    };
    $user->setTable('users');
    $user->setConnection(config('database.default'));
    $user->exists = true;
    $user->setRawAttributes(array_merge($base_user->getAttributes(), ['deleted_at' => null]), true);

    $action = new UpdateUserProfileInformation;
    $action->update($user, [
        'id' => (string) $user->getKey(),
        'name' => 'New Name',
        'email' => 'old-pass@example.com',
    ]);

    expect($user->captured_payload)->not->toHaveKey('password_confirmation')
        ->and($user->captured_payload)->not->toHaveKey('current_password');
});

it('uses validation rules from HasValidations trait when available', function (): void {
    $user = new class extends User
    {
        use HasValidations;

        public function getOperationRules(?string $operation = null): array
        {
            expect($operation)->toBe('update');

            return [
                'name' => ['required', 'string'],
                'email' => ['required', 'email'],
            ];
        }

        public function save(array $options = []): bool
        {
            return true;
        }
    };
    $user->id = 'fake-id';
    $user->name = 'Initial Name';
    $user->email = 'initial@example.com';

    $action = new UpdateUserProfileInformation;
    $action->update($user, [
        'name' => 'Updated Name',
        'email' => 'initial@example.com',
    ]);

    expect($user->name)->toBe('Updated Name');
});

it('uses fallback rules when user does not use HasValidations trait', function (): void {
    $user = new class extends Model implements Illuminate\Contracts\Auth\Authenticatable, MustVerifyEmail
    {
        use Authenticatable;

        public bool $saved = false;

        public bool $verification_sent = false;

        protected $table = 'users';

        public function forceFill(array $attributes): static
        {
            foreach ($attributes as $key => $value) {
                $this->setAttribute($key, $value);
            }

            return $this;
        }

        public function save(array $options = []): bool
        {
            $this->saved = true;

            return true;
        }

        public function sendEmailVerificationNotification(): void
        {
            $this->verification_sent = true;
        }

        public function hasVerifiedEmail(): bool
        {
            return $this->email_verified_at !== null;
        }

        public function markEmailAsVerified(): bool
        {
            $this->email_verified_at = now();

            return true;
        }

        public function markEmailAsUnverified(): bool
        {
            $this->email_verified_at = null;

            return true;
        }

        public function getEmailForVerification(): string
        {
            return (string) $this->email;
        }
    };
    $user->id = 999999;
    $user->name = 'Fallback User';
    $user->email = 'fallback.old@example.test';

    $action = new UpdateUserProfileInformation;
    $action->update($user, [
        'name' => 'Fallback Updated',
        'email' => 'fallback.new@example.test',
    ]);

    expect($user->saved)->toBeTrue()
        ->and($user->verification_sent)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();
});
