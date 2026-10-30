<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\LocaleScope;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Services\Translation\Definitions\ITranslated;

/**
 * Trait for models that store translatable fields in a separate translation table.
 *
 * Architecture:
 * - Translatable fields are determined dynamically from the translation model's $fillable
 * - Values are stored in pending_translations until model is saved
 * - On save, pending_translations are persisted to the translation table
 * - Custom accessors (getXxxAttribute) and mutators (setXxxAttribute) are supported
 *
 * Usage:
 * - Add trait to model: use HasTranslations;
 * - Create translation model with $fillable containing translatable fields
 * - Translation model class is auto-resolved: ModelTranslation in Translations subfolder
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-require-implements \Modules\Core\Contracts\ITranslatableModel
 *
 * @template TModel of Model
 * @template TTranslationModel of Model&ITranslated
 */
trait HasTranslations
{
    /**
     * Temporary storage for translatable fields to be saved.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $pending_translations = [];

    /**
     * Current locale context for setter operations.
     */
    protected ?string $current_setter_locale = null;

    /**
     * Cached translatable fields to avoid creating new instances.
     * Key: model class name, Value: array of translatable fields.
     *
     * @var array<class-string, list<string>>
     */
    protected static array $cached_translatable_fields = [];

    /**
     * Get translatable fields for this model.
     * Cached to avoid creating new instances and potential recursion.
     *
     * @return list<string>
     */
    public static function getTranslatableFields(): array
    {
        $model_class = static::class;

        // Cache per classe per evitare ricorsione durante l'inizializzazione
        if (! isset(static::$cached_translatable_fields[$model_class])) {
            // array_filter keeps the original keys, and the fields are read by
            // position everywhere they are used.
            static::$cached_translatable_fields[$model_class] = array_values(array_filter(
                (new (static::getTranslationModelClass()))->getFillable(),
                static fn (string $field): bool => $field !== 'locale' && ! str_ends_with($field, '_id'),
            ));
        }

        return static::$cached_translatable_fields[$model_class];
    }

    /**
     * Reset the static default locale cache.
     * Used in tests and long-running processes to clear the per-request cache.
     */
    public static function resetLocaleCache(): void
    {
        LocaleContext::resetDefaultLocaleCache();
    }

    /**
     * Override getAttribute to handle translatable fields.
     *
     * Priority:
     * 1. If translatable field with custom accessor → call accessor with translated value
     * 2. If translatable field → return translated value
     * 3. Otherwise → delegate to parent
     *
     * @param  string  $key
     */
    public function getAttribute($key): mixed
    {
        if ($this->isTranslatableField($key)) {
            $value = $this->getTranslatableFieldValue($key);

            // Check for custom accessor (e.g., getNameAttribute)
            $accessor = 'get' . Str::studly($key) . 'Attribute';

            if (method_exists($this, $accessor)) {
                return $this->{$accessor}($value);
            }

            return $value;
        }

        return parent::getAttribute($key);
    }

    /**
     * Override setAttribute to handle translatable fields.
     *
     * Priority:
     * 1. If translatable field with custom mutator → call mutator (it should call setTranslatableFieldValue)
     * 2. If translatable field → store in pending translations
     * 3. Otherwise → delegate to parent
     *
     * @param  string  $key
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        if ($this->isTranslatableField($key)) {
            // Check for custom mutator (e.g., setNameAttribute)
            $mutator = 'set' . Str::studly($key) . 'Attribute';

            if (method_exists($this, $mutator)) {
                $this->{$mutator}($value);

                return $this;
            }

            $this->setTranslatableFieldValue($key, $value);

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Get translatable fields for this model.
     */
    public function isTranslatableField(string $field): bool
    {
        return in_array($field, $this::getTranslatableFields(), true);
    }

