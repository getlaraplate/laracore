<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IOptimisticLockableModel;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Locking\Traits\HasOptimisticLocking;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\Concerns\HasVersions;

/**
 * A versioned record with translations and an optimistic lock, whose history carries its locales (D10, D23, D28).
 */
final class TranslatedDocument extends Model implements IOptimisticLockableModel, IVersionedModel
{
    use HasOptimisticLocking;
    use HasTranslations;
    use HasVersions;

    public const string TABLE = 'core_test_translated_documents';

    protected $table = self::TABLE;

    protected $guarded = [];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    public function useVersionStrategy(VersionStrategy $strategy): void
    {
        $this->versionStrategy = $strategy;
    }

    protected static function getTranslationModelClass(): string
    {
        return TranslatedDocumentTranslation::class;
    }
}
