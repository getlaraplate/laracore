<?php

declare(strict_types=1);

namespace Modules\Core\Models\Translations;

use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasSlug;
use Modules\Core\Models\Concerns\RecordsChangesOnOwner;
use Modules\Core\Models\Concerns\TouchesOwnerOnChange;
use Modules\Core\Overrides\Model;
use Modules\Core\Services\Translation\Definitions\ITranslated;
use Modules\Core\Support\OwnerTimestamp;
use Override;

/**
 * @property int $id
 * @property int $taxonomy_id
 * @property string $locale The locale of the translation
 * @property string $name The translated name of the taxonomy
 * @property string|null $slug The translated slug of the taxonomy
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class TaxonomyTranslation extends Model implements ITranslated
{
    use HasSlug;
    use RecordsChangesOnOwner;
    use TouchesOwnerOnChange;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::TaxonomiesTranslations->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        'taxonomy_id',
        'locale',
        'name',
        'slug',
        'components',
    ];

    /**
     * The taxonomy this translation belongs to: its `updated_at` moves when the translation changes (D30). The
     * owner class is abstract and shared by every taxonomy type, so the row is touched by table and key.
     */
    public function touchOwner(): void
    {
        $key = $this->getAttribute('taxonomy_id');

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        app(OwnerTimestamp::class)->touchRow($this->getConnection(), CoreTables::Taxonomies->value, 'id', $key, 'updated_at', $this->freshTimestampString());
    }

    // /**
    //  * The category that belongs to the translation.
    //  *
    //  * @return BelongsTo<Category>
    //  */
    // public function category(): BelongsTo
    // {
    //     return $this->belongsTo(Category::class);
    // }

    protected function casts(): array
    {
        return [
            'components' => 'json',
        ];
    }

    /**
     * Never a model: {@see touchOwner()} touches the row by table and key.
     */
    protected function ownerToTouch(): null
    {
        return null;
    }
}
