<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Contracts\IDynamicEntityTypable;
use Modules\Core\Contracts\ILockableModel;
use Modules\Core\Contracts\IValidatableModel;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Locking\Traits\HasLocks;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Concerns\HasClosureTable;
use Modules\Core\Models\Concerns\HasPath;
use Modules\Core\Models\Concerns\HasTranslatedDynamicContents;
use Modules\Core\Models\Concerns\HasValidity;
use Modules\Core\Models\Concerns\SortableTrait;
use Modules\Core\Models\Translations\TaxonomyTranslation;
use Modules\Core\Overrides\Model;
use Modules\Core\Support\Hierarchy\ClosurePaths;
use Override;
use Spatie\EloquentSortable\Sortable;

abstract class Taxonomy extends Model implements IActivatableModel, ILockableModel, IValidatableModel, Sortable
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
    use HasPath;
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
     * The value of {@see self::getPath()} given by {@see self::assignPaths()} for this load; null builds it from the
     * ancestors.
     */
    private ?string $assignedPath = null;

    /**
     * @return class-string<Entity>
     */
    abstract public static function getEntityModelClass(): string;

    abstract protected static function getEntityType(): IDynamicEntityTypable;

    /**
     * Give each taxonomy the value {@see self::getPath()} builds from its ancestors, for a whole collection at once:
     * one query joining the closure rows of all of them to their ancestors (per {@see ClosurePaths::KEY_CHUNK_SIZE}
     * taxonomies), each distinct ancestor hydrated once, then the ancestors' translations once.
     * Reading `path` on taxonomies one by one loads the ancestors of each; an eager `ancestors` load hydrates one
     * ancestor per (taxonomy, ancestor) pair. The ancestors are read as the `ancestors` relation reads them: the
     * model's global scopes apply, and an ancestor they hide gives no segment.
     *
     * The value belongs to this load: a taxonomy moved afterwards keeps it until it is loaded again.
     *
     * @param  iterable<array-key, mixed>  $taxonomies
     */
    public static function assignPaths(iterable $taxonomies): void
    {
        /** @var array<class-string<self>, array<string, list<self>>> $nodes */
        $nodes = [];

        foreach ($taxonomies as $taxonomy) {
            $key = $taxonomy instanceof self ? $taxonomy->getKey() : null;

            if (is_int($key) || is_string($key)) {
                $nodes[$taxonomy::class][(string) $key][] = $taxonomy;
            }
        }

        foreach ($nodes as $by_key) {
            [$chains, $ancestors] = self::readAncestors(reset($by_key)[0], array_map(strval(...), array_keys($by_key)));

            foreach ($by_key as $key => $same_node) {
                $chain = $chains[(string) $key] ?? [];
                krsort($chain);
                $slugs = [];

                foreach ($chain as $distance => $ancestor_key) {
                    if ($distance > 0 && isset($ancestors[$ancestor_key])) {
                        $slugs[] = $ancestors[$ancestor_key]->slug;
                    }
                }

                foreach ($same_node as $taxonomy) {
                    $taxonomy->assignedPath = self::joinChain($slugs, $taxonomy->slug ?? null, '/');
                }
            }
        }
    }

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
     * @return HasMany<TaxonomyTranslation>
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
    public function getPath(): string
    {
        // Use slug from translation and build a stable ancestor chain
        return $this->assignedPath ?? $this->buildAncestorChain('slug', '/');
    }

    public function appendPaths(): self
    {
        $this->appends = array_merge($this->appends, ['ids', 'path', 'full_name']);

        return $this;
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

    protected static function getTranslationModelClass(): string
    {
        return TaxonomyTranslation::class;
    }

    /**
     * Eager-load ancestors (and their translations) for path-related accessors.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    final protected function withAncestorsForPath(Builder $query): Builder
    {
        return $query->with([
            'ancestors',
            'ancestors.translations',
        ]);
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

    final protected function ids(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->buildAncestorChain('id', '.'),
        );
    }

    final protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->buildAncestorChain('name', ' > '),
        );
    }

    final protected function title(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->name,
        );
    }

    /**
     * The visible ancestors of the nodes, each hydrated once with its translations and the model's default relations,
     * and the chain of each node by distance, from one query per {@see ClosurePaths::KEY_CHUNK_SIZE} nodes joining the
     * closure rows to the model table: the model's global scopes decide which ancestors exist, as they do for the
     * `ancestors` relation. A node without a visible ancestor has no chain.
     *
     * @param  list<string>  $keys
     * @return array{0: array<string, array<int, string>>, 1: array<string, self>}
     */
    private static function readAncestors(self $model, array $keys): array
    {
        $closure_table = $model->getClosureTable();
        $reach = $closure_table . '_reach';
        $key_name = $model->getKeyName();
        $chains = [];
        $ancestors = [];

        foreach (array_chunk($keys, ClosurePaths::KEY_CHUNK_SIZE) as $chunk) {
            $query = $model->newQuery()
                ->with('translations')
                ->join($closure_table . ' as ' . $reach, $reach . '.ancestor_id', '=', $model->getQualifiedKeyName())
                ->whereIn($reach . '.descendant_id', $chunk)
                ->where($reach . '.depth', '>', 0)
                ->select([$model->getTable() . '.*', $reach . '.descendant_id as closure_descendant_id', $reach . '.depth as closure_depth']);
            $rows = [];

            foreach ($query->toBase()->get() as $row) {
                $row = (array) $row;
                $ancestor = (string) $row[$key_name];
                $chains[(string) $row['closure_descendant_id']][(int) $row['closure_depth']] = $ancestor;
                unset($row['closure_descendant_id'], $row['closure_depth']);

                if (! isset($ancestors[$ancestor])) {
                    $rows[$ancestor] = $row;
                }
            }

            if ($rows === []) {
                continue;
            }

            $hydrated = $query->hydrate(array_values($rows))->all();
            $query->eagerLoadRelations($hydrated);

            foreach ($hydrated as $ancestor) {
                $ancestors[(string) $ancestor->getKey()] = $ancestor;
            }
        }

        return [$chains, $ancestors];
    }

    /**
     * The non-empty values of the ancestors, root first, then the node's own, joined; the node's own value alone
     * (or an empty string) when no segment is left.
     *
     * @param  array<array-key, mixed>  $ancestorValues  Root first
     */
    private static function joinChain(array $ancestorValues, mixed $current, string $separator): string
    {
        /** @var Collection<int, mixed> $segments */
        $segments = collect($ancestorValues)
            ->filter(static fn (mixed $value): bool => $value !== null && $value !== '')
            ->values();

        if ($current !== null && $current !== '') {
            $segments->push($current);
        }

        if ($segments->isEmpty()) {
            return (string) ($current ?? '');
        }

        return $segments->join($separator);
    }

    /**
     * Build a concatenated chain from ancestors plus the current model.
     */
    private function buildAncestorChain(string $field, string $separator): string
    {
        return self::joinChain($this->ancestors->pluck($field)->reverse()->all(), $this->{$field} ?? null, $separator);
    }
}
