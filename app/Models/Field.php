<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\Rule;
use LogicException;
use Modules\Core\Casts\FieldType;
use Modules\Core\Casts\ObjectCast;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Pivot\Fieldable;
use Modules\Core\Observers\FieldObserver;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @property int $id
 * @property string $name The name of the field
 * @property FieldType $type The type of the field
 * @property object $options The options of the field
 * @property int $is_slug Whether the field takes part in the slug
 * @property bool $is_active Whether the field is active
 * @property bool $is_translatable Whether the field is translatable
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
#[ObservedBy(FieldObserver::class)]
final class Field extends Model implements IActivatableModel
{
    // region Traits
    use HasActivation {
        HasActivation::casts as private activationCasts;
    }
    use HasApprovals;
    // endregion

    /**
     * @var string
     */
    #[Override]
    protected $table = CoreTables::Fields->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    protected $fillable = [
        'name',
        'type',
        'options',
    ];

    public function getAttribute(mixed $key): mixed
    {
        if (property_exists($this, 'pivot') && $this->pivot !== null && isset($this->pivot->{$key})) {
            return $this->pivot->{$key};
        }

        return parent::getAttribute($key);
    }

    public function setAttribute(mixed $key, mixed $value): mixed
    {
        if (property_exists($this, 'pivot') && $this->pivot !== null && array_key_exists($key, $this->pivot->getAttributes())) {
            // @phpstan-ignore assign.propertyReadOnly
            data_set($this->pivot, $key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @return BelongsToMany<Preset,Field,Fieldable,'pivot'>
     */
    public function presets(): BelongsToMany
    {
        return $this->belongsToMany(Preset::class, CoreTables::Fieldables->value)->using(Fieldable::class)->withTimestamps()->withPivot(['order_column', 'is_required', 'default']);
    }

    /**
     * The preset link the field was loaded through (`Preset::fields()`, or a presettable's frozen snapshot): the
     * required flag, the order and the default value the preset gives the field.
     *
     * @throws LogicException when the field was loaded without it
     */
    public function presetPivot(): Fieldable
    {
        $pivot = $this->relationLoaded('pivot') ? $this->getRelation('pivot') : null;

        if (! $pivot instanceof Fieldable) {
            throw new LogicException("Field {$this->name} was loaded without its preset pivot.");
        }

        return $pivot;
    }

    #[Override]
    public function toArray(): array
    {
        $field = parent::toArray();

        if (isset($field['pivot'])) {
            $pivot = $field['pivot'];
            unset($field['pivot']);
            $field = array_merge($field, $pivot);
        } elseif (property_exists($this, 'pivot') && $this->pivot !== null) {
            $field = array_merge($field, $this->pivot->toArray());
        }

        return $field;
    }

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], [
            'is_active' => 'boolean',
            'type' => ['required', 'string', Rule::enum(FieldType::class)],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255', 'unique:' . CoreTables::Fields->value . ',name'],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255', 'unique:' . CoreTables::Fields->value . ',name,' . $this->id],
        ]);

        return $rules;
    }

    protected function casts(): array
    {
        return array_merge($this->activationCasts(), [
            'options' => ObjectCast::class,
            'type' => FieldType::class,
            'is_translatable' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ]);
    }
}
