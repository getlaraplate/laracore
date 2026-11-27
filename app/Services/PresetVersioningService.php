<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use Modules\Core\Models\Field;
use Modules\Core\Models\Pivot\Presettable;
use Modules\Core\Models\Preset;

/**
 * @phpstan-import-type FieldSnapshot from \Modules\Core\Models\Pivot\Presettable
 */
final class PresetVersioningService
{
    /**
     * Create a new presettable version for the given preset.
     * Soft-deletes the current active version and inserts a new one
     * with a fresh fields snapshot.
     */
    public function createVersion(Preset $preset): Presettable
    {
        $preset_class = $preset::class;
        $module = class_module($preset_class);

        if ($module !== 'App') {
            $presettable_class = str_replace('Core', $module, Presettable::class);
        } else {
            $presettable_class = str_replace('Modules\\Core', $module, Presettable::class);
        }

        /** @var Presettable $presettable_model */
        $presettable_model = new $presettable_class;
        $presettable_model->setConnection($preset->getConnection()->getName());

        return $preset->getConnection()->transaction(function () use ($preset, $presettable_model): Presettable {
            $presettable_model->newQuery()
                ->where('preset_id', $preset->id)
                ->where('entity_id', $preset->entity_id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            /** @var Presettable $presettable */
            $presettable = $presettable_model->newQuery()->forceCreate([
                'preset_id' => $preset->id,
                'entity_id' => $preset->entity_id,
                'fields_snapshot' => $this->captureFieldsSnapshot($preset),
            ]);

            return $presettable->load(['preset', 'entity']);
        });
    }

    /**
     * Capture the current field configuration for the given preset.
     *
     * @return list<FieldSnapshot>
     */
    public function captureFieldsSnapshot(Preset $preset): array
    {
        return array_values($preset->fields()
            ->orderByPivot('order_column', 'asc')
            ->get()
            ->map(static fn (Field $field): array => [
                'field_id' => $field->id,
                'name' => $field->name,
                'type' => $field->type->value,
                'options' => json_decode(json_encode($field->options), true),
                'is_translatable' => (bool) $field->is_translatable,
                'is_slug' => (bool) $field->is_slug,
                'pivot' => [
                    'is_required' => (bool) $field->presetPivot()->is_required,
                    'order_column' => (int) $field->presetPivot()->order_column,
                    'default' => $field->presetPivot()->default,
                ],
            ])
            ->all());
    }
}
