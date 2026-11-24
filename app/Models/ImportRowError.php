<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Database\Factories\ImportRowErrorFactory;
use Modules\Core\Enums\CoreTables;
use Override;

/**
 * One row that could not be imported: its 1-based position, the field → messages
 * that explain why, and the raw mapped row. Together these back the downloadable
 * per-row failure report and let a user fix the source and re-import.
 *
 * @property int $id
 * @property int $import_session_id
 * @property int $row_number 1-based data row number in the source (header excluded)
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class ImportRowError extends Model implements IsPartOfParent
{
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'import_session_id',
        'row_number',
        'errors',
        'raw',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::ImportRowErrors->value;

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'session';
    }

    /**
     * @return BelongsTo<ImportSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ImportSession::class, 'import_session_id');
    }

    protected static function newFactory(): ImportRowErrorFactory
    {
        return ImportRowErrorFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'errors' => 'array',
            'raw' => 'array',
        ];
    }
}
