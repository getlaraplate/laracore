<?php

declare(strict_types=1);

namespace Modules\Core\Observers;

use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\Field;
use Modules\Core\Search\Extensions\ExtenderColumnGuard;
use Modules\Core\Services\DynamicContentsService;

final class FieldObserver
{
    /**
     * Attributes that define where a field's data physically lives and how it is
     * stored: once the field is linked to a preset they are frozen, because the
     * presettable snapshots (and the content written under them) rely on them.
     * Changing them would orphan existing data. Other attributes (options,
     * is_active, and pivot-level is_required) remain freely editable.
     *
     * @var list<string>
     */
    private const array LOCKED_WHEN_LINKED = ['type', 'is_translatable'];

    /**
     * Handle the Field "updating" event.
     */
    public function updating(Field $model): void
    {
        $this->guardStructuralImmutability($model);
        $this->guardExtenderColumns($model);

        if (property_exists($model, 'pivot') && $model->pivot && $model->pivot->isDirty()) {
            $model->pivot->save();
        }
    }

    /**
     * Handle the Field "created" event.
     */
    public function created(Field $model): void
    {
        $this->flushDynamicContentsMetadata($model);
    }

    /**
     * Handle the Field "updated" event.
     */
    public function updated(Field $model): void
    {
        $this->flushDynamicContentsMetadata($model);
    }

    /**
     * Handle the Field "deleted" event.
     */
    public function deleted(Field $model): void
    {
        $this->flushDynamicContentsMetadata($model);
    }

    /**
     * Handle the Field "restored" event.
     */
    public function restored(Field $model): void
    {
        $this->flushDynamicContentsMetadata($model);
    }

    /**
     * A field's structure feeds the cached preset and presettable metadata, so any
     * write must invalidate the persistent {@see DynamicContentsService} caches, after the commit of the write.
     */
    private function flushDynamicContentsMetadata(Field $model): void
    {
        DynamicContentsService::getInstance()->clearAllCaches($model);
    }

    /**
     * @throws ValidationException
     */
    private function guardStructuralImmutability(Field $model): void
    {
        $locked_dirty = array_values(array_intersect(self::LOCKED_WHEN_LINKED, array_keys($model->getDirty())));

        if ($locked_dirty === [] || ! $this->isLinkedToPreset($model)) {
            return;
        }

        $messages = [];

        foreach ($locked_dirty as $attribute) {
            $messages[$attribute] = "The field '{$attribute}' cannot be changed once the field is linked to a preset; create a new field instead.";
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * A field renamed while attached to presets may not take the name of an own column of an extender of one of
     * their entities (XS17, {@see ExtenderColumnGuard}).
     *
     * @throws ValidationException
     */
    private function guardExtenderColumns(Field $model): void
    {
        $name = $model->getAttribute('name');

        if (! $model->isDirty('name') || ! is_string($name)) {
            return;
        }

        $preset_ids = $model->getConnection()->table(CoreTables::Fieldables->value)
            ->where('field_id', $model->getKey())
            ->pluck('preset_id');
        $guard = resolve(ExtenderColumnGuard::class);

        foreach ($preset_ids as $preset_id) {
            if (is_int($preset_id) || is_string($preset_id)) {
                $guard->assertNotShadowed($name, $preset_id, $model->getConnection(), 'name');
            }
        }
    }

    private function isLinkedToPreset(Field $model): bool
    {
        return $model->getConnection()->table(CoreTables::Fieldables->value)
            ->where('field_id', $model->getKey())
            ->exists();
    }
}
