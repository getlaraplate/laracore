<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Laravel\Passkeys\Passkey as BasePasskey;
use Modules\Core\Enums\CoreTables;
use Override;

/**
 * The package model on the Core-prefixed table, so every Core table follows one naming rule.
 *
 * @property int $id
 * @property int $user_id The user who registered the passkey
 * @property string $name The name the user gave to the passkey
 * @property string $credential_id The WebAuthn credential identifier
 * @property \Carbon\CarbonImmutable|null $last_used_at The last login made with the passkey
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property array<array-key, mixed> $credential The public key credential
 */
final class Passkey extends BasePasskey
{
    #[Override]
    protected $table = CoreTables::Passkeys->value;
}
