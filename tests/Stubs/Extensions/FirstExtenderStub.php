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
 * An extender of {@see ExtensionHostStub}, keyed to its host row by `host_id`.
 */
final class FirstExtenderStub extends Model implements IExtendsModel
{
    public const string TABLE = 'extension_first_stubs';

    public const string ALIAS = 'core.first';

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
        return ['kind' => ['type' => FieldType::Keyword, 'filterable' => true]];
    }
}
