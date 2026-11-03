<?php

declare(strict_types=1);

namespace Modules\Core\Versioning;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Modules\Core\Versioning\Data\RelationDescriptor;

/**
 * The versioned shape of a root class and its fingerprint (D13, D33, D57): the columns its history records, the
 * columns of its translations that are its state, and the relations it registers with their coverage. Defaults,
 * columns left out of the history and the inside of JSON columns are not part of the shape.
 */
final readonly class EpochFingerprint
{
    /**
     * @return array{columns: list<array{name: string, type: string, nullable: bool}>, translation_columns: list<array{name: string, type: string, nullable: bool}>, relations: list<array{relation: string, ownership: string, coverage: string, pivot_columns: list<string>, keys: array<string, string>}>}
     */
    public function definitionOf(Model $root): array
    {
        return [
            'columns' => $this->columnsOf($root, $this->isVersioned(...)),
            'translation_columns' => $this->translationColumnsOf($root),
            'relations' => $this->relationsOf($root),
        ];
    }

    /**
     * The sha256 of the definition, with its keys and lists in a stable order.
     *
     * @param  array<array-key, mixed>  $definition
     */
    public function fingerprintOf(array $definition): string
    {
        return hash('sha256', json_encode($this->normalized($definition), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  callable(Model, string): bool  $keep
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    private function columnsOf(Model $model, callable $keep): array
    {
        $columns = [];

        foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
            $name = (string) $column['name'];

            if ($keep($model, $name)) {
                $columns[] = ['name' => $name, 'type' => mb_strtolower((string) $column['type_name']), 'nullable' => (bool) $column['nullable']];
            }
        }

        usort($columns, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $columns;
    }

    /**
     * The rule of the history's own images: the declared versionable columns when there are any, every column
     * otherwise, never one the model leaves out.
     */
    private function isVersioned(Model $root, string $column): bool
    {
        $versionable = method_exists($root, 'getVersionable') ? $root->getVersionable() : [];
        $dont_versionable = method_exists($root, 'getDontVersionable') ? $root->getDontVersionable() : [];

        return ($versionable === [] || in_array($column, $versionable, true)) && ! in_array($column, $dont_versionable, true);
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    private function translationColumnsOf(Model $root): array
    {
        if (! method_exists($root, 'translations')) {
            return [];
        }

        $translations = $root->translations();

        if (! $translations instanceof HasMany) {
            return [];
        }

        $parent_key = $translations->getForeignKeyName();

        return $this->columnsOf($translations->getRelated(), static function (Model $translation, string $column) use ($parent_key): bool {
            if (method_exists($translation, 'ownerImageKeys')) {
                return in_array($column, $translation->ownerImageKeys(), true);
            }

            return ! in_array($column, [$translation->getKeyName(), $parent_key, 'locale', 'created_at', 'updated_at'], true);
        });
    }

    /**
     * @return list<array{relation: string, ownership: string, coverage: string, pivot_columns: list<string>, keys: array<string, string>}>
     */
    private function relationsOf(Model $root): array
    {
        if (! method_exists($root, 'versionedRelationDescriptors')) {
            return [];
        }

        $relations = array_map(fn (RelationDescriptor $descriptor): array => $this->relationDefinition($root, $descriptor), $root->versionedRelationDescriptors());

        usort($relations, static fn (array $left, array $right): int => strcmp($left['relation'], $right['relation']));

        return $relations;
    }

    /**
     * @return array{relation: string, ownership: string, coverage: string, pivot_columns: list<string>, keys: array<string, string>}
     */
    private function relationDefinition(Model $root, RelationDescriptor $descriptor): array
    {
        $pivot_columns = $descriptor->pivotColumns;
        sort($pivot_columns);

        return [
            'relation' => $descriptor->relation,
            'ownership' => $descriptor->ownership->value,
            'coverage' => $descriptor->coverage->value,
            'pivot_columns' => $pivot_columns,
            'keys' => $this->keysOf($root->{$descriptor->relation}()),
        ];
    }

    /**
     * Where the links of a relation are stored: a relation moved to another table or other key columns reads its
     * past rows against a different shape.
     *
     * @return array<string, string>
     */
    private function keysOf(mixed $relation): array
    {
        if ($relation instanceof BelongsToMany) {
            $keys = [
                'table' => $relation->getTable(),
                'foreign_pivot_key' => $relation->getForeignPivotKeyName(),
                'related_pivot_key' => $relation->getRelatedPivotKeyName(),
            ];

            if ($relation instanceof MorphToMany) {
                $keys['morph_type'] = $relation->getMorphType();
            }

            return $keys;
        }

        if ($relation instanceof HasOneOrMany) {
            return ['foreign_key' => $relation->getForeignKeyName()];
        }

        return [];
    }

    private function normalized(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map($this->normalized(...), $value);

        if (array_is_list($normalized)) {
            usort($normalized, static fn (mixed $left, mixed $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR),
                json_encode($right, JSON_THROW_ON_ERROR),
            ));

            return $normalized;
        }

        ksort($normalized);

        return $normalized;
    }
}
