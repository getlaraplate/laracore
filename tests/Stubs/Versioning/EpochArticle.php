<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersionedRelations;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Tests\Stubs\Versioning\Relations\VersionedRelationSubject;
use Modules\Core\Versioning\Data\RelationDescriptor;

/**
 * A versioned record that versions every column but `notes`, and registers the relations a test gives it: its schema
 * epoch follows its table and its relations (D13, D33, D57).
 */
final class EpochArticle extends Model
{
    use HasVersionedRelations;
    use HasVersions;

    public const string TABLE = 'core_test_epoch_articles';

    public const string PIVOT_TABLE = 'core_test_epoch_article_subjects';

    /**
     * @var list<RelationDescriptor>
     */
    public static array $descriptors = [];

    /**
     * The pivot column holding the article's key in {@see self::subjects()}.
     */
    public static string $subjectsForeignPivotKey = 'article_id';

    protected $table = self::TABLE;

    protected $guarded = [];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->dontVersionable[] = 'notes';
    }

    /**
     * @return BelongsToMany<VersionedRelationSubject, $this>
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(VersionedRelationSubject::class, self::PIVOT_TABLE, self::$subjectsForeignPivotKey, 'subject_id');
    }

    /**
     * @return list<RelationDescriptor>
     */
    protected function versionedRelations(): array
    {
        return self::$descriptors;
    }
}
