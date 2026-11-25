<?php

declare(strict_types=1);

use Modules\Core\Casts\FieldType;
use Modules\Core\Models\Field;
use Modules\Core\Models\Pivot\Fieldable;
use Modules\Core\Overrides\Model;
use Modules\Core\Tests\Support\AppDynamicEntities;

it('reads and writes pivot attributes through field accessors', function (): void {
    $preset = AppDynamicEntities::preset();
    $field = Field::query()->create([
        'name' => 'pivot_field_' . uniqid(),
        'type' => FieldType::Text,
        'options' => new stdClass(),
    ]);

    $preset->fields()->attach($field->id, [
        'order_column' => 99,
        'is_required' => true,
        'default' => ['placeholder' => 'x'],
    ]);

    $field_table = (new Field())->getTable();
    $field_with_pivot = $preset->fields()->where($field_table . '.id', $field->id)->firstOrFail();

    expect($field_with_pivot->pivot)->toBeInstanceOf(Fieldable::class)
        ->and($field_with_pivot->pivot->is_required)->toBeTrue();

    $field_model = new class extends Field
    {
        public Fieldable $pivot;
    };
    $field_model->pivot = $field_with_pivot->pivot;

    expect($field_model->getAttribute('field_id'))->toBe($field->id);

    $field_model->setAttribute('order_column', 42);
    expect($field_model->pivot->order_column)->toBe(42);

    expect($field_model->toArray())->toHaveKey('is_required');
});

it('extends validation rules for create and update operations', function (): void {
    $field = Field::query()->create([
        'name' => 'rules_field_' . uniqid(),
        'type' => FieldType::Text,
        'options' => new stdClass(),
    ]);

    $rules = $field->getRules();
    expect($rules['create'])->toHaveKey('name')
        ->and($rules[Model::DEFAULT_RULE])->toHaveKey('type')
        ->and($rules['update'])->toHaveKey('name');
});

it('returns the preset pivot it was loaded through', function (): void {
    $preset = AppDynamicEntities::preset();
    $field = Field::query()->create([
        'name' => 'preset_pivot_field_' . uniqid(),
        'type' => FieldType::Text,
        'options' => new stdClass(),
    ]);
    $preset->fields()->attach($field->id, ['order_column' => 7, 'is_required' => true, 'default' => ['a' => 1]]);

    $loaded = $preset->fields()->where((new Field())->qualifyColumn('id'), $field->id)->firstOrFail();

    expect($loaded->presetPivot())->toBeInstanceOf(Fieldable::class)
        ->and($loaded->presetPivot()->preset_id)->toBe($preset->id)
        ->and($loaded->presetPivot()->is_required)->toBeTrue()
        ->and($loaded->presetPivot()->default)->toBe(['a' => 1]);
});

it('refuses to read a preset pivot it was not loaded with', function (): void {
    $field = Field::query()->create([
        'name' => 'no_pivot_field_' . uniqid(),
        'type' => FieldType::Text,
        'options' => new stdClass(),
    ]);

    expect(fn (): Fieldable => Field::query()->findOrFail($field->id)->presetPivot())
        ->toThrow(LogicException::class, 'was loaded without its preset pivot');
});
