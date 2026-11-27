<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Contracts\IDynamicEntityTypable;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Contracts\IValidatableModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasClosureTable;
use Modules\Core\Models\Concerns\HasTranslatedDynamicContents;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Models\Concerns\SortableTrait;
use Modules\Core\Models\Translations\TaxonomyTranslation;
use Modules\Core\Overrides\Model;
use Override;
use Spatie\EloquentSortable\Sortable;

/**
 * @property int $id
 * @property int $entity_id
 * @property int $presettable_id
 * @property int|null $parent_id
 * @property string|null $logo The logo of the taxonomy
 * @property string|null $logo_full The full logo of the taxonomy
 * @property bool $is_active Whether the taxonomy is active
 * @property int $order_column The order of the taxonomy
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property \Carbon\CarbonImmutable|null $locked_at The date and time when the entity was locked
 * @property int|null $locked_user_id The user who locked the entity
 * @property \Carbon\CarbonImmutable|null $locked_until The moment the lock expires; null means it never does
 * @property \Carbon\CarbonImmutable $valid_from
 * @property \Carbon\CarbonImmutable|null $valid_to
 * @property array<array-key, mixed>|null $shared_components The shared dynamic components of the taxonomy
 * @property string|null $name
 * @property string|null $slug
 * @property array<array-key, mixed>|null $components
 */
abstract class Taxonomy extends Model implements IActivatableModel, ILockableModel, ITranslatableModel, IValidatableModel, Sortable
{
    // region Traits
    use HasActivation {
        HasActivation::casts as private activationCasts;
    }
    use HasApprovals {
        HasApprovals::toArray as private approvalsToArray;
        HasApprovals::requiresApprovalWhen as private requiresApprovalWhenTrait;
    }
    use HasClosureTable;
    use HasLocks;
    use HasTranslatedDynamicContents {
        HasTranslatedDynamicContents::getRules as private getRulesDynamicContents;
        HasTranslatedDynamicContents::toArray as private translatedDynamicContentsToArray;
        HasTranslatedDynamicContents::casts as private translatedDynamicContentsCasts;
        HasTranslatedDynamicContents::getTranslationModelClass as private getTranslationModelClassDynamicContents;
    }
    use HasValidity;
    use SortableTrait {
        SortableTrait::scopeOrdered as private scopePriorityOrdered;
    }
    // endregion

    /**
     * @var string
     */
    #[Override]
    final protected $table = CoreTables::Taxonomies->value;

    /**
     * The attributes that are mass assignable.
     */
    #[Override]
    final protected $fillable = [
        'parent_id',
        'presettable_id',
        'entity_id',
    ];

    #[Override]
    final protected $hidden = [
        'entity',
        'parent_id',
        'presettable_id',
        'entity_id',
        'ancestorsAndSelf',
        'ancestors',
        'descendants',
        'descendantsAndSelf',
        'childrenAndSelf',
        'bloodline',
    ];

    /**
     * @return class-string<Entity>
     */
    abstract public static function getEntityModelClass(): string;

    abstract protected static function getEntityType(): IDynamicEntityTypable;

    public function getRules(): array
    {
        $table = $this->getTable();
        $rules = parent::getRules();
        $dynamic_fields = $this->getRulesDynamicContents();
        $default_rule = Model::DEFAULT_RULE;
        $rules[$default_rule] = array_merge($rules[$default_rule], $dynamic_fields, [
            'parent_id' => "sometimes|nullable|exists:{$table},id",
        ]);
        $rules['create'] = array_merge($rules['create'], [
            // 'name' => 'required|string|max:255', // Validated in translation
            // 'slug' => 'sometimes|nullable|string|max:255', // Validated in translation
            'translations' => 'sometimes|array',
            'translations.*.locale' => 'required|string|max:10',
            'translations.*.name' => 'required|string|max:255',
            'translations.*.slug' => 'sometimes|nullable|string|max:255',
            'translations.*.components' => 'sometimes|array',
        ]);
        $rules['update'] = array_merge($rules['update'], [
            // 'name' => 'sometimes|required|string|max:255', // Validated in translation
            // 'slug' => 'sometimes|nullable|string|max:255', // Validated in translation
            'translations' => 'sometimes|array',
            'translations.*.locale' => 'required|string|max:10',
            'translations.*.name' => 'sometimes|required|string|max:255',
            'translations.*.slug' => 'sometimes|nullable|string|max:255',
            'translations.*.components' => 'sometimes|array',
        ]);

        return $rules;
    }

    /**
     * Shared translation table {@see TaxonomyTranslation} keys rows with taxonomy_id, not {subclass}_id.
     *
     * @return HasMany<TaxonomyTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(static::getTranslationModelClass(), 'taxonomy_id');
    }

    /**
     * @return HasOne<TaxonomyTranslation>
     */
    public function translation(): HasOne
    {
        $current_locale = LocaleContext::get();
        $default_locale = LocaleContext::getDefault();
        $fallback_enabled = $this->translationFallbackEnabledBySettings();

        $relation = $this->hasOne(static::getTranslationModelClass(), 'taxonomy_id');

        if ($fallback_enabled) {
            $relation->where(function (Builder $query) use ($current_locale, $default_locale): void {
                $query->where('locale', $current_locale)
                    ->orWhere('locale', $default_locale);
            })
                ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$current_locale]);
        } else {
            $relation->where('locale', $current_locale);
        }

        return $relation;
    }

    #[Override]
    public function toArray(): array
    {
        // $parsed = parent::toArray() ?? $this->attributesToArray();
        $parsed = parent::toArray();

        return array_merge($parsed, $this->translatedDynamicContentsToArray(), $this->approvalsToArray($parsed));
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('global_filters', static function (Builder $query): void {
            $query->active()->valid();
        });
        self::addGlobalScope('global_ordered', static function (Builder $query): void {
            $query->ordered();
        });
    }

    /**
     * @return class-string<TaxonomyTranslation>
     */
    protected static function getTranslationModelClass(): string
    {
        return TaxonomyTranslation::class;
    }

    #[Scope]
    /**
     * @param  Builder<Taxonomy>  $query
     * @return Builder<Taxonomy>
     */
    final protected function ordered(Builder $query): Builder
    {
        $model = $query->getModel();

        if (! $model instanceof self) {
            return $query;
        }

        $orderColumn = $model->qualifyColumn($model->determineOrderColumnName());

        // @phpstan-ignore method.notFound
        return $query->orderBy($orderColumn, 'asc')->validityOrdered();
    }

    protected function casts(): array
    {
        return array_merge($this->activationCasts(), $this->translatedDynamicContentsCasts(), [
            'parent_id' => 'integer',
            'model_type_id' => 'integer',
            'order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'datetime',
            'content' => 'json',
        ]);
    }

    protected function slugPlaceholders(): array
    {
        // Use name from translation
        return [...array_map(fn (string $field): string => '{' . $field . '}', $this->dynamicSlugFields()), '{name}'];
    }

    protected function requiresApprovalWhen(array $modifications): bool
    {
        return $this->requiresApprovalWhenTrait($modifications) && ($modifications[self::$valid_from_column] ?? $modifications[self::$valid_to_column] ?? false);
    }

    final protected function title(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->name,
        );
    }
}
