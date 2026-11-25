<?php

declare(strict_types=1);

namespace Modules\Core\Database\Factories\Concerns;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\Core\Casts\FieldType;
use Modules\Core\Models\Concerns\HasSlug;
use Modules\Core\Models\Field;
use Modules\Core\Services\DynamicContentsService;
use RuntimeException;
use stdClass;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasDynamicContentFactory
{
    /**
     * The entity and presettable a new record of the factory's model gets, picked at random among those available.
     *
     * @return array{entity_id: int, presettable_id: int}
     */
    public function dynamicContentDefinition(): array
    {
        $model_name = $this->modelName();

        $entity_type = $model_name::getEntityType();

        $presettables = $model_name::fetchAvailablePresettables($entity_type);

        throw_unless(
            $presettables->isNotEmpty(),
            RuntimeException::class,
            sprintf(
                'No presettables available for model [%s] and entity type [%s]. Ensure CMS/Core seeders created presettables for this type.',
                $model_name,
                $entity_type->toScalar(),
            ),
        );

        $presettable = $presettables->random();

        return [
            'entity_id' => $presettable->entity_id,
            'presettable_id' => $presettable->id,
        ];
    }

    /**
     * Create pivot relations for a content model.
     *
     * @param  Model|Collection<int,Model>  $content
     */
    public function createDynamicContentRelations(Model|Collection $content, ?callable $callback = null): void
    {
        $i = 0;

        try {
            if (! $callback) {
                return;
            }

            if (! $content instanceof Collection) {
                $content = collect([$content]);
            }

            for ($i; $i < $content->count(); $i++) {
                $callback($content[$i]);
            }
        } catch (Exception $e) {
            Log::warning('Failed to attach relations to content: ' . $e->getMessage(), [
                'model_id' => $content instanceof Model ? $content->getKey() : $content->get($i)->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $forcedValues
     */
    private function fillDynamicContents(Model $model, array $forcedValues = []): void
    {
        throw_unless($model->getAttribute('entity_id'), RuntimeException::class, 'No entity specified for model: ' . $model::class);

        $fields = $this->resolvePresetFields($model);

        $components_array = $fields->mapWithKeys(function (Field $field) use ($forcedValues): array {
            $value = $field->presetPivot()->default;

            if ($field->presetPivot()->is_required || fake()->boolean()) {
                if (isset($forcedValues[$field->name])) {
                    $value = $forcedValues[$field->name];
                } else {
                    $value = match ($field->type) {
                        FieldType::Textarea => fake()->paragraphs(fake()->numberBetween(1, 3), true),
                        FieldType::Text => fake()->text(fake()->numberBetween(100, 255)),
                        FieldType::Number => fake()->randomNumber(),
                        FieldType::Email => fake()->unique()->email(),
                        FieldType::Phone => fake()->boolean() ? fake()->unique()->e164PhoneNumber() : null,
                        FieldType::Url => fake()->boolean() ? fake()->unique()->url() : null,
                        FieldType::Datetime => fake()->dateTime()->format('Y-m-d H:i:s'),
                        FieldType::Editor => (object) [
                            'blocks' => array_map(static fn (string $paragraph) => (object) [
                                'type' => 'paragraph',
                                'data' => [
                                    'text' => $paragraph,
                                ],
                            ], fake()->paragraphs(fake()->numberBetween(1, 10))),
                        ],
                        FieldType::Object => new stdClass(),
                        default => $value,
                    };
                }
            }

            return [$field->name => $value];
        })->toArray();

        // Separate fields by translatability using live preset data (not snapshot-dependent).
        // Non-translatable fields are written directly to the shared_components JSON attribute,
        // bypassing setAttribute → isDynamicField → getFieldsFromSnapshot() which would fail
        // when fields_snapshot is not yet populated, causing raw attributes to land in the INSERT.
        $shared = json_decode((string) ($model->attributes['shared_components'] ?? '{}'), true);
        $shared = is_array($shared) ? $shared : [];
        $translatable = [];

        foreach ($fields as $field) {
            $value = $components_array[$field->name] ?? $field->presetPivot()->default;

            if ($field->is_translatable ?? false) {
                $translatable[$field->name] = $value;
            } else {
                $shared[$field->name] = $value;
            }
        }

        if ($translatable !== []) {
            $model->setAttribute('components', $translatable);
        }

        // setAttribute routes 'shared_components' to parent::setAttribute (not translatable,
        // not a dynamic field) which applies the json cast and stores in $this->attributes safely.
        $model->setAttribute('shared_components', $shared);

        if (
            class_uses_trait($model, HasSlug::class)
            && $model->getRawOriginal('slug') === null
            && method_exists($model, 'generateSlug')
        ) {
            $model->setAttribute('slug', $model->generateSlug());
        }
    }

    /**
     * Resolve a model's preset fields (with pivot) from the cached
     * {@see DynamicContentsService} data instead of loading
     * presettable -> preset -> fields from the database for every record. The
     * fields are static per presettable, so this collapses ~15 queries per
     * record into a single warm-up load per entity type.
     *
     * @return Collection<int, Field>
     */
    private function resolvePresetFields(Model $model): Collection
    {
        $type = $model::getEntityType();
        $service = DynamicContentsService::getInstance();

        $presettable_id = $model->getAttribute('presettable_id');
        $presettable = $service->fetchAvailablePresettables($type)->firstWhere('id', $presettable_id);

        throw_unless(
            $presettable,
            RuntimeException::class,
            sprintf('No cached presettable [%s] for model [%s].', is_scalar($presettable_id) ? $presettable_id : '?', $model::class),
        );

        $preset = $service->fetchAvailablePresets($type)->firstWhere('id', $presettable->preset_id);

        throw_unless(
            $preset,
            RuntimeException::class,
            sprintf('No cached preset [%s] for model [%s].', $presettable->preset_id, $model::class),
        );

        return $preset->fields;
    }
}
