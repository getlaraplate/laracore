<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Enums\VersionSetKind;
use Override;

/**
 * One revision of a versioned root: the rows it wrote, in the order of their sequence.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $root_type
 * @property string|null $root_id
 * @property string|null $root_connection_ref
 * @property string|null $root_table_ref
 * @property int|null $user_id
 * @property VersionSetKind $kind
 * @property VersionCaptureQuality $capture
 * @property string|null $reason
 * @property int|null $reverted_from_set_id
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class VersionSet extends Model
{
    /**
     * The history is append-only: a set is never updated once written (D19).
     */
    public const UPDATED_AT = null;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::VersionSets->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'uuid',
        'root_type',
        'root_id',
        'root_connection_ref',
        'root_table_ref',
        'kind',
        'capture',
        'reason',
        'reverted_from_set_id',
    ];

    /**
     * @return list<string>
     */
    #[Override]
    public function getFillable(): array
    {
        $user_foreign_key = config('versionable.user_foreign_key', 'user_id');

        if (! is_string($user_foreign_key) || $user_foreign_key === '') {
            throw new InvalidArgumentException('The versionable user foreign key must be a non-empty string.');
        }

        return array_values(array_unique([...parent::getFillable(), $user_foreign_key]));
    }

    /**
     * @return HasMany<Version>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<VersionSet, VersionSet>
     */
    public function revertedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverted_from_set_id');
    }

    protected function casts(): array
    {
        return [
            'kind' => VersionSetKind::class,
            'capture' => VersionCaptureQuality::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
