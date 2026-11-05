<?php

declare(strict_types=1);

namespace Modules\Core\Search\Traits;

use Exception;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes as EloquentSoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Modules\Core\Search\Support\ScoutWheres;
use Modules\Core\Overrides\CustomSoftDeletingScope;
use Modules\Core\Search\Exceptions\SearchCollectionResolutionException;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;
use Modules\Core\Search\Schema\SchemaDefinition;
use Modules\Core\SoftDeletes\SoftDeletes as CoreSoftDeletes;
use Override;
use ReflectionMethod;

trait CommonEngineFunctions
{
    public const string INDEXED_AT_FIELD = '_indexed_at';

    /**
     * @param  Model&Searchable  $model
     *
     * @throws \Http\Client\Exception
     */
    abstract public function checkIndex(Model $model): bool;

    public function getName(): string
    {
        return Str::of(static::class)->afterLast('\\')->replace('Engine', '')->toString();
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function isVectorSearch(Builder $builder): bool
    {
        $wheres = ScoutWheres::map($builder);

        return isset($wheres['vector'])
            || isset($wheres['embedding'])
            || isset($wheres[$this->resolveVectorField($builder->model)])
            || method_exists($builder->model, 'getVectorField');
    }

    #[Override]
    public function prepareDataToEmbed(Model $model): ?string
    {
        if (! method_exists($model, 'prepareDataToEmbed')) {
            return null;
        }

        return $model->prepareDataToEmbed();
    }

    /**
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    #[Override]
    public function ensureIndex(string|Model $model): bool
    {
        if (! $this->checkIndex($model)) {
            try {
                /** @var Model&Searchable $model */
                $this->createIndex($model);

                return true;
            } catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
                // If index already exists (race condition in parallel processing), ignore the error
                if ($e->getCode() === 400 && str_contains($e->getMessage(), 'resource_already_exists_exception')) {
                    return false;
                }

