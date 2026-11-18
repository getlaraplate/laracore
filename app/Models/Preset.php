<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Core\Cache\HasCache;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Contracts\IDynamicEntityTypable;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Pivot\Fieldable;
use Modules\Core\Models\Pivot\Presettable;
use Modules\Core\Observers\DynamicContentMetadataObserver;
use Modules\Core\Observers\HierarchyOwnerObserver;
use Modules\Core\Overrides\Model;
use Modules\Core\Services\PresetVersioningService;
use Override;

/**
 * @property int|string $id
 * @property string $name
 * @property int|string|null $entity_id
 * @property int|string|null $template_id
 */
#[ObservedBy([DynamicContentMetadataObserver::class, HierarchyOwnerObserver::class])]
abstract class Preset extends Model implements IActivatableModel
{
    use HasActivation {
        HasActivation::casts as private activationCasts;
    }
    use HasApprovals;
    use HasCache;

    #[Override]
    final protected $table = CoreTables::Presets->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    final protected $fillable = [
        'entity_id',
        'name',
        'template_id',
    ];

    #[Override]
    final protected $hidden = [
        'entity_id',
        'template_id',
    ];

    /**
     * Get the model class for the related content.
     *
     * @return class-string<Model>
     */
    abstract protected static function getRelatedModelClass(): string;

    /**
     * @return BelongsTo<Template>
     */
    final public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * @return BelongsTo<Entity>
     */
    final public function entity(): BelongsTo
    {
        return $this->belongsTo(str_replace('Preset', 'Entity', static::class));
    }

    /**
     * @return BelongsToMany<Field,Preset,Fieldable,'pivot'>
     */
    final public function fields(): BelongsToMany
    {
        return $this->belongsToMany(Field::class, CoreTables::Fieldables->value)->using(Fieldable::class)->withTimestamps()->withPivot(['id', 'order_column', 'is_required', 'default']);
    }

    /**
     * Create a new presettable version with the current fields snapshot.
     * Call this after modifying the fields relationship (attach/detach/sync)
     * since BelongsToMany bulk operations don't fire pivot model events.
     */
    final public function createFieldsVersion(): Presettable
    {
        return resolve(PresetVersioningService::class)->createVersion($this);
    }

    /**
     * Get the current active presettable for this preset.
     */
    final public function activePresettable(): ?Presettable
    {
        $presettable_class = $this->getModuleModelClass(Presettable::class);

        return $presettable_class::query()
            ->where('preset_id', $this->id)
            ->where('entity_id', $this->entity_id)
            ->whereNull('deleted_at')
            ->latest('version')
            ->first();
    }

    final public function getRules(): array
    {
        $rules = parent::getRules();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], [
            'is_active' => 'boolean',
            'template_id' => ['sometimes', 'exists:' . CoreTables::Templates->value . ',id'],
            'entity_id' => ['required', 'exists:' . CoreTables::Entities->value . ',id'],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255'],
        ]);
        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        return $rules;
    }

    /**
     * Migrate all contents to the latest presettable version.
     * This reassigns every content's presettable_id to the current active version.
     */
    final public function migrateRelatedModelsToLastVersion(): int
    {
        $active = $this->activePresettable();

        if (! $active instanceof Presettable) {
            return 0;
        }

        $related_model_class = static::getRelatedModelClass();

        return $related_model_class::query()
            ->whereIn('presettable_id', function (QueryBuilder $query): void {
                $query->select('id')
                    ->from(CoreTables::Presettables->value)
                    ->where('preset_id', $this->id)
                    ->where('entity_id', $this->entity_id);
            })
            ->update(['presettable_id' => $active->id]);
    }

    /**
     * Active presets whose related entity is active and matches the given CMS table type (e.g. contents).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    final protected function forActiveEntityOfType(Builder $query, IDynamicEntityTypable $entity_type): void
    {
        $query->where($query->qualifyColumn(self::activationColumn()), true)
            ->whereHas('entity', function (Builder $entity_query) use ($entity_type): void {
                $entity_query->where($entity_query->qualifyColumn(Entity::activationColumn()), true)
                    ->where($entity_query->qualifyColumn('type'), $entity_type);
            });
    }

    final protected function casts(): array
    {
        return array_merge($this->activationCasts(), [
            'template_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
        ]);
    }

    /**
     * The module's concrete counterpart of a Core model class, resolved from the concrete
     * preset's module (`static`), not from this abstract class, which always lives in Core.
     */
    private function getModuleModelClass(string $class): string
    {
        $module = class_module(static::class);

        if ($module !== 'App') {
            return str_replace('Core', $module, $class);
        }

        return str_replace('Modules\\Core', $module, $class);
    }
}
