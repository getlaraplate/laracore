<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Field;
use Modules\Core\Models\Pivot\Fieldable;
use Modules\Core\Models\Preset;
use Modules\Core\Search\Extensions\ExtenderColumnGuard;
use Modules\Core\Services\PresetVersioningService;
use ReflectionClass;

final readonly class FieldableObserver
{
    public function __construct(
        private PresetVersioningService $versioning,
        private ExtenderColumnGuard $extender_columns,
    ) {}

    /**
     * A field entering a preset may not take the name of an own column of an extender of the preset's entity
     * (XS17, {@see ExtenderColumnGuard}).
     *
     * @throws ValidationException
     */
    public function saving(Fieldable $fieldable): void
    {
        if (! $fieldable->isDirty(['field_id', 'preset_id']) || $fieldable->field_id === null || $fieldable->preset_id === null) {
            return;
        }

        $field_name = Field::query()->whereKey($fieldable->field_id)->value('name');

        if (is_string($field_name)) {
            $this->extender_columns->assertNotShadowed($field_name, $fieldable->preset_id, $fieldable->getConnection(), 'field_id');
        }
    }

    public function saved(Fieldable $fieldable): void
    {
        $this->createVersionForPreset($fieldable);
    }

    public function deleted(Fieldable $fieldable): void
    {
        $this->createVersionForPreset($fieldable);
    }

    private function createVersionForPreset(Fieldable $fieldable): void
    {
        $preset = $this->resolvePreset($fieldable);

        if (! $preset instanceof Preset) {
            return;
        }

        $this->versioning->createVersion($preset);
    }

    private function resolvePreset(Fieldable $fieldable): ?Preset
    {
        if ($fieldable->preset_id === null) {
            return null;
        }

        foreach (models(filter: $this->isConcretePresetClass(...)) as $preset_class) {
            $preset = $preset_class::query()->find($fieldable->preset_id);

            if ($preset instanceof Preset) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $model
     */
    private function isConcretePresetClass(string $model): bool
    {
        if (! is_subclass_of($model, Preset::class)) {
            return false;
        }

        return ! (new ReflectionClass($model))->isAbstract();
    }
}