                throw $e;
            }
        }

        return false;
    }

    /**
     * @phpstan-assert Model&Searchable $model
     */
    #[Override]
    public function ensureSearchable(Model $model): void
    {
        throw_unless($this->usesSearchableTrait($model), InvalidArgumentException::class, 'Model ' . $model::class . ' does not implement the Searchable trait');
    }

    /**
     * @throws \Http\Client\Exception
     */
    #[Override]
    public function getLastIndexedTimestamp(Model $model): ?string
    {
        $this->ensureIndex($model);

        try {
            /** @var Model&Searchable $model */
            return $model::search('*')
                ->where('entity', $model->getTable())
                ->orderBy(self::INDEXED_AT_FIELD, 'desc')
                ->take(1)
                ->get()
                ->first()
                ?->{self::INDEXED_AT_FIELD};
        } catch (Exception $exception) {
            Log::error('Error getting last indexed timestamp from Search Engine', [
                'index' => $model->searchableAs(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if model uses the Searchable trait.
     */
    protected function usesSearchableTrait(Model $model): bool
    {
        return in_array(Searchable::class, class_uses_recursive($model), true);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return EloquentBuilder<Model>
     */
    protected function newQueryIncludingTrashed(string $modelClass): EloquentBuilder
    {
        $query = $modelClass::query();
        $traits = class_uses_recursive($modelClass);

        if (in_array(CoreSoftDeletes::class, $traits, true)) {
            $query->withoutGlobalScope(CustomSoftDeletingScope::class);

            return $query;
        }

        if (in_array(EloquentSoftDeletes::class, $traits, true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    /**
     * @return non-empty-string|null
     */
    protected function resolveSearchableCollectionName(Model $model): ?string
    {
        $searchable_as = [$model, 'searchableAs'];

        if (! is_callable($searchable_as)) {
            return null;
        }

        $collection = $searchable_as();

        return is_string($collection) && $collection !== '' ? $collection : null;
    }

    /**
     * @param  string|Model&Searchable|class-string<Model&Searchable>  $name
     * @return array{model: Model&Searchable, collection: string}|null
     */
    protected function matchModelToCollectionName(string|Model $name): ?array
    {
        $model = null;
        $collection = null;

        if ($name instanceof Model) {
            $model = $name;
            $collection = $model->searchableAs();
        } elseif (is_string($name) && class_exists($name)) {
            $model = new $name();
            $collection = $model->searchableAs();
        } elseif (is_string($name)) {
            $models = models(true, filter: fn (string $model): bool => class_uses_trait($model, Searchable::class) && new $model()->searchableAs() === $name);

            throw_if(count($models) > 1, SearchCollectionResolutionException::class, 'Multiple models found for collection name: ' . $name);

            $resolved = head($models);
            $model = is_string($resolved) ? new $resolved() : ($resolved instanceof Model ? $resolved : null);
            $collection = $name;
        }

        throw_if($collection === null, SearchCollectionResolutionException::class, 'Unable to resolve collection name for index creation.');

        // Get mapping from the model if available; otherwise fail
        if ($model === null) {
            return null;
        }

        return [
            'model' => $model,
            'collection' => $collection,
        ];
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return list<float>
     */
    protected function extractVectorFromBuilder(Builder $builder): array
    {
        $vector_field = $this->resolveVectorField($builder->model);
        $wheres = ScoutWheres::map($builder);
        $vector = $wheres['vector'] ?? $wheres[$vector_field] ?? $wheres['embedding'] ?? [];

        if (! is_array($vector)) {
            return [];
        }

        $normalized = [];

        foreach ($vector as $value) {
            if (is_int($value) || is_float($value)) {
                $normalized[] = (float) $value;
            }
        }

        return $normalized;
    }

    protected function resolveVectorField(mixed $model): string
    {
        if ($model instanceof Model && method_exists($model, 'getVectorField')) {
            $field = $model->getVectorField();

            if (is_string($field) && $field !== '') {
                return $field;
            }
        }

        if ($model instanceof Model && method_exists($model, 'getSchemaDefinition') && (new ReflectionMethod($model, 'getSchemaDefinition'))->isPublic()) {
            $schema = $model->getSchemaDefinition();

            if ($schema instanceof SchemaDefinition) {
                foreach ($schema->getFields() as $field) {
                    if (! $field instanceof FieldDefinition) {
                        continue;
                    }

                    if ($field->type === FieldType::Vector || ($field->type !== FieldType::Array && $field->hasIndexType(IndexType::Vector))) {
                        return $field->name;
                    }

                    if ($field->type === FieldType::Array && is_array($field->options['vector'] ?? null)) {
                        return $field->name . '.vector';
                    }
                }
            }
        }

        if ($model instanceof Model && method_exists($model, 'getSearchMapping')) {
            $mapping = $model->getSearchMapping();
            $field = $this->resolveVectorFieldFromMapping(is_array($mapping) ? $mapping : []);

            if ($field !== null) {
                return $field;
            }
        }

        return 'embedding';
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function resolveVectorFieldFromMapping(array $mapping): ?string
    {
        $properties = data_get($mapping, 'mappings.properties');

        if (is_array($properties)) {
            foreach ($properties as $name => $definition) {
                if (! is_string($name) || ! is_array($definition)) {
                    continue;
                }

                if (($definition['type'] ?? null) === 'dense_vector') {
                    return $name;
                }

                if (($definition['type'] ?? null) === 'nested' && data_get($definition, 'properties.vector.type') === 'dense_vector') {
                    return $name . '.vector';
                }
            }
        }

        $fields = $mapping['fields'] ?? null;

        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                    continue;
                }

                $type = $field['type'] ?? null;

                if ($type === 'float[]' || $type === 'vector' || array_key_exists('num_dim', $field)) {
                    return $field['name'];
                }
            }
        }

        return null;
    }
}
