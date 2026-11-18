<?php

declare(strict_types=1);

use App\Casts\EntityType;
use Illuminate\Validation\ValidationException;
use Modules\Core\Casts\FieldType;
use Modules\Core\Models\Field;
use Modules\Core\Models\Pivot\Fieldable;
use Modules\Core\Search\Extensions\ExtensionRegistry;
use Modules\Core\Tests\Stubs\Extensions\ExtensionHostStub;
use Modules\Core\Tests\Stubs\Extensions\FirstExtenderStub;
use Modules\Core\Tests\Support\AppDynamicEntities;

/*
 * XS17 of spec docs/superpowers/specs/2026-11-17-extended-entity-search-design.md: a dynamic field never takes the
 * name of an own column of an extender on a preset of that extender's entity. The extender's merge routes an own
 * column to itself, so the field would be stored on the host and never read back through the extender. Dynamic
 * fields are configured at runtime, so the rule is checked when a name enters such a preset: when a field is attached
 * to it, and when a field already attached is renamed.
 *
 * FirstExtenderStub extends rows of the `app_pages` entity and owns `id`, `host_id`, `kind` and the timestamps.
 */

beforeEach(function (): void {
    $registry = new ExtensionRegistry();
    $registry->register(ExtensionHostStub::class, FirstExtenderStub::ALIAS, FirstExtenderStub::class);
    app()->instance(ExtensionRegistry::class, $registry);
});

function shadowing_attach(Field $field, int|string $preset_id): Fieldable
{
    return Fieldable::query()->create([
        'field_id' => $field->id,
        'preset_id' => $preset_id,
        'order_column' => 1,
        'default' => null,
        'is_required' => false,
    ]);
}

function shadowing_named_field(string $name): Field
{
    return Field::query()->create(['name' => $name, 'type' => FieldType::Text, 'options' => new stdClass()]);
}

it('refuses to attach a field named like an extender column to a preset of its entity', function (): void {
    $preset = AppDynamicEntities::preset(EntityType::Pages);
    $field = shadowing_named_field('kind');

    try {
        shadowing_attach($field, $preset->id);
        $this->fail('The field was attached.');
    } catch (ValidationException $exception) {
        $message = $exception->errors()['field_id'][0] ?? '';

        expect($message)->toContain('"kind"', FirstExtenderStub::ALIAS, 'XS17');
    }

    expect(Fieldable::query()->where('field_id', $field->id)->exists())->toBeFalse();
});

it('attaches a field with any other name to a preset of the extender entity', function (): void {
    $preset = AppDynamicEntities::preset(EntityType::Pages);
    $field = shadowing_named_field('colour_' . uniqid());

    shadowing_attach($field, $preset->id);

    expect(Fieldable::query()->where('field_id', $field->id)->exists())->toBeTrue();
});

it('attaches a field named like an extender column to a preset of another entity', function (): void {
    $preset = AppDynamicEntities::preset(EntityType::Authors);
    $field = shadowing_named_field('kind');

    shadowing_attach($field, $preset->id);

    expect(Fieldable::query()->where('field_id', $field->id)->exists())->toBeTrue();
});

it('refuses to rename a field attached to a preset of the extender entity to an extender column', function (): void {
    $preset = AppDynamicEntities::preset(EntityType::Pages);
    $field = shadowing_named_field('colour_' . uniqid());
    shadowing_attach($field, $preset->id);

    $field->name = 'kind';

    expect(fn () => $field->save())->toThrow(ValidationException::class);
    expect(Field::query()->whereKey($field->id)->value('name'))->not->toBe('kind');
});

it('renames a field attached to no preset of the extender entity to an extender column', function (): void {
    $field = shadowing_named_field('colour_' . uniqid());
    shadowing_attach($field, AppDynamicEntities::preset(EntityType::Authors)->id);

    $field->name = 'kind';
    $field->save();

    expect(Field::query()->whereKey($field->id)->value('name'))->toBe('kind');
});
