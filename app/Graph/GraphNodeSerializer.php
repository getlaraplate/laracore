<?php

declare(strict_types=1);

namespace Modules\Core\Graph;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Graph\Contracts\GraphProviderRegistryInterface;
use Modules\Core\Graph\DTOs\GraphNode;
use Modules\Core\Support\ReadableColumnGuard;

final class GraphNodeSerializer
{
    /**
     * @var list<string>
     */
    private const array FALLBACK_SUMMARY_FIELDS = [
        'title',
        'name',
        'label',
        'slug',
        'path',
        'status',
        'type',
        'code',
        'created_at',
        'updated_at',
    ];

    public function __construct(
        private readonly GraphEntityResolver $entities,
        private readonly GraphProviderRegistryInterface $providers,
    ) {}

    public function serialize(Model $model, string $detail): GraphNode
    {
        $module = $this->entities->moduleFor($model);
        $entity = $this->entities->entityFor($model);
        $attributes = $this->attributesFor($model, $module, $entity, $detail);

        return new GraphNode(
            id: $this->entities->nodeId($model),
            module: $module,
            entity: $entity,
            key: $model->getKey(),
            label: $this->labelFor($model),
            attributes: $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(Model $model, string $module, string $entity, string $detail): array
    {
        if ($detail === 'minimal') {
            return $this->onlyExisting($model, ['slug', 'path']);
        }

        if ($detail === 'full') {
            return $this->safeAttributes($model->toArray());
        }

        $provider = $this->providers->providerFor($module, $entity);
        $fields = $provider?->summaryFields($module, $entity) ?: self::FALLBACK_SUMMARY_FIELDS;

        return $this->onlyExisting($model, $fields);
    }

    private function labelFor(Model $model): ?string
    {
        $attributes = $model->getAttributes();

        foreach (['title', 'name', 'label', 'slug', 'code'] as $field) {
            if (! $this->isReturnedToCaller($model, $field)) {
                continue;
            }

            $value = $attributes[$field] ?? null;

            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        $key = $model->getKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function onlyExisting(Model $model, array $fields): array
    {
        $values = [];
        $attributes = $model->getAttributes();

        foreach ($fields as $field) {
            if (! $this->isReturnedToCaller($model, $field)) {
                continue;
            }

            $value = $attributes[$field] ?? null;

            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        return $this->safeAttributes($values);
    }

    /**
     * Whether the model's serialization returns the attribute to the current caller: not hidden by the model, not
     * hidden from the anonymous user, within the media projection. The label and the summary read raw attributes,
     * so they obey the same rule as the serialization they stand beside.
     */
    private function isReturnedToCaller(Model $model, string $field): bool
    {
        if (in_array($field, $model->getHidden(), true)) {
            return false;
        }

        $visible = $model->getVisible();

        if ($visible !== [] && ! in_array($field, $visible, true)) {
            return false;
        }

        return ReadableColumnGuard::isReadable($model, $field);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function safeAttributes(array $attributes): array
    {
        unset($attributes['password'], $attributes['remember_token']);

        return $attributes;
    }
}
