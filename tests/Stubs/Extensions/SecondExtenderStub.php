<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Extensions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IExtendsModel;
use Modules\Core\Search\Extensions\HidesExtendedRows;
use Modules\Core\Search\Schema\FieldType;
use Override;

/**
 * A second extender of {@see ExtensionHostStub}, whose `extension` mapping a test sets to share a field with
 * {@see FirstExtenderStub} or to conflict with it.
 */
final class SecondExtenderStub extends Model implements IExtendsModel
{
    public const string TABLE = 'extension_second_stubs';

    public const string ALIAS = 'core.second';

    /**
     * @var array<string, mixed>
     */
    public static array $mapping = ['kind' => ['type' => FieldType::Keyword, 'filterable' => true]];

    #[Override]
    protected $table = self::TABLE;

    #[Override]
    protected $guarded = [];

    public function extendedModel(): string
    {
        return ExtensionHostStub::class;
    }

    public function extensionAlias(): string
    {
        return self::ALIAS;
    }

    public function extensionHostKey(): string
    {
        return 'host_id';
    }

    public function extensionHostRelation(): string
    {
        return 'host';
    }

    /**
     * @return list<string>
     */
    public function extensionOwnColumns(): array
    {
        return ['id', 'host_id', 'kind', 'created_at', 'updated_at'];
    }

    /**
     * @return BelongsTo<ExtensionHostStub, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(ExtensionHostStub::class, 'host_id')->withoutGlobalScope(HidesExtendedRows::class);
    }

    /**
     * No entity: its host rows have no presets to guard.
     */
    public function extensionEntityType(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function searchableExtension(): array
    {
        return ['kind' => $this->getAttribute('kind')];
    }

    /**
     * @return array<string, mixed>
     */
    public function searchableExtensionMapping(): array
    {
        return self::$mapping;
    }
}
