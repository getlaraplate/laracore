<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\CoreTables;
use Override;

/**
 * Transactional integration event awaiting publication to an external transport.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property int $publish_attempts
 * @property string|null $last_error
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class OutboxEvent extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::OutboxEvents->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'occurred_at',
        'published_at',
        'publish_attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'publish_attempts' => 'integer',
        ];
    }
}
