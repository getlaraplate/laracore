<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Database\Factories\VersionEpochFactory;
use Modules\Core\Enums\CoreTables;
use Override;

/**
 * One versioned shape of a root class: the columns, translation columns and relations its history records.
 * A row is written the first time a process sees the shape and is never updated nor removed (D13, D32, D33).
 *
 * @property int $id
 * @property string $root_type
 * @property string $fingerprint
 * @property array<string, mixed> $definition
 * @property \Carbon\CarbonImmutable $first_seen_at
 */
final class VersionEpoch extends Model
{
    use HasFactory;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::VersionsEpochs->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'root_type',
        'fingerprint',
        'definition',
        'first_seen_at',
    ];

    protected static function newFactory(): VersionEpochFactory
    {
        return VersionEpochFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'first_seen_at' => 'immutable_datetime',
        ];
    }
}
