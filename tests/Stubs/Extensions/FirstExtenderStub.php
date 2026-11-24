<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Stubs\Extensions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IExtendsModel;
use Modules\Core\Contracts\IMountsRelatedAttributes;
use Modules\Core\Search\Extensions\HidesExtendedRows;
use Modules\Core\Search\Schema\FieldType;
use Override;

/**
 * An extender of {@see ExtensionHostStub}, keyed to its host row by `host_id`, with its own key (XS8). It mounts the
 * attributes of its host row, as a product mounts its content's: a filter on `title` is a filter on the host.
 */
final class FirstExtenderStub extends Model implements IExtendsModel, IMountsRelatedAttributes
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

    public function mountedRelation(): string
    {
        return 'host';
    }

    public function isMountedAttribute(string $key): bool
    {
        return ! in_array($key, $this->extensionOwnColumns(), true) && $key !== 'host';
    }

    /**
     * @return BelongsTo<ExtensionHostStub, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(ExtensionHostStub::class, 'host_id')->withoutGlobalScope(HidesExtendedRows::class);
    }

    /**
     * The `app_pages` entity of the Core test application, so its presets are guarded (XS17).
     */
    public function extensionEntityType(): ?string
    {
        return 'app_pages';
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