    /**
     * Get the translations relation.
     *
     * @return HasMany<TTranslationModel>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(static::getTranslationModelClass());
    }

    /**
     * Get the translation for current locale (with conditional fallback).
     *
     * @return HasOne<TTranslationModel>
     */
    public function translation(): HasOne
    {
        $current_locale = LocaleContext::get();
        $default_locale = LocaleContext::getDefault();
        $fallback_enabled = $this->isFallbackEnabled();

        $relation = $this->hasOne(static::getTranslationModelClass());

        // The backoffice (a picked content locale) shows the default text where a translation is missing, whatever
        // the model's fallback, and the text of any other locale where the default is missing too: an empty cell
        // would read as missing data. What it writes never comes from this relation.
        if (LocaleContext::getContentLocale() !== null) {
            $relation->orderByRaw(
                'CASE WHEN locale = ? THEN 0 WHEN locale = ? THEN 1 ELSE 2 END',
                [$current_locale, $default_locale],
            )->orderBy('id');
        } elseif ($fallback_enabled) {
            // Se fallback abilitato: traduzione corrente o di default
            $relation->where(function ($query) use ($current_locale, $default_locale): void {
                $query->where('locale', $current_locale)
                    ->orWhere('locale', $default_locale);
            })
                ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$current_locale]);
        } else {
            // Se fallback disabilitato: SOLO traduzione corrente (null se non esiste)
            $relation->where('locale', $current_locale);
        }

        return $relation;
    }

    /**
     * Set locale context for next assignments.
     */
    public function inLocale(string $locale): self
    {
        $this->current_setter_locale = $locale;

        return $this;
    }

    /**
     * Get translation for specific locale.
     *
     * @return TTranslationModel|null
     */
    public function getTranslation(?string $locale = null, ?bool $with_fallback = null): ?Model
    {
        $locale ??= LocaleContext::get();
        $default_locale = LocaleContext::getDefault();
        $fallback_enabled = $with_fallback ?? $this->isFallbackEnabled();

        // Prova a ottenere traduzione per la lingua richiesta
        $translation = $this->translations()->where('locale', $locale)->first();

        // Se non esiste e fallback è abilitato, usa quella di default
        if (! $translation && $fallback_enabled && $locale !== $default_locale) {
            return $this->translations()->where('locale', $default_locale)->first();
        }

        return $translation;
    }

    /**
     * Set translation for specific locale.
     *
     * @param  array<string, mixed>  $data
     */
    public function setTranslation(string $locale, array $data): self
    {
        $this->writeTranslation($locale, $this->ensureTranslationFieldDefaults($data));

        // Reload if current translation
        if ($locale === LocaleContext::get()) {
            $this->load('translation');
        }

        return $this;
    }

    /**
     * Update translation for specific locale.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateTranslation(string $locale, array $data): self
    {
        return $this->setTranslation($locale, $data);
    }

    /**
     * Check if translation exists for locale.
     */
    public function hasTranslation(?string $locale = null): bool
    {
        $locale ??= LocaleContext::get();

        return $this->translations()->where('locale', $locale)->exists();
    }

    /**
     * Get all translations.
     *
     * Stated as a collection of models rather than of TTranslationModel: the
     * relation is reached by name, so what it holds is only known to be models.
     *
     * @return Collection<int, Model>
     */
    public function getAllTranslations(): Collection
    {
        return $this->translations;
    }

    /**
     * @param  array<string, mixed>|null  $parsed
     * @return array<string, mixed>
     */
    public function toArray(?array $parsed = null): array
    {
        $content = $parsed ?? (method_exists(parent::class, 'toArray') ? parent::toArray() : $this->attributesToArray());
        $translation = $this->getRelationValue('translation');

        if ($translation) {
            foreach ($this::getTranslatableFields() as $field) {
                if (isset($translation->{$field}) && ! in_array($field, $this->hidden, true)) {
                    $content[$field] = $translation->{$field};
                }
            }
        }

        $locale = $this->getCurrentLocale();

        if (isset($this->pending_translations[$locale])) {
            return array_merge($content, $this->pending_translations[$locale]);
        }

        return $content;
    }

    public function initializeHasTranslations(): void
    {
        if (! in_array('translation', $this->hidden, true)) {
            $this->hidden[] = 'translation';
        }

        if (! in_array('translation', $this->with, true)) {
            $this->with[] = 'translation';
        }

        if (! in_array('locale', $this->appends, true)) {
            $this->appends[] = 'locale';
        }
    }

    /**
     * Whether locale fallback is enabled for this model.
     * Reads optional {@see $translation_fallback_enabled} or settings in group {@code translations}
     * with name {@code translation_fallback_{table}}. When no setting exists, fallback stays enabled.
     */
    public function translationFallbackEnabledBySettings(): bool
    {
        if (property_exists($this, 'translation_fallback_enabled')) {
            return (bool) $this->translation_fallback_enabled;
        }

        return app(PerModelSettingResolver::class)->boolean(
            PerModelSettingResolver::nameFor(CoreDatabaseSeeder::TRANSLATION_FALLBACK_NAME_PREFIX, $this->getTable()),
            default: true,
        );
    }

    /**
     * Whether automatic translation is enabled for this model.
     * Reads optional {@see $auto_translate_enabled} or settings in group {@code translations}
     * with name {@code auto_translate_{table}}. When no setting exists, auto-translate stays disabled.
     */
    public function autoTranslateEnabledBySettings(): bool
    {
        if (property_exists($this, 'auto_translate_enabled')) {
            return (bool) $this->auto_translate_enabled;
        }

        return app(PerModelSettingResolver::class)->boolean(
            PerModelSettingResolver::nameFor(CoreDatabaseSeeder::AUTO_TRANSLATE_NAME_PREFIX, $this->getTable()),
            default: false,
        );
    }

    /**
     * Get the translation model class name.
     *
     * @return class-string<TTranslationModel>
     */
    protected static function getTranslationModelClass(): string
    {
        // Get the base class (Content, Category, Tag) even if called from child class (Article, etc.)
        $current_class = static::class;

        // If this is a child class (e.g., Article extends Content), use the parent class
        $parent_class = get_parent_class($current_class);

        if ($parent_class && ! is_a($parent_class, Model::class, true)) {
            $current_class = $parent_class;
        }

        $class_name = str_replace('\\Models\\', '\\Models\\Translations\\', $current_class) . 'Translation';

        throw_unless(class_exists($class_name), LogicException::class, 'Translation model class not found: ' . $class_name);

        throw_unless(is_subclass_of($class_name, ITranslated::class), LogicException::class, 'Translation model class does not implement ITranslated: ' . $class_name);

        return $class_name;
    }

    /**
     * Boot the translations trait.
     */
    protected static function bootHasTranslations(): void
    {
        static::addGlobalScope(new LocaleScope());

        // Handle saved event to save translations (after model has ID)
        static::saved(function (Model $model): void {
            /** @var self $model */
            $model->savePendingTranslations();
        });

        static::created(function (Model $model): void {
            // Check if default translation exists
            $default_locale = LocaleContext::getDefault();
            $default_locale = is_string($default_locale) ? $default_locale : null;

            /** @var self $model */
            if (! $model->hasTranslation($default_locale)) {
                return;
            }

            // Emit event instead of dispatching job directly
            // AI listener will handle the translation job
            event(new TranslatedModelSaved($model));
        });

        static::updated(function (Model $model): void {
            // Only translate if default translation was modified
            $default_locale = LocaleContext::getDefault();

            /** @var self $model */
            $default_translation = $model->getTranslation(is_string($default_locale) ? $default_locale : null);

            if (! $default_translation || ! $default_translation->wasChanged()) {
                return;
            }

            // Emit event instead of dispatching job directly
            // AI listener will handle the translation job
            event(new TranslatedModelSaved($model, [], true));
        });
    }

    /**
     * Eloquent accessor for locale attribute.
     * This makes locale available in toArray() and JSON serialization.
     *
     * @return Attribute<string, never>
     */
    protected function locale(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getCurrentLocale(),
        );
    }

    /**
     * Save pending translations.
     */
    protected function savePendingTranslations(): void
    {
        if (empty($this->pending_translations)) {
            return;
        }

        foreach ($this->pending_translations as $locale => $fields) {
            $this->writeTranslation($locale, $this->ensureTranslationFieldDefaults($fields));
        }

        // Clear pending translations
        $this->pending_translations = [];
        $this->current_setter_locale = null;

        // Reload translation relation
        $this->load('translation');
    }

    /**
     * Scope to filter by specific locale (removes default locale scope).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forLocale(Builder $query, ?string $locale = null, ?bool $with_fallback = null): Builder
    {
        $locale ??= LocaleContext::get();
        $default_locale = LocaleContext::getDefault();
        $fallback_enabled = $with_fallback ?? $this->isFallbackEnabled();

        // Rimuovi il global scope di default
        $query->withoutGlobalScope(LocaleScope::class);

        // Filtra per lingua specifica
        if ($fallback_enabled && $locale !== $default_locale) {
            // Mostra contenuti con traduzione richiesta O di default
            $query->whereHas('translations', function (Builder $q) use ($locale, $default_locale): void {
                $q->where('locale', $locale)
                    ->orWhere('locale', $default_locale);
            });
        } else {
            // Mostra SOLO contenuti con traduzione richiesta
            $query->whereHas('translations', function (Builder $q) use ($locale): void {
                $q->where('locale', $locale);
            });
        }

        // Carica traduzione
        $query->with(['translation' => function ($q) use ($locale, $default_locale, $fallback_enabled): void {
            if ($fallback_enabled) {
                $q->where(function ($sub_q) use ($locale, $default_locale): void {
                    $sub_q->where('locale', $locale)
                        ->orWhere('locale', $default_locale);
                })
                    ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale]);
            } else {
                $q->where('locale', $locale);
            }
        }]);

        return $query;
    }

    /**
     * Scope to include translation without filtering.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function withTranslation(Builder $query, ?string $locale = null, ?bool $with_fallback = null): Builder
    {
        $locale ??= LocaleContext::get();
        $default_locale = LocaleContext::getDefault();
        $fallback_enabled = $with_fallback ?? $this->isFallbackEnabled();

        $query->with(['translation' => function ($q) use ($locale, $default_locale, $fallback_enabled): void {
            if ($fallback_enabled) {
                $q->where(function ($sub_q) use ($locale, $default_locale): void {
                    $sub_q->where('locale', $locale)
                        ->orWhere('locale', $default_locale);
                })
                    ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [$locale]);
            } else {
                $q->where('locale', $locale);
            }
        }]);

        return $query;
    }

    /**
     * Get default translation.
     *
     * @return TTranslationModel|null
     */
    protected function getDefaultTranslation(): ?Model
    {
        return $this->translations()
            ->where('locale', LocaleContext::getDefault())
            ->first();
    }

    /**
     * Get value for a translatable field.
     *
     * Checks in order:
     * 1. Pending translations for current locale
     * 2. Pending translations for default locale (if fallback enabled)
     * 3. Saved translation relation
     * 4. Already-loaded translations collection (avoids extra query)
     * 5. Default translation query (only when collection is not loaded)
     *
     * @param  string  $key  The field name
     * @return mixed The field value or null
     */
    protected function getTranslatableFieldValue(string $key): mixed
    {
        $current_locale = LocaleContext::get();

        $default_locale = LocaleContext::getDefaultCached();
        $fallback_enabled = $this->isFallbackEnabled();

        // First, check pending translations (values set but not yet saved)
        if (isset($this->pending_translations[$current_locale][$key])) {
            return $this->pending_translations[$current_locale][$key];
        }

        // If fallback enabled, check pending translation for default locale
        if ($fallback_enabled && isset($this->pending_translations[$default_locale][$key])) {
            return $this->pending_translations[$default_locale][$key];
        }

        // Then check saved translation relation (only if already loaded — avoid triggering a lazy load)
        $translation = $this->relationLoaded('translation') ? $this->getRelationValue('translation') : null;

        if ($translation && isset($translation->{$key})) {
            return $translation->{$key};
        }

        // Not loaded: the record's own translation in this locale comes before any fallback, and is kept for
        // the next reads.
        if (! $this->relationLoaded('translation') && $this->exists) {
            $translation = $this->translationInLocale($current_locale);

            if ($translation instanceof Model) {
                $this->setRelation('translation', $translation);

                if (isset($translation->{$key})) {
                    return $translation->{$key};
                }
            }
        }

        // Fallback to default translation if enabled
        if ($fallback_enabled) {
            // Check the already-loaded translations collection before issuing a new query
            $loaded_translations = $this->relationLoaded('translations') ? $this->getRelationValue('translations') : null;

            if ($loaded_translations instanceof Collection) {
                $default_translation = $loaded_translations->firstWhere('locale', $default_locale);

                if ($default_translation && isset($default_translation->{$key})) {
                    return $default_translation->{$key};
                }

                return null;
            }

            // Collection not loaded — fall back to a targeted DB query
            $default_translation = $this->getDefaultTranslation();

            if ($default_translation && isset($default_translation->{$key})) {
                return $default_translation->{$key};
            }
        }

        return null;
    }

    /**
     * Set value for a translatable field.
     *
     * Stores the value in pending_translations to be persisted on save().
     *
     * @param  string  $key  The field name
     * @param  mixed  $value  The value to set
     */
    protected function setTranslatableFieldValue(string $key, $value): void
    {
        $locale = $this->getCurrentLocale();

        // Store in pending translations to be saved on save()
        if (! isset($this->pending_translations[$locale])) {
            $this->pending_translations[$locale] = [];
        }

        $this->pending_translations[$locale][$key] = $value;
    }

    /**
     * Bridge for models (e.g. Content) that use Laravel Attribute accessors for translatable containers.
     */
    protected function translationsGet(string $key): mixed
    {
        return $this->getTranslatableFieldValue($key);
    }

    /**
     * Bridge for models (e.g. Content) that use Laravel Attribute mutators for translatable containers.
     */
    protected function translationsSet(string $key, mixed $value): void
    {
        $this->setTranslatableFieldValue($key, $value);
    }

    /**
     * Updates the record's translation in the locale, or creates it. A translation removed earlier is still in
     * its table and holds the unique (record, locale) key, so it is revived and overwritten, in one write,
     * instead of being inserted again.
     *
     * @param  array<string, mixed>  $fields
     */
    private function writeTranslation(string $locale, array $fields): void
    {
        $query = $this->translations();

        if (method_exists($query->getRelated(), 'trashed')) {
            $query->withTrashed();
        }

        $translation = $query->where('locale', $locale)->first();

        if (! $translation instanceof Model) {
            $translation = $this->translations()->make(array_merge($fields, ['locale' => $locale]));
            $this->giveOwnerTo($translation);
            $translation->save();

            return;
        }

        if (method_exists($translation, 'trashed') && $translation->trashed() && method_exists($translation, 'reviveInMemory')) {
            $translation->reviveInMemory();
        }

        $this->giveOwnerTo($translation);
        $translation->fill($fields)->save();
    }

    /**
     * Hands the translation this instance as its owner, so what the translation records on its owner (its history
     * rows, D28) reaches the record being written, not a copy reloaded from the database nor its abstract base.
     */
    private function giveOwnerTo(Model $translation): void
    {
        if (method_exists($translation, 'recordOnHistoryOf')) {
            $translation->recordOnHistoryOf($this);
        }
    }

    /**
     * The record's translation in the locale, from the loaded translations when there are, else from the database.
     *
     * @return TTranslationModel|null
     */
    private function translationInLocale(string $locale): ?Model
    {
        $loaded_translations = $this->relationLoaded('translations') ? $this->getRelationValue('translations') : null;

        if ($loaded_translations instanceof Collection) {
            return $loaded_translations->firstWhere('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->first();
    }

    private function isFallbackEnabled(): bool
    {
        return $this->translationFallbackEnabledBySettings();
    }

    /**
     * Get the current locale for setter operations.
     * This is a helper method used internally by the trait.
     */
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function ensureTranslationFieldDefaults(array $fields): array
    {
        if (in_array('components', static::getTranslatableFields(), true) && ! array_key_exists('components', $fields)) {
            $fields['components'] = [];
        }

        return $fields;
    }

    private function getCurrentLocale(): string
    {
        return $this->current_setter_locale ?? LocaleContext::get();
    }
}
