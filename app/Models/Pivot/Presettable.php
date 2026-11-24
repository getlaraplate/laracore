<?php

declare(strict_types=1);

namespace Modules\Core\Models\Pivot;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\ISoftDeletableModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Entity;
use Modules\Core\Models\Field;
use Modules\Core\Models\Preset;
use Modules\Core\Observers\HierarchyOwnerObserver;
use Modules\Core\Overrides\Pivot;
use Modules\Core\SoftDeletes\SoftDeletes;
use Override;

/**
 * @property int $id
 * @property int $preset_id The preset that the entity preset relation belongs to
 * @property int $entity_id The entity that the entity preset relation belongs to
 * @property int $version Incremental version number scoped to preset+entity
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed> $fields_snapshot
 */
#[ObservedBy(HierarchyOwnerObserver::class)]
abstract class Presettable extends Pivot implements ISoftDeletableModel
{
    use SoftDeletes;

    #[Override]
    final public $incrementing = true;

    #[Override]
    final public $timestamps = false;

    #[Override]
    final protected $table = CoreTables::Presettables->value;

    #[Override]
    final protected $fillable = [
        'preset_id',
        'entity_id',
        'fields_snapshot',
    ];

    #[Override]
    final protected $with = [
        'preset',
        'entity',
    ];

    /**
     * Memoized result of {@see self::getFieldsFromSnapshot()}.
     */
    private ?Collection $hydrated_snapshot_fields = null;

    /**
     * The snapshot the memoized fields were built from, so a changed snapshot rebuilds.
     */
    private ?string $hydrated_snapshot_key = null;

    /**
     * @return BelongsTo<Preset, $this>
     */
    final public function preset(): BelongsTo
    {
        return $this->belongsTo($this->presetModelClass());
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    final public function entity(): BelongsTo
    {
        return $this->belongsTo($this->entityModelClass());
    }

    /**
     * Hydrate Field model instances from the frozen snapshot.
     * Each field gets a Fieldable pivot attached, so downstream code
     * that accesses $field->pivot->is_required etc. works unchanged.
     *
     * @return Collection<int, Field>
     */
    final public function getFieldsFromSnapshot(): Collection
    {
        $snapshot = $this->fields_snapshot ?? [];
        $key = (string) json_encode($snapshot);

        if ($this->hydrated_snapshot_key === $key && $this->hydrated_snapshot_fields instanceof Collection) {
            return $this->hydrated_snapshot_fields;
        }

        $fields = collect($snapshot)
            ->map(static function (array $data): Field {
                $field = new Field();
                $field->forceFill([
                    'id' => $data['field_id'],
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'options' => $data['options'],
                    'is_translatable' => $data['is_translatable'],
                    'is_slug' => $data['is_slug'],
                ]);
                $field->exists = true;
                $field->syncOriginal();

                $pivot = new Fieldable();
                $pivot->forceFill([
                    'is_required' => $data['pivot']['is_required'],
                    'order_column' => $data['pivot']['order_column'],
                    'default' => $data['pivot']['default'] ?? null,
                ]);
                $pivot->exists = true;
                $pivot->syncOriginal();

                $field->setRelation('pivot', $pivot);

                return $field;
            })
            ->sortBy(fn (Field $field): int => $field->getRelation('pivot')->order_column)
            ->values();

        $this->hydrated_snapshot_key = $key;

        return $this->hydrated_snapshot_fields = new Collection($fields->all());
    }

    final protected static function boot(): void
    {
        parent::boot();

        self::creating(static function (self $presettable): void {
            $presettable->version = (int) self::query()
                ->withTrashed()
                ->where('preset_id', $presettable->preset_id)
                ->where('entity_id', $presettable->entity_id)
                ->max('version') + 1;
        });
    }

    /**
     * @return class-string<Preset>
     */
    protected function presetModelClass(): string
    {
        return Preset::class;
    }

    /**
     * @return class-string<Entity>
     */
    protected function entityModelClass(): string
    {
        return Entity::class;
    }

    final protected function casts(): array
    {
        return [
            'version' => 'integer',
            'fields_snapshot' => 'json',
            'created_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
