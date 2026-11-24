<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Database\Factories\ImportSessionFactory;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Import\Enums\ImportSessionStatus;
use Modules\Core\Import\Enums\ImportSourceFormat;
use Override;

/**
 * One interactive bulk import: the uploaded file, the detected source columns, the
 * chosen target entity and column mapping, the run status and the per-outcome
 * counters. It is the durable record a preview reads from, the queued job advances,
 * and the UI polls for progress.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $entity_key The registered import entity, e.g. core.user or sao.ticket
 * @property ImportSourceFormat $source_format
 * @property string $file_disk
 * @property string $file_path
 * @property string $original_filename
 * @property ImportSessionStatus $status
 * @property int|null $total_rows
 * @property int $processed_rows
 * @property int $created_rows
 * @property int $updated_rows
 * @property int $skipped_rows
 * @property int $failed_rows
 * @property \Carbon\CarbonImmutable|null $started_at
 * @property \Carbon\CarbonImmutable|null $finished_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property array<array-key, mixed>|null $detected_columns Source column headers detected on upload
 * @property array<array-key, mixed>|null $mapping Target field name => source column header
 * @property array<array-key, mixed>|null $options Reader hints: delimiter, etc.
 */
final class ImportSession extends Model
{
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'status' => ImportSessionStatus::Draft->value,
        'processed_rows' => 0,
        'created_rows' => 0,
        'updated_rows' => 0,
        'skipped_rows' => 0,
        'failed_rows' => 0,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'user_id',
        'entity_key',
        'source_format',
        'file_disk',
        'file_path',
        'original_filename',
        'status',
        'detected_columns',
        'mapping',
        'options',
        'total_rows',
        'processed_rows',
        'created_rows',
        'updated_rows',
        'skipped_rows',
        'failed_rows',
        'started_at',
        'finished_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::ImportSessions->value;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportRowError, $this>
     */
    public function rowErrors(): HasMany
    {
        return $this->hasMany(ImportRowError::class);
    }

    protected static function newFactory(): ImportSessionFactory
    {
        return ImportSessionFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'source_format' => ImportSourceFormat::class,
            'status' => ImportSessionStatus::class,
            'detected_columns' => 'array',
            'mapping' => 'array',
            'options' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_rows' => 'integer',
            'updated_rows' => 'integer',
            'skipped_rows' => 'integer',
            'failed_rows' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
