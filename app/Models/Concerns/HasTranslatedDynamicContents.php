<?php

declare(strict_types=1);

namespace Modules\Core\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Trait for models that need both dynamic contents AND translations.
 *
 * This trait combines HasDynamicContents and HasTranslations, resolving method conflicts
 * and ensuring that translatable fields (defined in the translation model's $fillable)
 * are stored in the translations table, while dynamic fields (from presets) work transparently.
 *
 * Architecture:
 * - Translatable fields determined dynamically via getTranslatableFields() (from translation model's $fillable)
 * - Dynamic fields determined dynamically via getDynamicFields() (from preset fields)
 * - Components (container for dynamic field values) stored in translations if translatable
 * - Custom accessors/mutators (getXxxAttribute/setXxxAttribute) are fully supported
 *
 * Priority for field access:
 * 1. Standard Eloquent attributes (columns in main table)
 * 2. Translatable fields (from translation model's $fillable)
 * 3. Dynamic fields (from preset configuration)
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasTranslatedDynamicContents
{
    // region Trait Aliases
    use HasDynamicContents {
        HasDynamicContents::getRules as private _internalDynamicContentsGetRules;
        HasDynamicContents::toArray as private _internalDynamicContentsToArray;
        HasDynamicContents::getAttribute as private _internalDynamicContentsGetAttribute;
        HasDynamicContents::setAttribute as private _internalDynamicContentsSetAttribute;
        HasDynamicContents::getComponentsAttribute as private _internalDynamicContentsGetComponentsAttribute;
        HasDynamicContents::setComponentsAttribute as private _internalDynamicContentsSetComponentsAttribute;
        HasDynamicContents::setComponentAttribute as private _internalDynamicContentsSetComponentAttribute;
        HasDynamicContents::initializeHasDynamicContents as private _internalDynamicContentsInitialize;
    }
    use HasTranslations {
        HasTranslations::toArray as private _internalTranslationsToArray;
        HasTranslations::getAttribute as private _internalTranslationsGetAttribute;
        HasTranslations::setAttribute as private _internalTranslationsSetAttribute;
    }
    // endregion Trait Aliases

    /**
     * Override getAttribute to handle both translations and dynamic contents.
     *
     * Priority:
     * 1. Standard Eloquent attributes, accessors, casts, relations
     * 2. Translatable fields → delegate to HasTranslations
     * 3. Dynamic fields → delegate to HasDynamicContents
     * 4. Default Eloquent behavior
     *
     * @param  string  $key
     */
    public function getAttribute($key): mixed
    {
        // First, check for standard Eloquent attributes, accessors, relations
        // These take priority over translatable/dynamic fields
        if (
            array_key_exists($key, $this->attributes)
            || $this->hasGetMutator($key)
            || $this->hasAttributeMutator($key)
            || method_exists($this, $key)
            || $key === 'pivot'
        ) {
            return parent::getAttribute($key);
        }

        // Check if it's a translatable field (stored in translation table)
        if ($this->isTranslatableField($key)) {
            $value = $this->getTranslatableFieldValue($key);

            // Check for custom accessor (e.g., getComponentsAttribute)
            $accessor = 'get' . Str::studly($key) . 'Attribute';

            if (method_exists($this, $accessor)) {
                return $this->{$accessor}($value);
            }

            return $value;
        }

        // Check if it's a dynamic field (from preset, stored in components or shared_components)
        if ($this->isDynamicField($key)) {
            // Check if field is translatable
            if ($this->isFieldTranslatable($key)) {
                // Translatable field: get from translation's components
                return data_get($this->getComponentsAttribute(), $key);
            }

            // Non-translatable field: get from shared_components
            $shared_components = isset($this->attributes['shared_components'])
                ? json_decode((string) $this->attributes['shared_components'], true)
                : [];

            return data_get($shared_components, $key);
        }

        // Default Eloquent behavior (relations, etc.)
        return parent::getAttribute($key);
    }

    /**
     * Override setAttribute to handle both translations and dynamic contents.
     *
     * Priority:
     * 1. Translatable fields → delegate to HasTranslations (with mutator support)
     * 2. Dynamic fields → store in components via translation
     * 3. Default Eloquent behavior
     *
     * @param  string  $key
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        // Check if it's a translatable field
        if ($this->isTranslatableField($key)) {
            // Check for custom mutator (e.g., setComponentsAttribute)
            $mutator = 'set' . Str::studly($key) . 'Attribute';

            if (method_exists($this, $mutator)) {
                $this->{$mutator}($value);

                return $this;
            }

            $this->setTranslatableFieldValue($key, $value);

            return $this;
        }

        // Check if it's a dynamic field
        if ($this->isDynamicField($key)) {
            // Check if field is translatable
            if ($this->isFieldTranslatable($key)) {
                // Translatable field: store in translation's components
                $components = $this->getTranslatableFieldValue('components') ?? [];
                $components[$key] = $value;
                $this->setComponentsAttribute($components);
            } else {
                // Non-translatable field: store in shared_components
                $shared_components = is_string($this->attributes['shared_components'] ?? null)
                    ? json_decode($this->attributes['shared_components'], true)
                    : [];
                $shared_components[$key] = $value;
                $this->attributes['shared_components'] = json_encode($shared_components);
            }

            return $this;
        }

        // Handle presettable_id sync
        $result = parent::setAttribute($key, $value);

        if ($key === 'presettable_id' && $value) {
            $this->entity_id = $this->getRelationValue('presettable')?->entity_id;
        }

        return $result;
    }

    /**
     * Merge toArray output from both traits.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $base = parent::toArray() ?? $this->attributesToArray();

        // Merge dynamic contents (expands components into individual fields)
        $with_dynamic = $this->_internalDynamicContentsToArray($base);

        // Merge translations
        return $this->_internalTranslationsToArray($with_dynamic);
    }

    /**
     * Override initializeHasDynamicContents to prevent translatable fields
     * from being stored in the main model's attributes.
     *
     * Translatable fields (like components) should be stored in the translation table,
     * not in the main model table. This method cleans up after HasDynamicContents
     * initialization which adds components to fillable and attributes.
     */
    public function initializeHasDynamicContents(): void
    {
        // Call base initialization
        $this->_internalDynamicContentsInitialize();

        // Remove translatable fields from fillable and attributes
        // They belong in the translations table
        foreach ($this::getTranslatableFields() as $field) {
            $fillable_key = array_search($field, $this->fillable, true);

            if ($fillable_key !== false) {
                unset($this->fillable[$fillable_key]);
            }

            unset($this->attributes[$field]);
        }

        $this->fillable = array_values($this->fillable);
    }

    /**
     * Safety initialization to ensure translatable fields are not in attributes.
     */
    public function initializeHasTranslatedDynamicContents(): void
    {
        foreach ($this::getTranslatableFields() as $field) {
            unset($this->attributes[$field]);
        }
    }

    /**
     * Expose getRules from HasDynamicContents.
     *
     * @return array<string, string>
     */
    public function getRules(): array
    {
        return $this->_internalDynamicContentsGetRules();
    }

    /**
     * Get components from translations and merge with preset defaults.
     *
     * This is the accessor for $model->components when components is translatable.
     * It fetches the raw value from translations and merges with field defaults.
     * Only includes translatable fields; non-translatable fields are in shared_components.
     *
     * @param  mixed  $value  Raw value (unused, we fetch from translations)
     * @return array<string, mixed>
     */
    protected function getComponentsAttribute($value = null): array
    {
        $raw_components = $this->getTranslatableFieldValue('components') ?? [];

        // Filter to only include translatable fields
        $translatable_components = [];

        foreach ($raw_components as $field_name => $field_value) {
            if ($this->isFieldTranslatable($field_name)) {
                $translatable_components[$field_name] = $field_value;
            }
        }

        return $this->mergeComponentsValues($translatable_components, true);
    }

    /**
     * Store components in translations after merging with preset defaults.
     *
     * This is the mutator for $model->components when components is translatable.
     *
     * @param  array<string, mixed>|string|null  $components
     */
    protected function setComponentsAttribute(array|string|null $components): void
    {
        // Handle JSON string input
        if (is_string($components)) {
            $components = json_decode($components, true) ?? [];
        }

        $merged = $this->mergeComponentsValues($components ?? []);
        $this->setTranslatableFieldValue('components', $merged);
    }

    /**
     * Override isFieldTranslatable to check the frozen fields snapshot first, falling back
     * to a live query only when the field is not found in the snapshot (e.g. snapshot not
     * yet populated). Using the snapshot keeps this consistent with isDynamicField() and
     * avoids an extra DB round-trip per field on every attribute access.
     */
    protected function isFieldTranslatable(string $field): ?bool
    {
        $presettable = $this->getRelationValue('presettable');

        if (! $presettable) {
            return null;
        }

        $field_from_snapshot = $presettable->getFieldsFromSnapshot()->firstWhere('name', $field);

        if ($field_from_snapshot !== null) {
            return (bool) $field_from_snapshot->is_translatable;
        }

        // Fallback to live query when field is absent from the snapshot
        $preset = $presettable->getRelationValue('preset');
        $field_model = $preset?->fields()->where('name', $field)->first();

        return $field_model !== null ? ($field_model->is_translatable ?? false) : null;
    }

    /**
     * Set a single dynamic field value within components or shared_components.
     *
     * Used when setting individual fields like $model->public_email = 'test@example.com'
     *
     * @param  string  $key  Field name
     * @param  mixed  $value  Field value
     */
    protected function setComponentAttribute(string $key, mixed $value): void
    {
        // Check if field is translatable
        if ($this->isFieldTranslatable($key)) {
            // Translatable field: store in translation's components
            $components = $this->getTranslatableFieldValue('components') ?? [];
            $components[$key] = $value;
            $this->setComponentsAttribute($components);
        } else {
            // Non-translatable field: store in shared_components
            $shared_components = isset($this->attributes['shared_components'])
                ? json_decode((string) $this->attributes['shared_components'], true)
                : [];
            $shared_components[$key] = $value;
            $this->attributes['shared_components'] = json_encode($shared_components);
        }
    }
}
