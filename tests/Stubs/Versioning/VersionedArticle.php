<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Versioning;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Enums\VersionStrategy;
use Modules\Core\Models\Concerns\HasVersions;

final class VersionedArticle extends Model implements IVersionedModel
{
    use HasVersions;

    public const string TABLE = 'core_test_versioned_articles';

    protected $table = self::TABLE;

    protected $guarded = [];

    protected array $versionable = ['title'];

    protected VersionStrategy $versionStrategy = VersionStrategy::DIFF;

    public function useVersionStrategy(VersionStrategy $strategy): void
    {
        $this->versionStrategy = $strategy;
    }

    /**
     * @param  list<string>  $attributes
     */
    public function encryptVersionAttributes(array $attributes): void
    {
        $this->encryptedVersionable = $attributes;
    }
}
