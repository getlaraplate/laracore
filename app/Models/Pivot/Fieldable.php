<?php

declare(strict_types=1);

namespace Modules\Core\Models\Pivot;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasVersions;
use Modules\Core\Models\Concerns\SortableTrait;
use Modules\Core\Observers\DynamicContentMetadataObserver;
use Modules\Core\Observers\FieldableObserver;
use Modules\Core\Overrides\Pivot;
use Override;
use Spatie\EloquentSortable\Sortable;

/**
 * @property int $id
 * @property int $preset_id
 * @property int $field_id
 * @property bool $is_required Whether the field is required
 * @property int $order_column The order of the field
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property string|null $deleted_at
 * @property int|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed>|null $default The default value of the field
 */
#[ObservedBy([FieldableObserver::class, DynamicContentMetadataObserver::class])]
final class Fieldable extends Pivot implements IVersionedModel, Sortable
{
    use HasVersions;
    use SortableTrait;

    #[Override]
    public $incrementing = true;

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Fieldables->value;

    #[Override]
    protected $attributes = [
        'is_required' => false,
        'order_column' => 0,
    ];

    #[Override]
    protected $hidden = [
        'field_id',
        'preset_id',
        'order_column',
    ];

    private array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    #[Scope]
    /**
     * @param  Builder<Fieldable>  $query
     * @return Builder<Fieldable>
     */
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('order_column', 'asc');
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'order_column' => 'integer',
            'default' => 'json',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ];
    }
}
