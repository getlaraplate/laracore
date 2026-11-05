<?php

declare(strict_types=1);

namespace Modules\Core\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Override;

/**
 * Eloquent cast for persisting {@see FiltersGroup} as JSON on the ACL (and similar) models.
 *
 * What is stored is in the syntax of the query builder: the operators of the filters are the symbols of
 * {@see FilterOperator} (`=`, `!=`, `>`, ...) and the operators of the groups are `and` or `or`, whichever spelling
 * the value was written in ({@see FilterOperator::tryFromRequestValue()}, {@see WhereClause::tryFromRequestValue()}).
 * An operator in no spelling is refused.
 */
final class FiltersGroupCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): ?FiltersGroup
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(sprintf('The stored filters of [%s] are not valid JSON.', $key));
            }

            if ($decoded === null) {
                return null;
            }

            $value = $decoded;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('The stored filters of [%s] must be a JSON object or list.', $key));
        }

        $group = $this->hydrateGroup($value);

        if (! filter_var($attributes['unrestricted'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! $this->holdsCondition($group)) {
            throw new InvalidArgumentException(sprintf('The stored filters of [%s] constrain nothing.', $key));
        }

        return $group;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = $this->hydrateGroup($value);
        }

        if (! $value instanceof FiltersGroup) {
            throw new InvalidArgumentException(sprintf(
                'Attribute [%s] must be an array or an instance of %s.',
                $key,
                FiltersGroup::class,
            ));
        }

        return json_encode($this->dehydrateGroup($value), JSON_THROW_ON_ERROR);
    }

    /**
     * A group, or a single node (a filter, a scope node or a relation node) or a list of nodes standing for a group.
     *
     * @param  array<mixed>  $data
     */
    private function hydrateGroup(array $data): FiltersGroup
    {
        if (array_is_list($data)) {
            $items = [];

            foreach ($data as $item) {
                $items[] = $this->hydrateNode($item);
            }

            return new FiltersGroup(filters: $items);
        }

        $node = $this->hydrateAssociative($data);

        return $node instanceof FiltersGroup ? $node : new FiltersGroup(filters: [$node]);
    }

    /**
     * An item of a filters list: an object that is a filter, a scope node, a group or a relation node.
     */
    private function hydrateNode(mixed $item): Filter|FiltersGroup|RelationFilter|ScopeFilter
    {
        if (! is_array($item) || array_is_list($item)) {
            throw new InvalidArgumentException('Every item of a filters list must be a filter, a scope filter, a group or a relation filter.');
        }

        return $this->hydrateAssociative($item);
    }

    /**
     * A node is exactly one kind, with exactly the keys of that kind: a relation node (`relation`, `filters`,
     * `morph_types`), a scope node (`scope`, `parameters`), a filter (`property`, `operator`, `value`) or a group
     * (`operator`, `filters`). A key outside the kind, or keys of two kinds at once, are refused: whatever a loose
     * reading drops from an AND group widens access.
     *
     * @param  array<mixed>  $data
     */
    private function hydrateAssociative(array $data): Filter|FiltersGroup|RelationFilter|ScopeFilter
    {
        if (array_key_exists('relation', $data)) {
            $this->assertKeys($data, ['relation', 'filters', 'morph_types']);

            return $this->hydrateRelationFilter($data);
        }

        if (array_key_exists('scope', $data)) {
            $this->assertKeys($data, ['scope', 'parameters']);

            return $this->hydrateScopeFilter($data);
        }

        if (array_key_exists('property', $data)) {
            $this->assertKeys($data, ['property', 'operator', 'value']);

            return $this->hydrateFilter($data);
        }

        if (array_key_exists('filters', $data)) {
            $this->assertKeys($data, ['operator', 'filters']);

            return $this->hydrateFiltersGroup($data);
        }

        throw new InvalidArgumentException('Invalid filter node in filters JSON.');
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $allowed
     */
    private function assertKeys(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Unexpected key [%s] in a filters node.', $key));
            }
        }
    }

    /**
     * @param  array<mixed>  $data
     */
    private function hydrateFiltersGroup(array $data): FiltersGroup
    {
        if (! is_array($data['filters'])) {
            throw new InvalidArgumentException('The filters of a group must be a list of conditions.');
        }

        $nested = [];

        foreach ($data['filters'] as $item) {
            $nested[] = $this->hydrateNode($item);
        }

        $operator = WhereClause::tryFromRequestValue($data['operator'] ?? 'and');

        if (! $operator instanceof WhereClause) {
            throw new InvalidArgumentException('Unknown operator of a filters group.');
        }

        return new FiltersGroup(filters: $nested, operator: $operator);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrateRelationFilter(array $data): RelationFilter
    {
        $relation = $data['relation'] ?? null;
        $nested = $data['filters'] ?? null;
        $morph_types = $data['morph_types'] ?? null;

        if (! is_string($relation) || $relation === '') {
            throw new InvalidArgumentException('A relation filter needs the name of the relation.');
        }

        if (! is_array($nested) || $nested === [] || array_is_list($nested)) {
            throw new InvalidArgumentException('The nested filters of a relation filter must be a filters group, not a list of conditions.');
        }

        if ($morph_types !== null && (! is_array($morph_types) || $morph_types === [] || ! array_is_list($morph_types))) {
            throw new InvalidArgumentException('The morph types of a relation filter must be a non-empty list.');
        }

        $types = null;

        foreach ($morph_types ?? [] as $type) {
            if (! is_string($type) || $type === '') {
                throw new InvalidArgumentException('The morph types of a relation filter must be class names.');
            }

            $types[] = $type;
        }

        $group = $this->hydrateGroup($nested);

        if (! $this->holdsCondition($group)) {
            throw new InvalidArgumentException('The nested filters of a relation filter must hold at least one condition.');
        }

        return new RelationFilter(
            relation: $relation,
            filters: $group,
            morph_types: $types,
        );
    }

    /**
     * A scope node: the name of a local scope and its positional parameters, scalars or null. Whether the model
     * declares the scope and the parameters fit it is checked against the model, at save and at use; here only the
     * shape is read, so nothing is left out.
     *
     * @param  array<mixed>  $data
     */
    private function hydrateScopeFilter(array $data): ScopeFilter
    {
        $scope = $data['scope'];
        $parameters = array_key_exists('parameters', $data) ? $data['parameters'] : [];

        if (! is_string($scope) || $scope === '') {
            throw new InvalidArgumentException('A scope filter needs the name of the scope.');
        }

        if (! is_array($parameters) || ! array_is_list($parameters)) {
            throw new InvalidArgumentException(sprintf('The parameters of scope filter "%s" must be a list.', $scope));
        }

        foreach ($parameters as $parameter) {
            if ($parameter !== null && ! is_scalar($parameter)) {
                throw new InvalidArgumentException(sprintf('The parameters of scope filter "%s" must be scalar values or null.', $scope));
            }
        }

        return new ScopeFilter($scope, $parameters);
    }

    /**
     * Whether the group constrains anything: an empty group, or one made of empty groups, matches every row. Any
     * other node, a scope node included, is a condition.
     */
    private function holdsCondition(FiltersGroup $group): bool
    {
        foreach ($group->filters as $node) {
            if (! $node instanceof FiltersGroup || $this->holdsCondition($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrateFilter(array $data): Filter
    {
        $operator_raw = $data['operator'] ?? '=';
        $property = $data['property'];

        if (! is_string($property) || $property === '') {
            throw new InvalidArgumentException('The property of a filter must be a column name.');
        }

        $operator = FilterOperator::tryFromRequestValue($operator_raw);

        if (! $operator instanceof FilterOperator) {
            throw new InvalidArgumentException('Unknown operator of a filter.');
        }

        return new Filter(
            property: $property,
            value: $data['value'] ?? null,
            operator: $operator,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dehydrateGroup(FiltersGroup $group): array
    {
        return $group->toArray();
    }
}
