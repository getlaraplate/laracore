<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use LogicException;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Seeding\ReconciliationOutcome;
use ReflectionClass;

trait HasSeedersUtils
{
    /**
     * Create a model from attributes.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  array<string,mixed>  $attributes
     * @param  array<string,mixed>  $pivotValues
     * @return TModel
     */
    protected function create(string $class, array $attributes, array $pivotValues = []): Model
    {
        /** @var Model $model */
        $model = new $class();

        // Extract the relations from the attributes array
        $callables = array_filter($attributes, fn ($value, $key): bool => (is_callable($value) || method_exists($class, $key)) && ! in_array($key, [...$model->getFillable(), ...$model->getHidden(), ...$model->getGuarded()], true), ARRAY_FILTER_USE_BOTH);

        // Remove the relations from the attributes array
        $model_attributes = array_diff_key($attributes, $callables);

        foreach ($model_attributes as $key => $value) {
            $model->{$key} = $value;
        }

        if (class_uses_trait($model, HasApprovals::class)) {
            /** @phpstan-ignore method.notFound */
            $model->setForcedApprovalUpdate(true);
        }

        $model->save();

        if ($callables !== []) {
            $reflected_class = new ReflectionClass($class);

            // Gestiamo le relazioni dopo il salvataggio
            foreach ($callables as $method => $value) {
                $value = is_callable($value) ? $value($model) : $value;
                $return_type = $reflected_class->getMethod($method)->getReturnType()?->getName();

                if ($return_type && is_subclass_of($return_type, Relation::class)) {
                    $relation = $model->{$method}();

                    if ($return_type === BelongsToMany::class) {
                        /**
                         * @var BelongsToMany<Model> $relation
                         */
                        $relation_ids = ($value instanceof Collection ? $value : collect(is_array($value) ? $value : [$value]))
                            ->map(fn (mixed $item): int|string => $item instanceof Model ? $item->getKey() : $item)
                            ->values();

                        if (isset($pivotValues[$method])) {
                            $relation->syncWithPivotValues($relation_ids, $pivotValues[$method], false);
                        } else {
                            $relation->syncWithoutDetaching($relation_ids);
                        }
                    } else {
                        /**
                         * @var BelongsTo<Model> $relation
                         * @var Model|int $value
                         */
                        $relation->associate($value);
                    }
                } else {
                    $model->{$method}($value);
                }
            }
        }

        return $model;
    }

    /**
     * Create many models from factories.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  array<int,array<string,mixed>>  $items  Array di array di attributi
     * @return Collection<int,TModel>
     */
    protected function createMany(string $class, array $items): Collection
    {
        if ($items === []) {
            return collect();
        }

        $timestamp = now();
        $models = [];
        $records = [];

        // Crea i model e prepara i dati per insert
        foreach ($items as $attributes) {
            $model = $class::query()->make($attributes);

            if (class_uses_trait($model, HasApprovals::class)) {
                /** @phpstan-ignore method.notFound */
                $model->setForcedApprovalUpdate(true);
            }

            // Aggiungi timestamps se il model li usa
            if ($model->usesTimestamps()) {
                $model->setCreatedAt($timestamp);
                $model->setUpdatedAt($timestamp);
            }

            $records[] = $model->attributesToArray();
            $models[] = $model;
        }

        $connection = $models[0]->getConnection();

        foreach ($models as $model) {
            if ($connection !== $model->getConnection()) {
                throw new LogicException('All models must use the same resolved database connection.');
            }
        }

        if ($models[0]->getIncrementing()) {
            $connection->transaction(function () use ($models, $records): void {
                foreach ($models as $index => $model) {
                    $id = $model->newQuery()->insertGetId($records[$index], $model->getKeyName());

                    $model->setAttribute($model->getKeyName(), $id);
                    $model->exists = true;
                    $model->syncOriginal();
                }
            });
        } else {
            $models[0]->newQuery()->insert($records);

            foreach ($models as $model) {
                $model->exists = true;
                $model->syncOriginal();
            }
        }

        // Force garbage collection after bulk insert to free memory
        unset($records);
        gc_collect_cycles();

        return collect($models);
    }

    /**
     * @param  class-string  $model
     */
    protected function logOperation(string $model): void
    {
        $already_exists = $model::query()->exists();
        $table = new ReflectionClass($model)->newInstanceWithoutConstructor()->getTable();
        $this->command?->line('  ' . ($already_exists ? 'Updating' : 'Creating') . ' default <fg=cyan;options=bold>' . $table . '</>');
    }

    /**
     * Print the line that closes a step opened by {@see logOperation()}: `nothing to update` when the
     * step changed no row, otherwise how many rows it created, realigned and left unchanged. Every
     * seeder reports through here, so a re-seed prints one line per step whatever the step seeds.
     */
    protected function logOutcome(int $created, int $realigned = 0, int $unchanged = 0): void
    {
        if ($created === 0 && $realigned === 0) {
            $this->command?->line('    - nothing to update');

            return;
        }

        $this->command?->line("    - created {$created}, realigned {$realigned}, unchanged {$unchanged}");
    }

    /**
     * {@see logOutcome()} for a reconciled definition: a restored row was realigned to its definition.
     */
    protected function logReconciliation(ReconciliationOutcome $outcome): void
    {
        $this->logOutcome(
            count($outcome->created),
            count($outcome->realigned) + count($outcome->restored),
            $outcome->unchanged,
        );
    }
}
